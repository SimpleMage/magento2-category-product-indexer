<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Indexer\Category\Product\AbstractAction;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\Store;
use Zend_Db_Expr;

/**
 * Snapshot-aware Select BUILDERS (not overrides) for the category_product indexer rewrite.
 *
 * Provides three private helpers that construct snapshot-aware Select objects. Each
 * consumer (FullAction, RowsAction, ProductCategoryRowsAction) chooses how to use them.
 *
 * Design rationale: trait methods defined as overriding `protected` would WIN over core
 * Rows's limitation-adding overrides, breaking partial reindex correctness. Keeping
 * helpers private means each host class composes them deliberately.
 *
 * Required host class instance properties:
 *   protected $connection         — \Magento\Framework\DB\Adapter\AdapterInterface
 *   protected $metadataPool       — MetadataPool
 *   private   SnapshotBuilder $snapshotBuilder
 *   private   Visibility      $productVisibility
 *   private   CoreBehavior    $coreBehavior
 *
 * Required host class methods (from AbstractAction hierarchy):
 *   protected function getTable($table)
 *   protected function getPathFromCategoryId($id)
 *   protected function makeTempCategoryTreeIndex()
 *
 * @property \Magento\Framework\DB\Adapter\AdapterInterface $connection
 * @property MetadataPool $metadataPool
 * @property SnapshotBuilder $snapshotBuilder
 * @property Visibility $productVisibility
 * @property CoreBehavior $coreBehavior
 */
trait SnapshotAwareSelectsTrait
{
    private function buildSnapshotAllProductsSelect(Store $store): Select
    {
        $snapshotTable = $this->snapshotBuilder->getProductSnapshotTable();
        $storeId = (int) $store->getId();

        $select = $this->connection->select()->from(
            ['cp' => $this->getTable('catalog_product_entity')],
            []
        )->joinInner(
            ['cpw' => $this->getTable('catalog_product_website')],
            'cpw.product_id = cp.entity_id',
            []
        )->joinInner(
            ['ps' => $snapshotTable],
            // Snapshot tables are keyed by stable entity_id, NOT the metadata
            // link field — on Adobe Commerce cp.row_id diverges from entity_id.
            sprintf('ps.entity_id = cp.entity_id AND ps.store_id = %d', $storeId),
            []
        )->joinLeft(
            ['ccp' => $this->getTable('catalog_category_product')],
            'ccp.product_id = cp.entity_id',
            []
        )->where(
            'cpw.website_id = ?',
            $store->getWebsiteId()
        )->where(
            'ps.status = ?',
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        )->where(
            'ps.visibility IN (?)',
            $this->productVisibility->getVisibleInSiteIds()
        )->group(
            'cp.entity_id'
        )->columns(
            [
                'category_id' => new Zend_Db_Expr((string) $store->getRootCategoryId()),
                'product_id' => 'cp.entity_id',
                'position' => $this->connection->getCheckSql(
                    'ccp.product_id IS NOT NULL',
                    'MIN(ccp.position)',
                    '10000'
                ),
                'is_parent' => $this->connection->getCheckSql('ccp.product_id IS NOT NULL', '1', '0'),
                'store_id' => new Zend_Db_Expr((string) $storeId),
                'visibility' => 'ps.visibility',
            ]
        );

        return $select;
    }

