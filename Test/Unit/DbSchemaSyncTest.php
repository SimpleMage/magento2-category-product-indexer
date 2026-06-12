<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the dual-definition of the snapshot tables: etc/db_schema.xml is the
 * canonical declaration (setup:upgrade creates the tables), while
 * SnapshotBuilder re-creates them at runtime with inline DDL (DROP/CREATE
 * self-heals schema bumps). The two MUST stay column-identical, otherwise
 * setup:db:status reports drift and setup:upgrade fights the runtime DDL.
 */
class DbSchemaSyncTest extends TestCase
{
    /**
     * Expected column sets per table — the single source of truth this test
     * holds BOTH definitions against.
     */
    private const EXPECTED_COLUMNS = [
        'simplemage_product_eav_snapshot' =>
            ['entity_id', 'store_id', 'status', 'visibility', 'is_salable_composite'],
        'simplemage_category_eav_snapshot' =>
            ['entity_id', 'store_id', 'is_active', 'is_anchor'],
        'simplemage_category_ancestor_map' =>
            ['ancestor_id', 'category_id'],
        'simplemage_category_product_anchor_snapshot' =>
            ['store_id', 'anchor_category_id', 'product_id', 'min_position', 'direct_position'],
    ];

    /**
     * A column unique to each table, used to match anonymous runtime
     * `CREATE TABLE %s (...)` blocks (sprintf placeholders carry no name).
     */
    private const SIGNATURE_COLUMN = [
        'simplemage_product_eav_snapshot' => 'is_salable_composite',
        'simplemage_category_eav_snapshot' => 'is_anchor',
        'simplemage_category_ancestor_map' => 'ancestor_id',
        'simplemage_category_product_anchor_snapshot' => 'anchor_category_id',
    ];

    public function testDbSchemaDeclaresExpectedTablesAndColumns(): void
    {
        $schemaFile = __DIR__ . '/../../etc/db_schema.xml';
        self::assertFileExists($schemaFile);

        $xml = \simplexml_load_file($schemaFile);
        self::assertNotFalse($xml, 'etc/db_schema.xml must be valid XML.');

        $declared = [];
        foreach ($xml->table as $table) {
            $name = (string) $table['name'];
            $columns = [];
            foreach ($table->column as $column) {
                $columns[] = (string) $column['name'];
            }
            $declared[$name] = $columns;
        }

        self::assertSame(
            \array_keys(self::EXPECTED_COLUMNS),
            \array_keys($declared),
            'db_schema.xml must declare exactly the four snapshot tables.',
        );
        foreach (self::EXPECTED_COLUMNS as $tableName => $expectedColumns) {
            self::assertSame(
                $expectedColumns,
                $declared[$tableName],
                \sprintf('db_schema.xml column drift on %s.', $tableName),
            );
        }
    }

    public function testRuntimeDdlMatchesDeclaredColumns(): void
    {
        $source = (string) \file_get_contents(
            __DIR__ . '/../../Model/Indexer/CategoryProduct/SnapshotBuilder.php',
        );
        self::assertNotSame('', $source);

        $blockCount = \preg_match_all(
            '/CREATE TABLE %s \(\s*(?<body>.*?)\)\s*ENGINE=InnoDB/s',
            $source,
            $matches,
        );
        self::assertSame(
            \count(self::EXPECTED_COLUMNS),
            $blockCount,
            'Expected exactly one runtime CREATE TABLE block per snapshot table.',
        );

        $runtimeByTable = [];
        foreach ($matches['body'] as $body) {
            $columns = [];
            foreach (\preg_split('/\n/', $body) ?: [] as $line) {
                $line = \trim($line);
                // Column lines start with the column identifier; constraint
                // lines start with PRIMARY KEY / KEY.
                if ($line === '' || \str_starts_with($line, 'PRIMARY KEY') || \str_starts_with($line, 'KEY ')) {
                    continue;
                }
                $columns[] = \explode(' ', $line, 2)[0];
            }

            $matchedTable = null;
            foreach (self::SIGNATURE_COLUMN as $tableName => $signature) {
                if (\in_array($signature, $columns, true)) {
                    $matchedTable = $tableName;
                    break;
                }
            }
            self::assertNotNull(
                $matchedTable,
                'Runtime CREATE TABLE block matches no known snapshot table: '
                . \implode(', ', $columns),
            );
            $runtimeByTable[$matchedTable] = $columns;
        }

        foreach (self::EXPECTED_COLUMNS as $tableName => $expectedColumns) {
            self::assertArrayHasKey(
                $tableName,
                $runtimeByTable,
                \sprintf('No runtime CREATE TABLE block found for %s.', $tableName),
            );
            self::assertSame(
                $expectedColumns,
                $runtimeByTable[$tableName],
                \sprintf(
                    'Runtime DDL for %s drifted from etc/db_schema.xml — '
                    . 'setup:db:status would report a mismatch.',
                    $tableName,
                ),
            );
        }
    }

    public function testRuntimeIndexNamesMatchDeclaredReferenceIds(): void
    {
        $schemaFile = __DIR__ . '/../../etc/db_schema.xml';
        $xml = \simplexml_load_file($schemaFile);
        self::assertNotFalse($xml);

        $declaredIndexes = [];
        foreach ($xml->table as $table) {
            foreach ($table->index as $index) {
                $declaredIndexes[] = (string) $index['referenceId'];
            }
        }
        self::assertNotEmpty($declaredIndexes);

        $source = (string) \file_get_contents(
            __DIR__ . '/../../Model/Indexer/CategoryProduct/SnapshotBuilder.php',
        );
        foreach ($declaredIndexes as $indexName) {
            self::assertStringContainsString(
                'KEY ' . $indexName . ' ',
                $source,
                \sprintf(
                    'Runtime DDL must create index %s under the same name as '
                    . 'db_schema.xml — differing names make setup:db:status '
                    . 'recreate indexes on every upgrade.',
                    $indexName,
                ),
            );
        }
    }
}
