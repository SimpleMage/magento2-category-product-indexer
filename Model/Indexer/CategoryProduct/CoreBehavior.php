<?php

/**
 * Copyright © SimpleMage. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct;

use Magento\Framework\App\ProductMetadataInterface;

/**
 * Semantic profile of the INSTALLED core's catalog_category_product behavior.
 *
 * Core changed indexer semantics between releases. To keep the rewrite's output
 * byte-identical with whatever core is installed, the snapshot-aware selects
 * must mirror the installed release, not a fixed one:
 *
 * - **2.4.8**: `getNonAnchorCategoriesSelect()` and `createAnchorSelect()` filter
 *   categories on `is_active` (`IFNULL(store_value, default_value) = 1` via the
 *   ccacd/ccacs joins). Before 2.4.8, products in inactive categories WERE
 *   indexed on the non-anchor path, and inactive anchor ancestors still
 *   received descendant products.
 *
 * Version detection uses ProductMetadataInterface::getVersion(). Mage-OS
 * deliberately keeps getVersion() Magento-compatible (distribution 2.2.1
 * reports "2.4.8-p4"; the distribution version is exposed separately via
 * getDistributionVersion()), so version_compare() is reliable on both Magento
 * and Mage-OS. Patch suffixes order correctly: "-pN" ranks above the plain
 * release in version_compare().
 *
 * Escape hatch for forks, backports, or git-dev installs where getVersion()
 * returns "UnknownVersion" — pin the flag explicitly in di.xml:
 *
 *   <type name="SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\CoreBehavior">
 *       <arguments>
 *           <argument name="filtersInactiveCategories" xsi:type="boolean">true</argument>
 *       </arguments>
 *   </type>
 */
class CoreBehavior
{
    /**
     * Release that introduced category is_active filtering in the non-anchor
     * select and on anchor-category rows.
     */
    private const CATEGORY_IS_ACTIVE_FILTER_SINCE = '2.4.8';

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ?bool $filtersInactiveCategories = null,
    ) {
    }

    /**
     * Whether the installed core excludes inactive categories from the
     * non-anchor select and from anchor-category rows (introduced in 2.4.8).
     */
    public function filtersInactiveCategories(): bool
    {
        return $this->filtersInactiveCategories ?? version_compare(
            $this->productMetadata->getVersion(),
            self::CATEGORY_IS_ACTIVE_FILTER_SINCE,
            '>=',
        );
    }
}
