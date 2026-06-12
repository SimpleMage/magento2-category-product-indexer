<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Test\Integration;

use Magento\Catalog\Model\Indexer\Category\Product\Action\Full as CoreFull;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\FullAction;

/**
 * Integration coverage for the snapshot-based full reindex:
 *
 *   1. The di.xml preference actually resolves to our FullAction.
 *   2. A full reindex through the snapshot path terminates and produces
 *      non-empty per-store index tables.
 *   3. The output is DETERMINISTIC — two consecutive full reindexes on the
 *      same catalog produce bit-identical index tables (MD5 over an
 *      order-stable row serialisation).
 *
 * The module-vs-core bit-identical comparison (toggling the DI preference at
 * runtime) is NOT automated here — it requires a separate process per DI
 * configuration and lives in the external benchmark harness. It was last
 * verified manually against a 447k-product fixture.
 *
 * Runs under `dev/tests/integration/phpunit` with a fixture catalog. The
 * fixture should be SMALL — the goal here is correctness, not performance.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @magentoDataFixture Magento/Catalog/_files/categories.php
 */
class ReindexCorrectnessTest extends TestCase
{
    private ObjectManagerInterface $objectManager;
    private ResourceConnection $resource;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->resource = $this->objectManager->get(ResourceConnection::class);
    }

    /**
     * Smoke test: just confirm the preference is wired and instantiable.
     */
    public function testPreferenceResolvesToOurClass(): void
    {
        $full = $this->objectManager->create(CoreFull::class);
        self::assertInstanceOf(
            FullAction::class,
            $full,
            sprintf(
                'Expected Magento to resolve %s to %s via di.xml preference. '
                . 'Got %s instead — preference not wired (run setup:di:compile?).',
                CoreFull::class,
                FullAction::class,
                $full::class,
            ),
        );
    }

    /**
     * Run the full reindex pipeline end-to-end through our snapshot path,
     * verify it terminates successfully and produces non-empty output.
     *
     * Determinism of that output is pinned separately in
     * testFullReindexIsDeterministic(); the module-vs-core comparison lives
     * in the external benchmark harness (see class docblock).
     */
    public function testFullReindexProducesNonEmptyOutput(): void
    {
        $stores = $this->objectManager->get(StoreManagerInterface::class)->getStores(false);
        $storeIds = array_map(static fn ($store): int => (int)$store->getId(), $stores);

        $full = $this->objectManager->create(CoreFull::class);
        $full->execute(); // throws on failure

        $connection = $this->resource->getConnection();
        $hadAnyRows = false;
        foreach ($storeIds as $storeId) {
            $tableName = $this->resource->getTableName(
                "catalog_category_product_index_store{$storeId}",
            );
            $select = $connection->select()->from($tableName, ['cnt' => 'COUNT(*)']);
            $rowCount = (int)$connection->fetchOne($select);

            // Some stores legitimately have zero rows on this fixture (e.g.
            // the admin scope has no categories assigned). We just need at
            // least ONE store to have output to know the pipeline ran.
            if ($rowCount > 0) {
                $hadAnyRows = true;
                break;
            }
        }

        self::assertTrue(
            $hadAnyRows,
            'After full reindex no catalog_category_product_index_store* table had any rows. '
            . 'Either the fixture is empty or the reindex pipeline produced no output.',
        );
    }

    /**
     * Two consecutive full reindexes on the same catalog must produce
     * bit-identical index tables — a snapshot rebuild, chunked INSERTs and
     * the single-shot tmp/replica publishing must not introduce any
     * order-dependent or partially-stale output.
     */
    public function testFullReindexIsDeterministic(): void
    {
        $first = $this->objectManager->create(CoreFull::class);
        $first->execute();
        $firstHashes = $this->captureIndexTableHashes();

        $second = $this->objectManager->create(CoreFull::class);
        $second->execute();
        $secondHashes = $this->captureIndexTableHashes();

        self::assertNotEmpty(
            array_filter($firstHashes),
            'Expected at least one non-empty per-store index fingerprint after full reindex.',
        );
        self::assertSame(
            $firstHashes,
            $secondHashes,
            'Two consecutive full reindexes produced different index contents — '
            . 'the snapshot path is non-deterministic or leaks state between runs.',
        );
    }

    /**
     * Reads an order-stable MD5 fingerprint of every
     * catalog_category_product_index_storeN table, keyed by store_id.
     *
     * @return array<int, string> store_id => md5 hex ('' for empty tables)
     */
    private function captureIndexTableHashes(): array
    {
        $stores = $this->objectManager->get(StoreManagerInterface::class)->getStores(false);
        $connection = $this->resource->getConnection();
        $hashes = [];

        foreach ($stores as $store) {
            $storeId = (int)$store->getId();
            $tableName = $this->resource->getTableName(
                "catalog_category_product_index_store{$storeId}",
            );
            if (!$connection->isTableExists($tableName)) {
                continue;
            }

            // Order-stable hash: deterministic regardless of physical row order.
            $select = $connection->select()
                ->from($tableName, [
                    'fingerprint' => new \Zend_Db_Expr(
                        "MD5(GROUP_CONCAT("
                        . "CONCAT_WS('|',category_id,product_id,position,store_id,visibility,is_parent) "
                        . "ORDER BY category_id, product_id "
                        . "SEPARATOR ';'))",
                    ),
                ]);
            $hashes[$storeId] = (string)$connection->fetchOne($select);
        }

        return $hashes;
    }
}