    private function buildSnapshotNonAnchorSelect(Store $store): Select
    {
        $rootPath = $this->getPathFromCategoryId($store->getRootCategoryId());
        $storeId = (int) $store->getId();

        $productSnapshot = $this->snapshotBuilder->getProductSnapshotTable();
        $categorySnapshot = $this->snapshotBuilder->getCategorySnapshotTable();

        $select = $this->connection->select()->from(
            ['cc' => $this->getTable('catalog_category_entity')],
            []
        )->joinInner(
            ['ccp' => $this->getTable('catalog_category_product')],
            'ccp.category_id = cc.entity_id',
            []
        )->joinInner(
            ['cpw' => $this->getTable('catalog_product_website')],
            'cpw.product_id = ccp.product_id',
            []
        )->joinInner(
            ['ps' => $productSnapshot],
            sprintf('ps.entity_id = ccp.product_id AND ps.store_id = %d', $storeId),
            []
        )->joinInner(
            ['cs' => $categorySnapshot],
            sprintf('cs.entity_id = cc.entity_id AND cs.store_id = %d', $storeId),
            []
        )->where(
            'cc.path LIKE ' . $this->connection->quote($rootPath . '/%')
        )->where(
            'cpw.website_id = ?',
            $store->getWebsiteId()
        )->where(
            'ps.status = ?',
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        )->where(
            'ps.visibility IN (?)',
            $this->productVisibility->getVisibleInSiteIds()
        )->where(
            'ps.is_salable_composite = ?',
            1
        )->columns(
            [
                'category_id' => 'cc.entity_id',
                'product_id' => 'ccp.product_id',
                'position' => 'ccp.position',
                'is_parent' => new Zend_Db_Expr('1'),
                'store_id' => new Zend_Db_Expr((string) $storeId),
                'visibility' => 'ps.visibility',
            ]
        );

        // Up to 2.4.7 core indexes products under direct-assigned categories
        // regardless of the category-active flag — only path membership (under
        // store root) and per-product status/visibility apply. Since 2.4.8 core
        // additionally requires the category to be active (ccacd/ccacs joins
        // with IFNULL(store, default) = 1). Mirror the installed core.
        if ($this->coreBehavior->filtersInactiveCategories()) {
            $select->where('cs.is_active = ?', 1);
        }

        return $select;
    }

    private function buildSnapshotAnchorSelect(Store $store): Select
    {
        $this->setCurrentStoreProperty($store);

        $rootCatIds = array_map('intval', explode('/', $this->getPathFromCategoryId($store->getRootCategoryId())));
        array_pop($rootCatIds);
        $storeId = (int) $store->getId();

        $temporaryTreeTable = $this->makeTempCategoryTreeIndex();

        $productSnapshot = $this->snapshotBuilder->getProductSnapshotTable();
        $categorySnapshot = $this->snapshotBuilder->getCategorySnapshotTable();

        $select = $this->connection->select()->from(
            ['cc' => $this->getTable('catalog_category_entity')],
            []
        )->joinInner(
            ['cc2' => $temporaryTreeTable],
            $this->quoteIntoIntList(
                'cc2.parent_id = cc.entity_id AND cc.entity_id NOT IN (?)',
                $rootCatIds
            ),
            []
        )->joinInner(
            ['ccp' => $this->getTable('catalog_category_product')],
            'ccp.category_id = cc2.child_id',
            []
        )->joinLeft(
            ['ccp2' => $this->getTable('catalog_category_product')],
            'ccp2.category_id = cc2.parent_id AND ccp.product_id = ccp2.product_id',
            []
        )->joinInner(
            ['cpe' => $this->getTable('catalog_product_entity')],
            'ccp.product_id = cpe.entity_id',
            []
        )->joinInner(
            ['cpw' => $this->getTable('catalog_product_website')],
            'cpw.product_id = ccp.product_id',
            []
        )->joinInner(
            ['ps' => $productSnapshot],
            // Snapshot tables are keyed by stable entity_id, NOT the metadata
            // link field — on Adobe Commerce row_id diverges from entity_id.
            sprintf('ps.entity_id = cpe.entity_id AND ps.store_id = %d', $storeId),
            []
        )->joinInner(
            ['cs' => $categorySnapshot],
            sprintf('cs.entity_id = cc.entity_id AND cs.store_id = %d', $storeId),
            []
        )->where(
            'cs.is_anchor = ?',
            1
        )->where(
            'cpw.website_id = ?',
            $store->getWebsiteId()
        )->where(
            'ps.status = ?',
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        )->where(
            'ps.visibility IN (?)',
            $this->productVisibility->getVisibleInSiteIds()
        )->columns(
            [
                'category_id' => 'cc.entity_id',
                'product_id' => 'ccp.product_id',
                'position' => $this->connection->getIfNullSql('ccp2.position', 'MIN(ccp.position) + 10000'),
                'is_parent' => new Zend_Db_Expr('0'),
                'store_id' => new Zend_Db_Expr((string) $storeId),
                'visibility' => 'ps.visibility',
            ]
        );

        // Anchor-category activity: descendants are already filtered by core's
        // temp tree fill (all versions), but the anchor row itself is only
        // excluded when inactive since 2.4.8. Mirror the installed core.
        if ($this->coreBehavior->filtersInactiveCategories()) {
            $select->where('cs.is_active = ?', 1);
        }

        $this->addChildProductsFilterSnapshot($select, $store);

        return $select;
    }

