<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zend_Db_Expr;

/**
 * Snapshot pattern applied to the `catalog_category_product` rewrite.
 *
 * Core Magento joins `catalog_product_entity_int` FOUR TIMES per INSERT query
 * (status default/store + visibility default/store) and `catalog_category_entity_int`
 * FOUR TIMES (is_active default/store + is_anchor default/store). Each of those
 * joins scans millions of rows on large catalogs and runs per-batch.
 *
 * This class builds permanent (not temporary — must survive across forked
 * ProcessManager workers) snapshot tables. Populated once upfront via
 * CROSS JOIN `store`. All subsequent reindex queries join ONCE to these snapshots
 * instead of eight times to EAV.
 *
 * Canonical table definitions live in etc/db_schema.xml; the runtime DDL in this
 * class MUST stay byte-equivalent (column set, types, index names) — guarded by
 * Test\Unit\DbSchemaSyncTest. Runtime DROP/CREATE is kept (instead of TRUNCATE)
 * so a schema bump self-heals tables created by an older module version.
 */
class SnapshotBuilder
{
    public const PRODUCT_SNAPSHOT_TABLE = 'simplemage_product_eav_snapshot';
    public const CATEGORY_SNAPSHOT_TABLE = 'simplemage_category_eav_snapshot';
    public const ANCHOR_SNAPSHOT_TABLE = 'simplemage_category_product_anchor_snapshot';
    public const ANCESTOR_MAP_TABLE = 'simplemage_category_ancestor_map';

    /**
     * MariaDB/MySQL advisory lock serialising every snapshot write (build and
     * refresh). GET_LOCK is acquired BLOCKING with a timeout so concurrent
     * callers wait for each other instead of racing DROP/CREATE — a timeout
     * throws, and every caller treats that as "snapshot unavailable" and falls
     * back to the core EAV-JOIN path.
     */
    private const BUILD_LOCK_NAME = 'simplemage_catprod_snapshot_build';

    /**
     * Full builds may legitimately take minutes on large catalogs — a second
     * full reindex waits this long before giving up and running on core path.
     */
    private const BUILD_LOCK_WAIT_SECONDS = 1800;

    /**
     * Partial (MView) refreshes must not block changelog processing for long.
     * If a full build is in flight, waiting it out is usually still cheaper
     * than core path; past this limit the Rows actions fall back to core.
     */
    private const REFRESH_LOCK_WAIT_SECONDS = 300;

    /**
     * Range chunk size for INSERT FROM SELECT. Bounded transaction size avoids
     * lock escalation on large catalogs, predictable redo-log pressure,
     * replication-friendly.
     */
    private const PRODUCT_CHUNK_SIZE = 5000;
    private const ANCHOR_CHUNK_SIZE = 5000;

