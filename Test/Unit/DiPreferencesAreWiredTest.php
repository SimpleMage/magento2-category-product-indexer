<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pure-XML test that the module's etc/di.xml installs all three required
 * preferences, with no `<type>` rewrites that would silently override the
 * preferences for downstream extensions.
 *
 * This is the minimum sanity contract: if any of the three preferences goes
 * missing during refactoring, our hot-path rewrites stop firing — silently.
 * The test pins down the wiring at the XML level, no Magento bootstrap
 * required.
 */
class DiPreferencesAreWiredTest extends TestCase
{
    private const REQUIRED_PREFERENCES = [
        'Magento\Catalog\Model\Indexer\Category\Product\Action\Full'
            => 'SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\FullAction',
        'Magento\Catalog\Model\Indexer\Category\Product\Action\Rows'
            => 'SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\RowsAction',
        'Magento\Catalog\Model\Indexer\Product\Category\Action\Rows'
            => 'SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\ProductCategoryRowsAction',
    ];

    private \DOMDocument $diXml;

    protected function setUp(): void
    {
        $this->diXml = new \DOMDocument();
        $loaded = $this->diXml->load(__DIR__ . '/../../etc/di.xml');
        self::assertTrue($loaded, 'etc/di.xml must exist and be valid XML.');
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function preferenceProvider(): iterable
    {
        foreach (self::REQUIRED_PREFERENCES as $core => $replacement) {
            yield $core => [$core, $replacement];
        }
    }

    /**
     * @dataProvider preferenceProvider
     */
    public function testPreferenceRegistered(string $coreClass, string $replacementClass): void
    {
        $xpath = new \DOMXPath($this->diXml);
        $nodes = $xpath->query(
            sprintf('//preference[@for=%s]', $this->xpathLiteral($coreClass)),
        );
        self::assertNotFalse($nodes);
        self::assertSame(
            1,
            $nodes->length,
            sprintf('Expected exactly one <preference for="%s"> in di.xml.', $coreClass),
        );

        $type = $nodes->item(0)->attributes->getNamedItem('type')?->nodeValue;
        self::assertSame(
            $replacementClass,
            $type,
            sprintf(
                'Preference for %s must point at %s (got %s).',
                $coreClass,
                $replacementClass,
                $type ?? 'null',
            ),
        );
    }

    public function testNoUnexpectedPreferences(): void
    {
        $xpath = new \DOMXPath($this->diXml);
        $all = $xpath->query('//preference');
        self::assertNotFalse($all);

        self::assertSame(
            count(self::REQUIRED_PREFERENCES),
            $all->length,
            'di.xml has extra <preference> nodes beyond the documented three. '
            . 'Either remove them or extend the test contract to cover them.',
        );
    }

    public function testReplacementClassesExist(): void
    {
        foreach (self::REQUIRED_PREFERENCES as $replacement) {
            $relativePath = \str_replace(
                ['SimpleMage\\CategoryProductIndexer\\', '\\'],
                ['', '/'],
                $replacement,
            ) . '.php';
            $absolutePath = __DIR__ . '/../../' . $relativePath;

            self::assertFileExists(
                $absolutePath,
                \sprintf('Replacement class file missing: %s', $relativePath),
            );
        }
    }

    /**
     * Safely quote a string for use inside an XPath expression.
     * (XPath 1.0 has no string-escape syntax, so we have to wrap with
     * concat() if the string contains both quote types.)
     */
    private function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'{$value}'";
        }
        if (!str_contains($value, '"')) {
            return "\"{$value}\"";
        }
        $parts = explode("'", $value);
        return 'concat(' . implode(", \"'\", ", array_map(
            static fn (string $p): string => "'{$p}'",
            $parts,
        )) . ')';
    }
}
