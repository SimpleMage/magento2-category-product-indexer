<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct;

use Magento\Catalog\Model\Config;
use Magento\Catalog\Model\Indexer\Category\Product\Action\Full as CoreFull;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Indexer\ActiveTableSwitcher;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Query\Generator as QueryGenerator;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\BatchProviderInterface;
use Magento\Framework\Indexer\BatchSizeManagementInterface;
use Magento\Indexer\Model\ProcessManager;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Full-reindex rewrite of `catalog_category_product` using the snapshot pattern.
 *
 * Builds the snapshot tables upfront via CROSS JOIN stores +
 * COALESCE(store, default), then calls parent::execute() which uses our
 * snapshot-aware select builders.
 */
class FullAction extends CoreFull
{
    use SnapshotAwareSelectsTrait;

    /**
     * True only after SnapshotBuilder::ensureFresh() succeeded for THIS run.
     * Every overridden hook checks this flag and routes to the core EAV-JOIN
     * implementation when false — the genuine fallback path.
     */
    private bool $snapshotReady = false;

    /**
     * Cache `describeTable` results — table schema doesn't change mid-reindex,
     * but `singleShotReindex` is called twice per store (anchor + non-anchor).
     * Each `describeTable` hits INFORMATION_SCHEMA which is non-trivial cost.
     *
     * @var array<string, string[]>
     */
    private array $tableColumnsCache = [];

    public function __construct(
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly Visibility $productVisibility,
        private readonly SnapshotBuilder $snapshotBuilder,
        private readonly CoreBehavior $coreBehavior,
        ?QueryGenerator $queryGenerator = null,
        ?BatchSizeManagementInterface $batchSizeManagement = null,
        ?BatchProviderInterface $batchProvider = null,
        ?MetadataPool $metadataPool = null,
        $batchRowsCount = null,
        ?ActiveTableSwitcher $activeTableSwitcher = null,
        ?ProcessManager $processManager = null,
        ?DeploymentConfig $deploymentConfig = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct(
            $resource,
            $storeManager,
            $config,
            $queryGenerator,
            $batchSizeManagement,
            $batchProvider,
            $metadataPool,
            $batchRowsCount,
            $activeTableSwitcher,
            $processManager,
            $deploymentConfig,
        );
    }

    /**
     * Build snapshot tables once before the per-store fork loop, then delegate
     * to parent::reindex().
     *
     * Fallback: if the snapshot build fails (lock wait timeout, DB error, disk
     * full), $snapshotReady stays false and every overridden hook below routes
     * to core's EAV-JOIN implementation. The user gets a slow run instead of a
     * broken one — and the failure is logged, not swallowed.
     */
    protected function reindex(): void
    {
        try {
            $this->snapshotBuilder->ensureFresh();
            $this->snapshotReady = true;
        } catch (\Throwable $snapshotFailure) {
            $this->snapshotReady = false;
            $this->logger?->error(
                '[SimpleMage] Snapshot build failed — full reindex falls back to the core EAV-JOIN path',
                ['exception' => $snapshotFailure],
            );
        }

        // Select caches may hold stale (snapshot or core) selects from a
        // previous execute() on the same instance — always start clean.
        $this->productsSelects = [];
        $this->nonAnchorSelects = [];
        $this->anchorSelects = [];

        parent::reindex();
    }

    protected function getAllProducts(Store $store)
    {
        if (!$this->snapshotReady) {
            return parent::getAllProducts($store);
        }
        if (!isset($this->productsSelects[$store->getId()])) {
            $this->productsSelects[$store->getId()] = $this->buildSnapshotAllProductsSelect($store);
        }

        return $this->productsSelects[$store->getId()];
    }

    protected function getNonAnchorCategoriesSelect(Store $store)
    {
        if (!$this->snapshotReady) {
            return parent::getNonAnchorCategoriesSelect($store);
        }
        if (!isset($this->nonAnchorSelects[$store->getId()])) {
            $this->nonAnchorSelects[$store->getId()] = $this->buildSnapshotNonAnchorSelect($store);
        }

        return $this->nonAnchorSelects[$store->getId()];
    }

