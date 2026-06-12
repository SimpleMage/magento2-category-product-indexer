<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Test\Unit;

use Magento\Framework\App\ProductMetadataInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\CoreBehavior;

/**
 * Pins the version-detection contract: the rewrite must mirror the INSTALLED
 * core's semantics, and the 2.4.8 boundary (category is_active filtering) is
 * exactly where output would silently diverge if detection drifted.
 */
class CoreBehaviorTest extends TestCase
{
    /**
     * The annotation is kept alongside the attribute so the suite also runs
     * on PHPUnit 9.x shipped with Magento <= 2.4.6 dev environments.
     *
     * @dataProvider versionProvider
     */
    #[DataProvider('versionProvider')]
    public function testDetectsIsActiveFilteringByCoreVersion(string $coreVersion, bool $expected): void
    {
        $behavior = new CoreBehavior($this->mockMetadata($coreVersion));

        self::assertSame($expected, $behavior->filtersInactiveCategories());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function versionProvider(): array
    {
        return [
            // Pre-2.4.8: inactive categories ARE indexed on non-anchor + anchor paths.
            '2.4.4' => ['2.4.4', false],
            '2.4.6' => ['2.4.6', false],
            '2.4.6-p14' => ['2.4.6-p14', false],
            '2.4.7' => ['2.4.7', false],
            '2.4.7-p10 (patch never crosses minor boundary)' => ['2.4.7-p10', false],
            // 2.4.8+: core filters category is_active.
            '2.4.8' => ['2.4.8', true],
            '2.4.8-p4 (Mage-OS 2.2.x reports this)' => ['2.4.8-p4', true],
            '2.4.9 (Mage-OS 3.x reports this)' => ['2.4.9', true],
            '2.5.0' => ['2.5.0', true],
            // Git-dev installs without composer.lock — conservative default,
            // overridable via di.xml.
            'UnknownVersion' => ['UnknownVersion', false],
        ];
    }

    public function testExplicitDiOverrideBeatsVersionDetection(): void
    {
        $pinnedOn = new CoreBehavior($this->mockMetadata('2.4.6'), true);
        $pinnedOff = new CoreBehavior($this->mockMetadata('2.4.9'), false);

        self::assertTrue($pinnedOn->filtersInactiveCategories(), 'di.xml=true must win over old core version');
        self::assertFalse($pinnedOff->filtersInactiveCategories(), 'di.xml=false must win over new core version');
    }

    private function mockMetadata(string $version): ProductMetadataInterface
    {
        $metadata = $this->createMock(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn($version);

        return $metadata;
    }
}
