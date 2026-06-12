<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\SnapshotBuilder;

/**
 * Drops the snapshot tables on `bin/magento module:uninstall`. The tables are
 * also DROP/CREATE-managed at runtime by SnapshotBuilder, so a leftover table
 * is harmless — this just leaves the database clean.
 */
class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        $connection = $setup->getConnection();
        foreach ([
            SnapshotBuilder::PRODUCT_SNAPSHOT_TABLE,
            SnapshotBuilder::CATEGORY_SNAPSHOT_TABLE,
            SnapshotBuilder::ANCHOR_SNAPSHOT_TABLE,
            SnapshotBuilder::ANCESTOR_MAP_TABLE,
        ] as $table) {
            $connection->dropTable($setup->getTable($table));
        }

        $setup->endSetup();
    }
}