    protected function createAnchorSelect(Store $store)
    {
        if (!$this->snapshotReady) {
            return parent::createAnchorSelect($store);
        }

        // ensureFresh() succeeded, so the flat anchor snapshot is guaranteed
        // to exist and be current — always take the fast path.
        return $this->buildFlatAnchorSelect($store);
    }

    /**
     * Override core's batched anchor reindex path with a single-shot INSERT per store.
     *
     * Core iterates `reindexCategoriesBySelect` in chunks, each one DELETE/SELECT/
     * INSERT cycle with a huge IN-list filter that prevents MySQL from using an
     * index-range plan. With the flat anchor snapshot + precomputed
     * is_salable_composite, the full anchor select is self-contained — one
     * `INSERT INTO _tmp SELECT ... FROM anchor_snapshot` per store replaces the
     * entire loop. InnoDB streams server-side, no PHP memory, no batch round-trips.
     *
     * Layout preserved: writes to _tmp then copies to _replica (same as core's
     * publishData). Final switchTable in AbstractAction::reindex() happens later.
     */
    protected function reindexAnchorCategories(Store $store): void
    {
        if (!$this->snapshotReady) {
            parent::reindexAnchorCategories($store);

            return;
        }
        $this->singleShotReindex($store, $this->getAnchorCategoriesSelect($store));
    }

    protected function reindexNonAnchorCategories(Store $store): void
    {
        if (!$this->snapshotReady) {
            parent::reindexNonAnchorCategories($store);

            return;
        }
        $this->singleShotReindex($store, $this->getNonAnchorCategoriesSelect($store));
    }

    /**
     * Executes `INSERT INTO _tmp SELECT ...` + publish to _replica in one go,
     * replacing core's per-batch iteration.
     */
    private function singleShotReindex(Store $store, Select $select): void
    {
        $storeId = (int) $store->getId();
        $tmpTable = $this->tableMaintainer->getMainTmpTable($storeId);
        $replicaTable = $this->tableMaintainer->getMainReplicaTable($storeId);

        // The _tmp table is TEMPORARY — visible only to the session that created
        // it (TableMaintainer's adapter). Core fixed cross-adapter visibility in
        // 2.4.9 by exposing that adapter; use it when available so every _tmp
        // operation below runs on the creating session. On <= 2.4.8 the shared
        // ResourceConnection instance is the same adapter, matching core behavior.
        $connection = method_exists($this->tableMaintainer, 'getSameAdapterConnection')
            ? $this->tableMaintainer->getSameAdapterConnection()
            : $this->connection;

        // First call per store creates fresh tmp via createTemporaryTableLike (drops + creates).
        // Subsequent calls are NO-OPs (TableMaintainer caches mainTmpTable[$storeId]).
        $this->tableMaintainer->createMainTmpTable($storeId);

        $tmpColumns = $this->getTableColumns($connection, $tmpTable);
        // Load-bearing: singleShotReindex runs twice per store (anchor + non-anchor).
        // The cached createMainTmpTable above is a no-op on the second call, so this DELETE
        // clears the previous call's data before the next INSERT FROM SELECT writes.
        $connection->delete($tmpTable);

        $connection->query(
            $connection->insertFromSelect(
                $select,
                $tmpTable,
                $tmpColumns,
                AdapterInterface::INSERT_ON_DUPLICATE,
            ),
        );

        // Replicate core's publishData semantic: copy _tmp -> _replica.
        $replicaColumns = $this->getTableColumns($connection, $replicaTable);
        $publishSelect = $connection->select()->from($tmpTable);
        $connection->query(
            $connection->insertFromSelect(
                $publishSelect,
                $replicaTable,
                $replicaColumns,
                AdapterInterface::INSERT_ON_DUPLICATE,
            ),
        );
    }

    /**
     * @return string[]
     */
    private function getTableColumns(AdapterInterface $connection, string $tableName): array
    {
        if (!isset($this->tableColumnsCache[$tableName])) {
            $this->tableColumnsCache[$tableName] = array_keys($connection->describeTable($tableName));
        }

        return $this->tableColumnsCache[$tableName];
    }
}
