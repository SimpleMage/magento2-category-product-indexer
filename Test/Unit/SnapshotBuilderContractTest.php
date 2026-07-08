<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\SnapshotBuilder;

/**
 * Pins SnapshotBuilder's public contract — the methods FullAction, RowsAction
 * and ProductCategoryRowsAction depend on. Renaming or removing any of these
 * breaks the action classes silently (DI resolves at runtime, not compile time).
 */
class SnapshotBuilderContractTest extends TestCase
{
    private const REQUIRED_PUBLIC_METHODS = [
        'ensureFresh',          // FullAction::reindex
        'ensureBuilt',          // internal precondition of both refreshFor* paths
        'refreshForProducts',   // ProductCategoryRowsAction::reindex
        'refreshForCategories', // RowsAction::reindex
        'dropAll',              // Setup\Uninstall / operational cleanup
        'getProductSnapshotTable',
        'getCategorySnapshotTable',
        'getAnchorSnapshotTable',
    ];

    public function testPublicApiHasExpectedMethods(): void
    {
        $reflection = new ReflectionClass(SnapshotBuilder::class);
        $publicMethods = \array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach (self::REQUIRED_PUBLIC_METHODS as $methodName) {
            self::assertContains(
                $methodName,
                $publicMethods,
                \sprintf(
                    'SnapshotBuilder must expose public method %s() — used by '
                    . 'the indexer action classes. Renaming or removing breaks '
                    . 'downstream contracts.',
                    $methodName,
                ),
            );
        }
    }

    public function testNoUnexpectedPublicMethods(): void
    {
        $reflection = new ReflectionClass(SnapshotBuilder::class);
        $publicMethods = \array_filter(
            \array_map(
                static fn (\ReflectionMethod $m): string => $m->getName(),
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            ),
            static fn (string $name): bool => $name !== '__construct',
        );

        $unexpected = \array_diff($publicMethods, self::REQUIRED_PUBLIC_METHODS);
        self::assertSame(
            [],
            \array_values($unexpected),
            'SnapshotBuilder grew public methods beyond the documented contract. '
            . 'Either make them private or extend this test (and the docs).',
        );
    }

    /**
     * The 1.0 line shipped a partial-refresh INSERT that silently omitted
     * is_salable_composite (falling back to the column DEFAULT) — composite
     * parents with all children disabled re-entered the index after every
     * partial reindex. Pin the fix: every INSERT into the product snapshot
     * must populate the column explicitly.
     */
    public function testEveryProductSnapshotInsertPopulatesSalabilityFlag(): void
    {
        $source = (string) \file_get_contents(
            __DIR__ . '/../../Model/Indexer/CategoryProduct/SnapshotBuilder.php',
        );
        self::assertNotSame('', $source);

        $insertCount = \preg_match_all(
            "/'entity_id', 'store_id', 'status', 'visibility'(?<salable>, 'is_salable_composite')?/",
            $source,
            $matches,
        );

        self::assertGreaterThanOrEqual(
            2,
            $insertCount,
            'Expected at least two product-snapshot INSERTs (full build + partial refresh).',
        );
        foreach ($matches['salable'] as $i => $capture) {
            self::assertSame(
                ", 'is_salable_composite'",
                $capture,
                \sprintf(
                    'Product snapshot INSERT #%d omits is_salable_composite — the column '
                    . 'would silently fall back to its DEFAULT and corrupt partial reindex output.',
                    $i + 1,
                ),
            );
        }
    }
}
