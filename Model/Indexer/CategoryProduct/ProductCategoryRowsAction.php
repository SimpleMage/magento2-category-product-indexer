<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct;

use Magento\Catalog\Model\Config;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Indexer\Product\Category\Action\Rows as CoreRows;
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
 * Partial-reindex rewrite of `catalog_product_category` (product-trigger path).
 *
 * Hot path in production — MView changelog for product changes fires through
 * this Rows action every time a product's status, visibility, or category
 * assignments change. Core's execute() expands the changelog IDs with composite
 * PARENTS (getProductIdsWithParents), so a child status flip re-derives the
 * parent's snapshot rows — including is_salable_composite — here too.
 *
 * SnapshotBuilder::refreshForProducts() refreshes BOTH the per-product EAV
 * snapshot rows AND the flat anchor snapshot rows for the affected products,
 * so anchor-category membership follows assignment changes immediately.
 */
class ProductCategoryRowsAction extends CoreRows
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
     * Refresh the snapshot before parent's per-store reindex loop.
     *
     * A snapshot refresh failure must NOT break MView changelog processing —
     * letting it propagate would leave the changelog never advancing, causing
     * an infinite retry loop on every cron tick. Instead the failure flips
     * $snapshotReady to false and the run completes correctly on core's
     * EAV-JOIN path.
     */
    protected function reindex(): void
    {
        try {
            // Parent::execute() sets limitationByProducts before calling reindex().
            $this->snapshotBuilder->refreshForProducts($this->limitationByProducts ?? []);
            $this->snapshotReady = true;
        } catch (\Throwable $e) {
            $this->snapshotReady = false;
            $this->logger?->error(
                '[SimpleMage] Snapshot refresh failed during partial reindex — falling back to core path',
                ['exception' => $e, 'product_ids_count' => count($this->limitationByProducts ?? [])],
            );
        }
        parent::reindex();
    }

    /**
     * Uses the flat anchor snapshot — its `ccp` alias matches exactly the filter
     * core Rows appends (`ccp.product_id IN (?)`), and refreshForProducts() has
     * just brought the affected rows up to date.
     */
    protected function createAnchorSelect(Store $store)
    {
        if (!$this->snapshotReady) {
            return parent::createAnchorSelect($store);
        }

        return $this->buildFlatAnchorSelect($store);
    }

    protected function getNonAnchorCategoriesSelect(Store $store)
    {
        if (!$this->snapshotReady) {
            // CoreRows appends the product limitation itself.
            return parent::getNonAnchorCategoriesSelect($store);
        }
        $select = $this->buildSnapshotNonAnchorSelect($store);

        return $select->where(
            'ccp.product_id IN (?)',
            $this->limitationByProducts,
            Zend_Db::INT_TYPE,
        );
    }

    /**
     * Core Product\Category\Rows adds `cp.entity_id IN (?)` limitation here. We
     * MUST replicate it — otherwise root reindex runs for ALL products on every
     * partial invocation, corrupting non-sample rows via INSERT ON DUPLICATE UPDATE.
     */
    protected function getAllProducts(Store $store)
    {
        if (!$this->snapshotReady) {
            // CoreRows appends the product limitation itself.
            return parent::getAllProducts($store);
        }
        $select = $this->buildSnapshotAllProductsSelect($store);

        return $select->where(
            'cp.entity_id IN (?)',
            $this->limitationByProducts,
            Zend_Db::INT_TYPE,
        );
    }
}