    /**
     * Anchor select using the pre-flattened anchor snapshot table — one row per
     * (anchor_category, product) with aggregated min_position + direct_position,
     * so the per-batch query becomes a PK lookup against a single table + EAV
     * snapshot filters. Drop-in replacement for buildSnapshotAnchorSelect when
     * the anchor snapshot is built.
     */
    private function buildFlatAnchorSelect(Store $store): Select
    {
        $this->setCurrentStoreProperty($store);

        $storeId = (int) $store->getId();

        $productSnapshot = $this->snapshotBuilder->getProductSnapshotTable();
        $categorySnapshot = $this->snapshotBuilder->getCategorySnapshotTable();
        $anchorSnapshot = $this->snapshotBuilder->getAnchorSnapshotTable();

        // Alias = `ccp` intentionally — core's Full::reindexAnchorCategories hardcodes
        // "ccp.product_id IN (?)" as the batch filter. Using `ccp` here means the
        // filter resolves to our snapshot's product_id, no override needed.
        //
        // The anchor snapshot is STORE-AWARE: SnapshotBuilder applies the
        // assignment category's per-store is_active when deriving rows (same
        // semantics as core's per-store temp tree), so this select only filters
        // on its own store_id slice.
        //
        // Cross-store scoping: store roots are typically `is_anchor=1` in EVERY
        // store_id slot of the EAV snapshot, so `cs.is_anchor=1` alone would
        // emit rows for sibling-store trees. Core sidesteps this with a
        // per-store `temp_category_tree_index` restricted to descendants of the
        // current store's root. We replicate that scoping by JOINing back to
        // `catalog_category_entity` and filtering on the anchor's `path`:
        //   1. The store's own root_category_id itself
        //   2. Categories whose `path` contains `/<store_root>/` (descendants)
        // Everything else (admin root id=1, sibling-store roots, unused
        // defaults) is excluded.
        $rootCategoryId = (int) $store->getRootCategoryId();
        $rootDescendantPattern = '%/' . $rootCategoryId . '/%';

        $select = $this->connection->select()->from(
            ['ccp' => $anchorSnapshot],
            []
        )->where(
            'ccp.store_id = ?',
            $storeId
        )->joinInner(
            ['cpw' => $this->getTable('catalog_product_website')],
            'cpw.product_id = ccp.product_id',
            []
        )->joinInner(
            ['ps' => $productSnapshot],
            sprintf('ps.entity_id = ccp.product_id AND ps.store_id = %d', $storeId),
            []
        )->joinInner(
            ['cs' => $categorySnapshot],
            sprintf('cs.entity_id = ccp.anchor_category_id AND cs.store_id = %d', $storeId),
            []
        )->joinInner(
            ['cce_anchor' => $this->getTable('catalog_category_entity')],
            'cce_anchor.entity_id = ccp.anchor_category_id',
            []
        )->where(
            'cs.is_anchor = ?',
            1
        )->where(
            'cpw.website_id = ?',
            $store->getWebsiteId()
        )->where(
            'ps.status = ?',
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        )->where(
            'ps.visibility IN (?)',
            $this->productVisibility->getVisibleInSiteIds()
        )->where(
            'ps.is_salable_composite = ?',
            1
        )->where(
            $this->connection->quoteInto('cce_anchor.entity_id = ?', $rootCategoryId)
            . ' OR '
            . $this->connection->quoteInto('cce_anchor.path LIKE ?', $rootDescendantPattern),
        )->columns(
            [
                'category_id' => 'ccp.anchor_category_id',
                'product_id' => 'ccp.product_id',
                // Preserves core semantic: when product is directly assigned to anchor,
                // use that position; else descendant MIN + 10 000 (core convention).
                'position' => $this->connection->getIfNullSql('ccp.direct_position', 'ccp.min_position + 10000'),
                'is_parent' => new Zend_Db_Expr('0'),
                'store_id' => new Zend_Db_Expr((string) $storeId),
                'visibility' => 'ps.visibility',
            ]
        );

        // Up to 2.4.7 core's createAnchorSelect doesn't filter the anchor on
        // is_active — descendant activity is handled by fillTempCategoryTreeIndex
        // (replicated here by the store-aware anchor snapshot), while the anchor
        // itself may be inactive. Since 2.4.8 core also requires the anchor
        // category to be active. Mirror the installed core.
        if ($this->coreBehavior->filtersInactiveCategories()) {
            $select->where('cs.is_active = ?', 1);
        }

        return $select;
    }