    /**
     * Max product IDs refreshed per anchor-refresh statement (IN-list bound).
     */
    private const REFRESH_CHUNK_SIZE = 5000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CatalogConfig $catalogConfig,
        private readonly LoggerInterface $logger,
        private readonly MetadataPool $metadataPool,
    ) {
    }

    /**
     * EAV link field for products — `entity_id` on Open Source, `row_id` on
     * Adobe Commerce (staging). EAV value tables and
     * catalog_product_relation.parent_id are keyed by THIS field; everything
     * else (catalog_category_product, catalog_product_website,
     * catalog_product_relation.child_id, the snapshot tables) stays in stable
     * entity_id space.
     */
    private function productLinkField(): string
    {
        return $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
    }

    private function categoryLinkField(): string
    {
        return $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
    }

    // ---------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------

    /**
     * Always rebuilds — indexers must be deterministic. A fingerprint-based skip
     * would risk stale output if any EAV change bypassed Magento's resource models
     * (raw SQL imports, programmatic catalog_product_website inserts, etc).
     *
     * @throws LocalizedException when the build lock cannot be acquired in time
     */
    public function ensureFresh(?OutputInterface $output = null): void
    {
        $this->withBuildLock(self::BUILD_LOCK_WAIT_SECONDS, function () use ($output): void {
            $this->buildProductSnapshot($output);
            $this->buildCategorySnapshot($output);
            $this->buildAncestorMap($output);
            $this->buildAnchorSnapshot($output);
        });
    }

    /**
     * Ensures all four snapshot tables exist (and match the current schema),
     * building only the missing ones. Used by partial-reindex paths where a
     * full rebuild is too expensive.
     *
     * @throws LocalizedException when the build lock cannot be acquired in time
     */
    public function ensureBuilt(?OutputInterface $output = null): void
    {
        $this->withBuildLock(self::REFRESH_LOCK_WAIT_SECONDS, function () use ($output): void {
            $this->buildMissingTables($output);
        });
    }

    /**
     * Product-trigger partial refresh (MView changelog of `catalog_product_category`).
     *
     * Refreshes BOTH the per-product EAV snapshot rows (status, visibility,
     * is_salable_composite) AND the flat anchor snapshot rows for the given
     * products — so anchor-category membership follows assignment changes
     * without waiting for the next full reindex.
     *
     * Cost scales with count($productIds) × storeCount, not catalog size.
     *
     * @param int[] $productIds
     * @throws LocalizedException when the build lock cannot be acquired in time
     */
    public function refreshForProducts(array $productIds, ?OutputInterface $output = null): void
    {
        $this->withBuildLock(self::REFRESH_LOCK_WAIT_SECONDS, function () use ($productIds, $output): void {
            $this->buildMissingTables($output);
            if ($productIds === []) {
                return;
            }
            $idList = array_map('intval', $productIds);
            $this->refreshProductRows($idList, $output);
            $this->refreshAnchorRowsForProducts($idList, $output);
        });
    }

    /**
     * Category-trigger partial refresh (MView changelog of `catalog_category_product`).
     *
     * Refreshes the per-category EAV snapshot rows, rebuilds the ancestor map
     * (category moves/creates change `path` for whole subtrees — and the
     * changelog contains every touched row, so the rebuild is always in scope),
     * then re-derives anchor snapshot rows for all products assigned to the
     * changed categories.
     *
     * @param int[] $categoryIds
     * @throws LocalizedException when the build lock cannot be acquired in time
     */
    public function refreshForCategories(array $categoryIds, ?OutputInterface $output = null): void
    {
        $this->withBuildLock(self::REFRESH_LOCK_WAIT_SECONDS, function () use ($categoryIds, $output): void {
            $this->buildMissingTables($output);
            if ($categoryIds === []) {
                return;
            }
            $idList = array_map('intval', $categoryIds);
            $this->refreshCategoryRows($idList, $output);
            $this->buildAncestorMap($output);

            $connection = $this->resourceConnection->getConnection();
            $affectedProducts = array_map('intval', $connection->fetchCol(
                $connection->select()
                    ->distinct()
                    ->from($this->resourceConnection->getTableName('catalog_category_product'), ['product_id'])
                    ->where('category_id IN (?)', $idList, \Zend_Db::INT_TYPE)
            ));
            foreach (array_chunk($affectedProducts, self::REFRESH_CHUNK_SIZE) as $chunk) {
                $this->refreshAnchorRowsForProducts($chunk, $output);
            }
        });
    }

    public function dropAll(): void
    {
        $connection = $this->resourceConnection->getConnection();
        foreach ([
            self::PRODUCT_SNAPSHOT_TABLE,
            self::CATEGORY_SNAPSHOT_TABLE,
            self::ANCHOR_SNAPSHOT_TABLE,
            self::ANCESTOR_MAP_TABLE,
        ] as $t) {
            $connection->query(sprintf(
                'DROP TABLE IF EXISTS %s',
                $connection->quoteIdentifier($this->resourceConnection->getTableName($t))
            ));
        }
    }

    public function getProductSnapshotTable(): string
    {
        return $this->resourceConnection->getTableName(self::PRODUCT_SNAPSHOT_TABLE);
    }

    public function getCategorySnapshotTable(): string
    {
        return $this->resourceConnection->getTableName(self::CATEGORY_SNAPSHOT_TABLE);
    }

    public function getAnchorSnapshotTable(): string
    {
        return $this->resourceConnection->getTableName(self::ANCHOR_SNAPSHOT_TABLE);
    }

    // ---------------------------------------------------------------------
    // Locking
    // ---------------------------------------------------------------------

    /**
     * Blocking GET_LOCK. '1' = acquired, '0' = timed out waiting (throw — the
     * caller falls back to the core path), NULL = DB error (throw).
     *
     * GET_LOCK is re-entrant per connection on MySQL 5.7+/MariaDB 10.0.2+,
     * but this class never nests acquisitions.
     */
    private function withBuildLock(int $waitSeconds, callable $work): void
    {
        $connection = $this->resourceConnection->getConnection();
        $acquired = $connection->fetchOne('SELECT GET_LOCK(?, ?)', [self::BUILD_LOCK_NAME, $waitSeconds]);

        if ($acquired === null) {
            throw new LocalizedException(__(
                'GET_LOCK("%1") returned NULL — DB error during lock acquisition',
                self::BUILD_LOCK_NAME,
            ));
        }

        if ((string) $acquired !== '1') {
            throw new LocalizedException(__(
                'Timed out after %1s waiting for snapshot build lock "%2" — another process is rebuilding',
                $waitSeconds,
                self::BUILD_LOCK_NAME,
            ));
        }

        try {
            $work();
        } finally {
            try {
                $connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::BUILD_LOCK_NAME]);
            } catch (\Throwable $releaseError) {
                // Connection died during work(); MariaDB auto-released the lock
                // on disconnect. Log but don't re-throw — work()'s exception (if
                // any) must propagate.
                $this->logger->warning(
                    '[SimpleMage snapshot] RELEASE_LOCK failed; lock auto-released by MariaDB on disconnect',
                    ['exception' => $releaseError],
                );
            }
        }
    }

    /**
     * Builds whichever snapshot tables are missing, in dependency order
     * (anchor snapshot needs the category snapshot and the ancestor map).
     * Caller must hold the build lock.
     */
    private function buildMissingTables(?OutputInterface $output): void
    {
        $connection = $this->resourceConnection->getConnection();

        if (!$connection->isTableExists($this->getProductSnapshotTable())) {
            $this->buildProductSnapshot($output);
        }
        if (!$connection->isTableExists($this->getCategorySnapshotTable())) {
            $this->buildCategorySnapshot($output);
        }

        $ancestorMapTable = $this->resourceConnection->getTableName(self::ANCESTOR_MAP_TABLE);
        $anchorMissing = !$connection->isTableExists($this->getAnchorSnapshotTable())
            || !$connection->isTableExists($ancestorMapTable)
            // Schema self-heal: pre-store-aware module versions had no store_id
            // column on the anchor snapshot — rebuild instead of mis-querying.
            || !isset($connection->describeTable($this->getAnchorSnapshotTable())['store_id']);

        if ($anchorMissing) {
            $this->buildAncestorMap($output);
            $this->buildAnchorSnapshot($output);
        }
    }

    // ---------------------------------------------------------------------
    // EAV helpers
    // ---------------------------------------------------------------------

    /**
     * Resolve an EAV attribute_id with a clean exception when missing. Without
     * this guard the `(int) $attr->getId()` chain becomes a fatal TypeError on
     * null (broken data patch / corrupt EAV setup).
     */
    private function requireAttributeId(string $entityType, string $code): int
    {
        $attr = $this->catalogConfig->getAttribute($entityType, $code);
        if ($attr === null || !$attr->getId()) {
            throw new LocalizedException(__(
                '[SimpleMage] Required EAV attribute "%1" missing for entity "%2" — broken EAV setup',
                $code,
                $entityType,
            ));
        }

        return (int) $attr->getId();
    }

    /**
     * SQL expression for the precomputed composite-salability flag, relative to
     * aliases `p` (catalog_product_entity) and `s` (store). Stored domain {0, 1}:
     *
     *   - product has no rows in catalog_product_relation (not a composite
     *     parent) → 1 (salable on its own)
     *   - composite parent with at least one child enabled in this store → 1
     *   - composite parent with all children disabled (or with no resolvable
     *     child status rows — matches core's NULL ≠ ENABLED filter) → 0
     *
     * Equivalent to core's addFilteringByChildProductsToSelect
     * (`relation.child_id IS NULL OR IFNULL(child_store, child_default) = 1`
     * + GROUP BY), precomputed once per (product, store).
     *
     * Shared by the full build AND refreshProductRows — keeping the partial
     * refresh from silently degrading to the column default was the single
     * worst correctness bug of the 1.0 line.
     *
     * Built from Select objects (not raw SQL) so Adobe Commerce's staging
     * FromRenderer injects `created_in/updated_in` version filters on the
     * child entity join at assemble() time — a raw string would silently read
     * ALL row versions of children with scheduled updates. `rel.parent_id`
     * and the EAV joins are link-field space (`row_id` on Adobe Commerce);
     * `rel.child_id` is stable entity_id space, so the child's EAV rows are
     * reached through its (version-filtered) entity row `ce`.
     */
    private function isSalableCompositeSql(AdapterInterface $connection, int $statusAttrId): string
    {
        $linkField = $this->productLinkField();
        $relationTable = $this->resourceConnection->getTableName('catalog_product_relation');
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $eavIntTable = $this->resourceConnection->getTableName('catalog_product_entity_int');

        $existsSelect = $connection->select()
            ->from(['rel' => $relationTable], [new Zend_Db_Expr('1')])
            ->where(sprintf('rel.parent_id = p.%s', $linkField));

        // Magento status enum: 1=ENABLED, 2=DISABLED. A composite is salable
        // iff at least one child resolves to enabled: MIN over COALESCE(store,
        // default) = 1. Children without a default-scope status row are
        // excluded (INNER JOIN), and an empty aggregate COALESCEs to 0 — both
        // match core.
        $childStatusSelect = $connection->select()
            ->from(['rel' => $relationTable], [])
            ->joinInner(
                ['ce' => $productTable],
                'ce.entity_id = rel.child_id',
                []
            )
            ->joinInner(
                ['csd' => $eavIntTable],
                sprintf('csd.%1$s = ce.%1$s AND csd.store_id = 0 AND csd.attribute_id = %2$d', $linkField, $statusAttrId),
                []
            )
            ->joinLeft(
                ['css' => $eavIntTable],
                sprintf('css.%1$s = ce.%1$s AND css.store_id = s.store_id AND css.attribute_id = %2$d', $linkField, $statusAttrId),
                []
            )
            ->where(sprintf('rel.parent_id = p.%s', $linkField))
            ->columns(new Zend_Db_Expr('IF(COALESCE(MIN(COALESCE(css.value, csd.value)), 0) = 1, 1, 0)'));

        return sprintf(
            'CASE WHEN NOT EXISTS (%s) THEN 1 ELSE (%s) END',
            $existsSelect->assemble(),
            $childStatusSelect->assemble(),
        );
    }

    // ---------------------------------------------------------------------
    // Product snapshot
    // ---------------------------------------------------------------------

    /**
     * Shared SELECT for the product snapshot rows (full build chunks and the
     * partial refresh differ only in the WHERE filter on p.entity_id).
     *
     * Built as a Select object so Adobe Commerce's staging FromRenderer
     * injects `created_in/updated_in` current-version filters on
     * catalog_product_entity at assemble() time. EAV joins use the metadata
     * link field (`row_id` on Adobe Commerce, `entity_id` on Open Source),
     * mirroring core AbstractAction; the snapshot itself stays keyed by
     * stable entity_id.
     */
    private function productSnapshotSelect(
        AdapterInterface $connection,
        int $statusAttrId,
        int $visibilityAttrId,
        string $productFilterSql,
    ): Select {
        $linkField = $this->productLinkField();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $eavIntTable = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $storeTable = $this->resourceConnection->getTableName('store');

        return $connection->select()
            ->from(['p' => $productTable], [])
            ->joinInner(['s' => $storeTable], 's.store_id > 0', [])
            ->joinInner(
                ['sd' => $eavIntTable],
                sprintf('sd.%1$s = p.%1$s AND sd.store_id = 0 AND sd.attribute_id = %2$d', $linkField, $statusAttrId),
                []
            )
            ->joinLeft(
                ['ss' => $eavIntTable],
                sprintf('ss.%1$s = p.%1$s AND ss.store_id = s.store_id AND ss.attribute_id = %2$d', $linkField, $statusAttrId),
                []
            )
            ->joinInner(
                ['vd' => $eavIntTable],
                sprintf('vd.%1$s = p.%1$s AND vd.store_id = 0 AND vd.attribute_id = %2$d', $linkField, $visibilityAttrId),
                []
            )
            ->joinLeft(
                ['vs' => $eavIntTable],
                sprintf('vs.%1$s = p.%1$s AND vs.store_id = s.store_id AND vs.attribute_id = %2$d', $linkField, $visibilityAttrId),
                []
            )
            ->where($productFilterSql)
            ->columns([
                'entity_id' => 'p.entity_id',
                'store_id' => 's.store_id',
                'status' => new Zend_Db_Expr('COALESCE(ss.value, sd.value)'),
                'visibility' => new Zend_Db_Expr('COALESCE(vs.value, vd.value)'),
                'is_salable_composite' => new Zend_Db_Expr(
                    $this->isSalableCompositeSql($connection, $statusAttrId)
                ),
            ]);
    }

    private function buildProductSnapshot(?OutputInterface $output = null): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->getProductSnapshotTable();

        $statusAttrId = $this->requireAttributeId(Product::ENTITY, 'status');
        $visibilityAttrId = $this->requireAttributeId(Product::ENTITY, 'visibility');

        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');

        $output?->writeln('<info>[snapshot] (re)building product EAV snapshot...</info>');

        $connection->query(sprintf('DROP TABLE IF EXISTS %s', $connection->quoteIdentifier($table)));
        $connection->query(sprintf(
            <<<'SQL'
            CREATE TABLE %s (
                entity_id INT UNSIGNED NOT NULL,
                store_id SMALLINT UNSIGNED NOT NULL,
                status SMALLINT NOT NULL DEFAULT 0,
                visibility SMALLINT NOT NULL DEFAULT 0,
                is_salable_composite TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (entity_id, store_id),
                KEY SIMPLEMAGE_PRODUCT_EAV_SNAPSHOT_STORE_ID_STATUS_VISIBILITY (store_id, status, visibility)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL,
            $connection->quoteIdentifier($table)
        ));

        // Range-chunk by p.entity_id: bounded transaction size avoids lock-escalation
        // risk + predictable redo-log pressure on the new snapshot table.
        $minId = (int) $connection->fetchOne(
            $connection->select()->from($productTable, ['MIN(entity_id)'])
        );
        $maxId = (int) $connection->fetchOne(
            $connection->select()->from($productTable, ['MAX(entity_id)'])
        );

        $rowsTotal = 0;
        $startNs = hrtime(true);

        if ($minId === 0 && $maxId === 0) {
            $output?->writeln('<info>[snapshot] no products — empty product snapshot</info>');
        } else {
            $totalChunks = (int) ceil(($maxId - $minId + 1) / self::PRODUCT_CHUNK_SIZE);
            $chunkIdx = 0;

            for ($from = $minId; $from <= $maxId; $from += self::PRODUCT_CHUNK_SIZE) {
                $to = $from + self::PRODUCT_CHUNK_SIZE - 1;
                $chunkIdx++;

                $select = $this->productSnapshotSelect(
                    $connection,
                    $statusAttrId,
                    $visibilityAttrId,
                    sprintf('p.entity_id BETWEEN %d AND %d', $from, $to),
                );
                $chunkStartNs = hrtime(true);
                $stmt = $connection->query($connection->insertFromSelect(
                    $select,
                    $table,
                    ['entity_id', 'store_id', 'status', 'visibility', 'is_salable_composite'],
                ));
                $rowsThis = $stmt->rowCount();
                $rowsTotal += $rowsThis;
                $chunkMs = (int) round((hrtime(true) - $chunkStartNs) / 1_000_000);

                if ($chunkIdx === 1 || $chunkIdx % 10 === 0 || $chunkIdx === $totalChunks) {
                    $output?->writeln(sprintf(
                        '<comment>  [snapshot] product chunk %d/%d (entity_id %d-%d): %s rows in %d ms</comment>',
                        $chunkIdx,
                        $totalChunks,
                        $from,
                        $to,
                        number_format($rowsThis, 0, '.', ' '),
                        $chunkMs,
                    ));
                }
            }
        }

        $durationMs = (hrtime(true) - $startNs) / 1_000_000;
        $output?->writeln(sprintf(
            '<info>[snapshot] product snapshot built: %s rows in %.1f ms</info>',
            number_format($rowsTotal, 0, '.', ' '),
            $durationMs,
        ));
    }

    /**
     * Refreshes product snapshot rows (status, visibility, is_salable_composite)
     * for a specific set of product IDs. Caller must hold the build lock and is
     * expected to pass IDs already expanded to include composite parents — core's
     * Product\Category\Action\Rows::execute does that via getProductIdsWithParents,
     * so a child status flip re-derives the parent's salability here too.
     *
     * @param int[] $idList already intval-mapped
     */
    private function refreshProductRows(array $idList, ?OutputInterface $output): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->getProductSnapshotTable();

        $statusAttrId = $this->requireAttributeId(Product::ENTITY, 'status');
        $visibilityAttrId = $this->requireAttributeId(Product::ENTITY, 'visibility');

        $startNs = hrtime(true);
        $connection->delete($table, ['entity_id IN (?)' => $idList]);

        $select = $this->productSnapshotSelect(
            $connection,
            $statusAttrId,
            $visibilityAttrId,
            sprintf('p.entity_id IN (%s)', implode(',', $idList)),
        );
        $connection->query($connection->insertFromSelect(
            $select,
            $table,
            ['entity_id', 'store_id', 'status', 'visibility', 'is_salable_composite'],
        ));
        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        $output?->writeln(sprintf(
            '<info>[snapshot] refreshed %d product(s) across all stores in %.1f ms</info>',
            count($idList),
            $durationMs,
        ));
    }

    // ---------------------------------------------------------------------
    // Category snapshot
    // ---------------------------------------------------------------------

    /**
     * Shared SELECT for the category snapshot rows (full build and partial
     * refresh differ only in the optional filter on c.entity_id). Same
     * link-field + staging-aware Select rationale as productSnapshotSelect().
     */
    private function categorySnapshotSelect(
        AdapterInterface $connection,
        int $isActiveAttrId,
        int $isAnchorAttrId,
        ?string $categoryFilterSql = null,
    ): Select {
        $linkField = $this->categoryLinkField();
        $categoryTable = $this->resourceConnection->getTableName('catalog_category_entity');
        $eavIntTable = $this->resourceConnection->getTableName('catalog_category_entity_int');
        $storeTable = $this->resourceConnection->getTableName('store');

        $select = $connection->select()
            ->from(['c' => $categoryTable], [])
            ->joinInner(['s' => $storeTable], 's.store_id > 0', [])
            ->joinInner(
                ['iad' => $eavIntTable],
                sprintf('iad.%1$s = c.%1$s AND iad.store_id = 0 AND iad.attribute_id = %2$d', $linkField, $isActiveAttrId),
                []
            )
            ->joinLeft(
                ['ias' => $eavIntTable],
                sprintf('ias.%1$s = c.%1$s AND ias.store_id = s.store_id AND ias.attribute_id = %2$d', $linkField, $isActiveAttrId),
                []
            )
            ->joinInner(
                ['and_d' => $eavIntTable],
                sprintf('and_d.%1$s = c.%1$s AND and_d.store_id = 0 AND and_d.attribute_id = %2$d', $linkField, $isAnchorAttrId),
                []
            )
            ->joinLeft(
                ['ans' => $eavIntTable],
                sprintf('ans.%1$s = c.%1$s AND ans.store_id = s.store_id AND ans.attribute_id = %2$d', $linkField, $isAnchorAttrId),
                []
            )
            ->columns([
                'entity_id' => 'c.entity_id',
                'store_id' => 's.store_id',
                'is_active' => new Zend_Db_Expr('COALESCE(ias.value, iad.value)'),
                'is_anchor' => new Zend_Db_Expr('COALESCE(ans.value, and_d.value)'),
            ]);

        if ($categoryFilterSql !== null) {
            $select->where($categoryFilterSql);
        }

        return $select;
    }

    private function buildCategorySnapshot(?OutputInterface $output = null): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->getCategorySnapshotTable();

        $isActiveAttrId = $this->requireAttributeId(Category::ENTITY, 'is_active');
        $isAnchorAttrId = $this->requireAttributeId(Category::ENTITY, 'is_anchor');

        $output?->writeln('<info>[snapshot] (re)building category EAV snapshot...</info>');

        $connection->query(sprintf('DROP TABLE IF EXISTS %s', $connection->quoteIdentifier($table)));
        $connection->query(sprintf(
            <<<'SQL'
            CREATE TABLE %s (
                entity_id INT UNSIGNED NOT NULL,
                store_id SMALLINT UNSIGNED NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                is_anchor TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (entity_id, store_id),
                KEY SIMPLEMAGE_CATEGORY_EAV_SNAPSHOT_STORE_ID_IS_ACTIVE_IS_ANCHOR (store_id, is_active, is_anchor)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL,
            $connection->quoteIdentifier($table)
        ));

        $select = $this->categorySnapshotSelect($connection, $isActiveAttrId, $isAnchorAttrId);
        $startNs = hrtime(true);
        $connection->query($connection->insertFromSelect(
            $select,
            $table,
            ['entity_id', 'store_id', 'is_active', 'is_anchor'],
        ));
        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        $rows = (int) $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])
        );
        $output?->writeln(sprintf(
            '<info>[snapshot] category snapshot built: %s rows in %.1f ms</info>',
            number_format($rows, 0, '.', ' '),
            $durationMs,
        ));
    }

    /**
     * Refreshes category snapshot rows for a specific set of category IDs.
     * Caller must hold the build lock.
     *
     * @param int[] $idList already intval-mapped
     */
    private function refreshCategoryRows(array $idList, ?OutputInterface $output): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->getCategorySnapshotTable();

        $isActiveAttrId = $this->requireAttributeId(Category::ENTITY, 'is_active');
        $isAnchorAttrId = $this->requireAttributeId(Category::ENTITY, 'is_anchor');

        $startNs = hrtime(true);
        $connection->delete($table, ['entity_id IN (?)' => $idList]);

        $select = $this->categorySnapshotSelect(
            $connection,
            $isActiveAttrId,
            $isAnchorAttrId,
            sprintf('c.entity_id IN (%s)', implode(',', $idList)),
        );
        $connection->query($connection->insertFromSelect(
            $select,
            $table,
            ['entity_id', 'store_id', 'is_active', 'is_anchor'],
        ));
        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        $output?->writeln(sprintf(
            '<info>[snapshot] refreshed %d category/categories across all stores in %.1f ms</info>',
            count($idList),
            $durationMs,
        ));
    }

    // ---------------------------------------------------------------------
    // Ancestor map + anchor snapshot
    // ---------------------------------------------------------------------

    /**
     * Builds the STRUCTURAL ancestor map: for each category, explode `path`
     * (e.g. "1/2/5/10") into (descendant=10, ancestor=[2,5]) rows. Root (1) and
     * the leaf itself are skipped — only PROPER ancestors (non-self, non-root).
     * Mirrors core's temp_tree_index shape, built from `path`.
     *
     * is_active filtering is deliberately NOT applied here: core's
     * fillTempCategoryTreeIndex filters activity PER STORE
     * (IFNULL(store_value, default_value)), so activity is resolved when the
     * anchor snapshot joins the per-store category EAV snapshot — keeping this
     * map store-independent and the per-store semantics identical to core.
     */
    private function buildAncestorMap(?OutputInterface $output = null): void
    {
        $connection = $this->resourceConnection->getConnection();
        $ancestorMapTable = $this->resourceConnection->getTableName(self::ANCESTOR_MAP_TABLE);
        $categoryTable = $this->resourceConnection->getTableName('catalog_category_entity');

        $connection->query(sprintf('DROP TABLE IF EXISTS %s', $connection->quoteIdentifier($ancestorMapTable)));
        $connection->query(sprintf(
            <<<'SQL'
            CREATE TABLE %s (
                ancestor_id INT UNSIGNED NOT NULL,
                category_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (ancestor_id, category_id),
                KEY SIMPLEMAGE_CATEGORY_ANCESTOR_MAP_CATEGORY_ID (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL,
            $connection->quoteIdentifier($ancestorMapTable),
        ));

        $output?->writeln('<info>  [SnapshotBuilder] building ancestor map from category paths...</info>');
        $startNs = hrtime(true);

        // Walk category paths in PHP — simpler than a recursive CTE and fast enough.
        //
        // Filter rationale: core Magento's catalog_category_product indexer projects
        // products into ancestor categories that are reachable from an active store
        // root. Categories that are level=0 (admin root id=1) or level=1 but NOT
        // any store group's `root_category_id` are never reachable as anchor
        // destinations — projecting into them is a hidden over-include bug visible
        // as MD5 mismatch on multi-website real-world catalogs.
        //
        // We collect the set of active store roots from store_group and treat them
        // as valid anchor destinations even though they're level=1. Everything else
        // at level<2 is skipped both as anchor SOURCE and as anchor DESTINATION.
        $storeGroupTable = $this->resourceConnection->getTableName('store_group');
        $activeStoreRoots = array_map(
            'intval',
            $connection->fetchCol(sprintf(
                'SELECT DISTINCT root_category_id FROM %s WHERE group_id > 0 AND root_category_id > 0',
                $connection->quoteIdentifier($storeGroupTable),
            )),
        );
        $activeStoreRootSet = array_flip($activeStoreRoots);

        // entity_id/path/level exist on both Open Source and Adobe Commerce
        // schemas. On Adobe Commerce the staging FromRenderer injects the
        // current-version filter into this Select at render time, so entities
        // with scheduled updates yield exactly one row.
        $categories = $connection->fetchAll(
            $connection->select()
                ->from($categoryTable, ['entity_id', 'path', 'level'])
                ->where('path IS NOT NULL')
        );
        $categoryLevels = [];
        foreach ($categories as $row) {
            $categoryLevels[(int) $row['entity_id']] = (int) $row['level'];
        }
        $isValidAnchor = function (int $categoryId) use ($categoryLevels, $activeStoreRootSet): bool {
            $level = $categoryLevels[$categoryId] ?? 0;
            if ($level >= 2) {
                return true;
            }
            // level<=1 only valid if it's an active store root
            return isset($activeStoreRootSet[$categoryId]);
        };

        $values = [];
        foreach ($categories as $row) {
            $categoryId = (int) $row['entity_id'];
            // Skip admin root + non-root level=1 categories as ancestor SOURCES
            // (their products are direct, not anchor-inherited).
            if (!$isValidAnchor($categoryId) && (int) $row['level'] < 2) {
                continue;
            }
            $segments = explode('/', (string) $row['path']);
            foreach ($segments as $segment) {
                $ancestorId = (int) $segment;
                if ($ancestorId === 0 || $ancestorId === $categoryId) {
                    continue;
                }
                if (!$isValidAnchor($ancestorId)) {
                    continue;
                }
                $values[] = [$ancestorId, $categoryId];
                if (count($values) >= 5000) {
                    $connection->insertArray($ancestorMapTable, ['ancestor_id', 'category_id'], $values);
                    $values = [];
                }
            }
        }
        if ($values !== []) {
            $connection->insertArray($ancestorMapTable, ['ancestor_id', 'category_id'], $values);
        }

        $mapRows = (int) $connection->fetchOne(
            $connection->select()->from($ancestorMapTable, ['COUNT(*)'])
        );
        $mapDuration = (int) round((hrtime(true) - $startNs) / 1_000_000);
        $output?->writeln(sprintf(
            '<info>  [SnapshotBuilder] ancestor map: %s rows in %d ms</info>',
            number_format($mapRows, 0, '.', ' '),
            $mapDuration,
        ));
    }

    /**
     * Builds the flat, STORE-AWARE pre-joined table of
     * (store × anchor_category × descendant product) with aggregated positions.
     * Replaces core's per-batch `cc × temp_tree × ccp × ccp2` self-join.
     *
     *   store_id           SMALLINT -- store view the pair is valid for
     *   anchor_category_id INT      -- the ANCESTOR category
     *   product_id         INT      -- product assigned to any descendant
     *   min_position       INT      -- MIN(ccp.position) across active descendants
     *   direct_position    INT?     -- ccp.position when directly assigned to anchor
     *   PK (store_id, anchor_category_id, product_id)
     *
     * The per-store dimension exists because core's temp tree filters the
     * ASSIGNMENT category's is_active per store (IFNULL(store, default)) — a
     * category deactivated in one store view must not project its products to
     * ancestors in that store. The category EAV snapshot provides exactly that
     * per-store flag, so the JOIN below reproduces core semantics.
     */
    private function buildAnchorSnapshot(?OutputInterface $output = null): void
    {
        $connection = $this->resourceConnection->getConnection();
        $anchorTable = $this->getAnchorSnapshotTable();
        $ccpTable = $this->resourceConnection->getTableName('catalog_category_product');

        $connection->query(sprintf('DROP TABLE IF EXISTS %s', $connection->quoteIdentifier($anchorTable)));
        $connection->query(sprintf(
            <<<'SQL'
            CREATE TABLE %s (
                store_id SMALLINT UNSIGNED NOT NULL,
                anchor_category_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                min_position INT NOT NULL,
                direct_position INT DEFAULT NULL,
                PRIMARY KEY (store_id, anchor_category_id, product_id),
                KEY SIMPLEMAGE_CATEGORY_PRODUCT_ANCHOR_SNAPSHOT_PRODUCT_ID (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL,
            $connection->quoteIdentifier($anchorTable),
        ));

        $output?->writeln('<info>  [SnapshotBuilder] populating anchor snapshot (store × ancestor × product)...</info>');
        $startNs = hrtime(true);

        // Chunk by ccp.product_id to bound transaction size. product_id is part
        // of the GROUP BY key, so chunks emit disjoint row sets.
        $anchorRange = $connection->fetchRow(
            $connection->select()->from(
                $ccpTable,
                ['min_id' => 'MIN(product_id)', 'max_id' => 'MAX(product_id)']
            ),
        );
        $minProductId = (int) ($anchorRange['min_id'] ?? 0);
        $maxProductId = (int) ($anchorRange['max_id'] ?? 0);

        $rowsTotal = 0;

        if ($minProductId === 0 && $maxProductId === 0) {
            $output?->writeln('<info>  [SnapshotBuilder] no products in catalog_category_product — empty anchor snapshot</info>');
        } else {
            $totalChunks = (int) ceil(($maxProductId - $minProductId + 1) / self::ANCHOR_CHUNK_SIZE);
            $chunkIdx = 0;

            for ($from = $minProductId; $from <= $maxProductId; $from += self::ANCHOR_CHUNK_SIZE) {
                $to = $from + self::ANCHOR_CHUNK_SIZE - 1;
                $chunkIdx++;

                $chunkStartNs = hrtime(true);
                $stmt = $connection->query($this->anchorInsertSql(
                    $connection,
                    sprintf('ccp.product_id BETWEEN %d AND %d', $from, $to),
                ));
                $rowsThis = $stmt->rowCount();
                $rowsTotal += $rowsThis;
                $chunkMs = (int) round((hrtime(true) - $chunkStartNs) / 1_000_000);

                if ($chunkIdx === 1 || $chunkIdx % 10 === 0 || $chunkIdx === $totalChunks) {
                    $output?->writeln(sprintf(
                        '<comment>    [SnapshotBuilder] anchor chunk %d/%d (product_id %d-%d): %s rows in %d ms</comment>',
                        $chunkIdx,
                        $totalChunks,
                        $from,
                        $to,
                        number_format($rowsThis, 0, '.', ' '),
                        $chunkMs,
                    ));
                }
            }
        }

        $buildDuration = (int) round((hrtime(true) - $startNs) / 1_000_000);
        $output?->writeln(sprintf(
            '<info>  [SnapshotBuilder] anchor snapshot: %s rows in %d ms</info>',
            number_format($rowsTotal, 0, '.', ' '),
            $buildDuration,
        ));
    }

    /**
     * Deletes and re-derives flat anchor snapshot rows for the given products
     * from the CURRENT ancestor map + assignments + per-store category activity.
     * Caller must hold the build lock.
     *
     * @param int[] $idList already intval-mapped
     */
    private function refreshAnchorRowsForProducts(array $idList, ?OutputInterface $output): void
    {
        if ($idList === []) {
            return;
        }
        $connection = $this->resourceConnection->getConnection();
        $anchorTable = $this->getAnchorSnapshotTable();

        $startNs = hrtime(true);
        $connection->delete($anchorTable, ['product_id IN (?)' => $idList]);
        $connection->query($this->anchorInsertSql(
            $connection,
            sprintf('ccp.product_id IN (%s)', implode(',', $idList)),
        ));
        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        $output?->writeln(sprintf(
            '<info>[snapshot] refreshed anchor rows for %d product(s) in %.1f ms</info>',
            count($idList),
            $durationMs,
        ));
    }

    /**
     * Shared INSERT…SELECT for the anchor snapshot (full build chunks and
     * partial refresh differ only in the WHERE filter on ccp.product_id).
     *
     * The INNER JOIN on the category EAV snapshot applies the ASSIGNMENT
     * category's per-store is_active — same semantics as core's per-store
     * temp tree. The direct-position LEFT JOIN is structural (store-agnostic).
     */
    private function anchorInsertSql(AdapterInterface $connection, string $productFilterSql): string
    {
        $anchorTable = $this->getAnchorSnapshotTable();
        $ancestorMapTable = $this->resourceConnection->getTableName(self::ANCESTOR_MAP_TABLE);
        $categorySnapshotTable = $this->getCategorySnapshotTable();
        $ccpTable = $this->resourceConnection->getTableName('catalog_category_product');

        return sprintf(
            <<<'SQL'
            INSERT INTO %s (store_id, anchor_category_id, product_id, min_position, direct_position)
            SELECT
                cs.store_id,
                am.ancestor_id AS anchor_category_id,
                ccp.product_id,
                MIN(ccp.position) AS min_position,
                MAX(direct.position) AS direct_position
            FROM %s ccp
            INNER JOIN %s am ON am.category_id = ccp.category_id
            INNER JOIN %s cs ON cs.entity_id = ccp.category_id AND cs.is_active = 1
            LEFT JOIN %s direct
                ON direct.category_id = am.ancestor_id AND direct.product_id = ccp.product_id
            WHERE %s
            GROUP BY cs.store_id, am.ancestor_id, ccp.product_id
            SQL,
            $connection->quoteIdentifier($anchorTable),
            $connection->quoteIdentifier($ccpTable),
            $connection->quoteIdentifier($ancestorMapTable),
            $connection->quoteIdentifier($categorySnapshotTable),
            $connection->quoteIdentifier($ccpTable),
            $productFilterSql,
        );
    }
}
