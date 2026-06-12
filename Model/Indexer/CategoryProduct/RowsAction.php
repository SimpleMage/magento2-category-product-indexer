<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct;

use Magento\Catalog\Model\Config;
use Magento\Catalog\Model\Indexer\Category\Product\Action\Rows as CoreRows;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Query\Generator as QueryGenerator;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Indexer\Model\WorkingStateProvider;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zend_Db;

/**
 * Partial-reindex rewrite of `catalog_category_product` (category-trigger path).
 *
 * Fires when CATEGORIES change — category entity (moves change `path` for whole
 * subtrees, and every touched row lands in the changelog), category attributes
 * like is_anchor / is_active.
 *
 * SnapshotBuilder::refreshForCategories() refreshes the per-category EAV
 * snapshot rows, rebuilds the ancestor map, and re-derives flat anchor rows for
 * products assigned to the changed categories — so the snapshot stays in sync
 * with category-side changes without a full reindex.
 */
class RowsAction extends CoreRows
{
    use SnapshotAwareSelectsTrait;

    /**
     * True only after the snapshot refresh succeeded for THIS run. When false,
     * every overridden hook routes to core's EAV-JOIN implementation — partial
     * reindex output stays correct (just slower), and the MView changelog keeps
     * advancing.
     */
    private bool $snapshotReady = false;

    public function __construct(
        ResourceConnection $resource,
        StoreManagerInterface $storeManager,
        Config $config,
        private readonly Visibility $visibility,
        private readonly SnapshotBuilder $snapshotBuilder,
        private readonly CoreBehavior $coreBehavior,
        ?QueryGenerator $queryGenerator = null,
        ?MetadataPool $metadataPool = null,
        ?TableMaintainer $tableMaintainer = null,
        ?CacheContext $cacheContext = null,
        ?EventManagerInterface $eventManager = null,
        ?IndexerRegistry $indexerRegistry = null,
        ?WorkingStateProvider $workingStateProvider = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct(
            $resource,
            $storeManager,
            $config,
            $queryGenerator,
            $metadataPool,
            $tableMaintainer,
            $cacheContext,
            $eventManager,
            $indexerRegistry,
            $workingStateProvider,
        );
    }

    /**
     * Refresh the snapshot before the per-store reindex loop.
     *
     * A snapshot refresh failure must NOT break MView changelog processing —
     * letting it propagate would freeze the changelog and trigger infinite cron
     * retries. Instead the failure flips $snapshotReady to false and the run
     * completes correctly on core's EAV-JOIN path.
     */
    protected function reindex(): void
    {
        try {
            // Parent::execute() sets limitationByCategories before calling reindex().
            $this->snapshotBuilder->refreshForCategories($this->limitationByCategories ?? []);
            $this->snapshotReady = true;
        } catch (\Throwable $e) {
            $this->snapshotReady = false;
            $this->logger?->error(
                '[SimpleMage] Snapshot refresh failed during partial category reindex — falling back to core path',
                ['exception' => $e, 'category_ids_count' => count($this->limitationByCategories ?? [])],
            );
        }
        parent::reindex();
    }

    /**
     * Cannot use buildFlatAnchorSelect here — it has no `cc` alias for
     * `catalog_category_entity` (it joins the flat anchor snapshot under `ccp`),
     * so core Rows's appended `cc.entity_id IN (?)` would fail. Stay on
     * buildSnapshotAnchorSelect which has the `cc` alias core Rows expects.
     */
    protected function createAnchorSelect(Store $store)
    {
        if (!$this->snapshotReady) {
            return parent::createAnchorSelect($store);
        }

        return $this->buildSnapshotAnchorSelect($store);
    }

    protected function getNonAnchorCategoriesSelect(Store $store)
    {
        if (!$this->snapshotReady) {
            // CoreRows appends the category limitation itself.
            return parent::getNonAnchorCategoriesSelect($store);
        }
        $select = $this->buildSnapshotNonAnchorSelect($store);

        return $select->where(
            'cc.entity_id IN (?)',
            $this->limitationByCategories,
            Zend_Db::INT_TYPE,
        );
    }

    /**
     * Root-category reindex (getAllProducts) isn't actually called in Rows paths
     * because CoreRows::isIndexRootCategoryNeeded() returns false. Override for
     * symmetry — if upstream ever flips the flag, we're ready.
     */
    protected function getAllProducts(Store $store)
    {
        if (!$this->snapshotReady) {
            return parent::getAllProducts($store);
        }

        return $this->buildSnapshotAllProductsSelect($store);
    }
}