    /**
     * Equivalent of AbstractAction::addFilteringByChildProductsToSelect.
     * Requires the caller's select to have `cpe` alias for catalog_product_entity.
     */
    private function addChildProductsFilterSnapshot(Select $select, Store $store): void
    {
        $productMetadata = $this->metadataPool->getMetadata(ProductInterface::class);
        $linkField = $productMetadata->getLinkField();
        $productSnapshot = $this->snapshotBuilder->getProductSnapshotTable();
        $storeId = (int) $store->getId();

        $select->joinLeft(
            ['relation' => $this->getTable('catalog_product_relation')],
            sprintf('cpe.%s = relation.parent_id', $linkField),
            []
        )->joinLeft(
            ['cps' => $productSnapshot],
            sprintf('cps.entity_id = relation.child_id AND cps.store_id = %d', $storeId),
            []
        )->where(
            'relation.child_id IS NULL OR cps.status = ?',
            \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED
        )->group(
            [
                'cc.entity_id',
                'ccp.product_id',
                'visibility',
            ]
        );
    }

    /**
     * Wrapper for Adapter::quoteInto with INT array list — isolated so we can
     * add a single phpstan-ignore for Magento's imperfect quoteInto docblock
     * (declared as string|null $type but accepts int constants).
     *
     * @param int[] $values
     */
    private function quoteIntoIntList(string $condition, array $values): string
    {
        // @phpstan-ignore-next-line
        return $this->connection->quoteInto($condition, $values, \Zend_Db::INT_TYPE);
    }

    /**
     * Sets AbstractAction's private $currentStore via reflection. Required because
     * parent's setCurrentStore() is private but makeTempCategoryTreeIndex reads
     * the property.
     *
     * Defensive check converts silent corruption (Magento patch renames property
     * → we silently skip the assignment → wrong reindex output) into a loud
     * RuntimeException.
     */
    private function setCurrentStoreProperty(Store $store): void
    {
        static $reflProp = null;
        if ($reflProp === null) {
            if (!property_exists(AbstractAction::class, 'currentStore')) {
                throw new \RuntimeException(
                    'SimpleMage_CategoryProductIndexer: Magento renamed or removed '
                    . AbstractAction::class . '::$currentStore. Reflection workaround broken.'
                );
            }
            $reflProp = new \ReflectionProperty(AbstractAction::class, 'currentStore');
        }
        $reflProp->setValue($this, $store);
    }
}
