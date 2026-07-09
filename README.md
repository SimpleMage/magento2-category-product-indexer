# SimpleMage - Category/Product Indexer Rewrite

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Magento 2.4](https://img.shields.io/badge/Magento-2.4-orange.svg)](https://magento.com/)
[![PHP 8.2-8.5](https://img.shields.io/badge/PHP-8.2--8.5-777BB4.svg)](https://www.php.net/)

**High-performance drop-in replacement for Magento 2's `catalog_category_product` indexer.**

Solves the classic *"Could not acquire lock for index: catalog_category_product"* hang on large catalogs (500k+ products) and delivers **2.6×-7.7× faster reindex** measured on real-world production databases - without schema changes, without breaking compatibility.

## The problem

Magento 2's stock `catalog_category_product` indexer runs a single mega-`INSERT...SELECT` with **13 JOINs**, including:

- 4× `catalog_product_entity_int` (status default + store override, visibility default + store override)
- 2× `catalog_category_entity_int` (is_active default + store override)
- 2× `catalog_category_product` (parent + child position)
- 2× `catalog_product_entity_int` for configurable child products
- `temp_catalog_category_tree_index_*` for anchor expansion
- `catalog_product_relation`, `catalog_product_entity`, `catalog_product_website`

Plus a `WHERE` clause with `IFNULL(store_value, default_value) = X` on three EAV columns - which **defeats the query optimizer** and forces a nested-loop join across the full Cartesian product.

On any non-trivial catalog this causes:

- ⏱️ **Multi-hour reindex times** that often never complete
- 🔒 **Million+ row locks held simultaneously** (`1 056 250` measured on a 447k-product store)
- 💥 **"Could not acquire lock for index"** errors when MySQL kills the transaction
- 🪦 **Suspended scheduler state** in `indexer:status` requiring manual `indexer:reset`
- 📉 **Drift between admin and storefront** - products added to categories never appear

If you've ever seen `catalog_category_product` stuck at "Processing" in `indexer:status` while the schedule shows "suspended" - this is for you.

## The solution: Snapshot Pattern

Instead of resolving EAV values on every reindex via `IFNULL(store, default)` joins, this module **pre-materializes** the EAV state into snapshot tables once:

| Snapshot table | Replaces | Built when |
|---|---|---|
| `simplemage_product_eav_snapshot` | 4× JOIN to `catalog_product_entity_int` (status + visibility) | Before every full reindex; incrementally refreshed on partial (including `is_salable_composite`) |
| `simplemage_category_eav_snapshot` | 2× JOIN to `catalog_category_entity_int` (is_active) | Same |
| `simplemage_category_ancestor_map` | Recursive walk over `catalog_category_entity.path` | Before every full reindex; rebuilt on category-trigger partials (moves/creates) |
| `simplemage_category_product_anchor_snapshot` | Anchor expansion (parent category inherits products from descendants) - **store-aware**: per-store `is_active` of the assignment category, same semantics as core's per-store temp tree | Before every full reindex; refreshed per affected product on partials |

The actual reindex `INSERT...SELECT` then drops from **13 JOINs → 2 JOINs** (PK lookups against snapshot tables, with proper indexes), and the `WHERE` clause becomes a plain equality without `IFNULL` - letting the query optimizer pick a sane plan.

## Measured impact

All numbers below come from full-reindex benchmarks on copies of real production
databases. In every run the index output was verified **byte-for-byte identical**
to core Magento via full-content MD5 snapshots (every row, every column, every
store view - no sampling).

### Magento 2.4.7-p7 - 111k products, 700+ categories

| Metric | Core Magento | This module | Improvement |
|---|---:|---:|---:|
| **Wall-clock time** | 124.4 s | **16.2 s** | **7.7× faster** |
| SQL queries | 6 906 | 2 442 | 2.8× fewer |
| Slow queries | 391 | 35 | 11.2× fewer |
| Output identical to core | - | ✅ MATCH (130 742 rows) | |

### Magento 2.4.6-p14 - 447k products, 3 store views, anchor-heavy taxonomy

| Metric | Core Magento | This module |
|---|---|---|
| **Wall-clock time** | hangs (> 24 h, killed) | **2 min 41 s** |
| Row locks held simultaneously | 1 056 250 (growing) | ~few thousand (chunked) |
| Tables locked simultaneously | 7 of 19 in use | 4-5 |
| JOINs in main `INSERT...SELECT` | 13 | 2 |
| EAV resolution | per-batch, via `IFNULL × 8` | once, materialized |

### Mage-OS 2.2.1 (Magento 2.4.8-p4) - 503k products, 4 store views, anchor-heavy taxonomy

| Metric | Core Magento | This module | Improvement |
|---|---:|---:|---:|
| **Wall-clock time** | 42 min 8 s | **16 min 21 s** | **2.6× faster** |
| Peak memory | 84 MB | 52 MB | 1.6× less |
| Slow queries | 48 | 20 | 2.4× fewer |
| Output identical to core | - | ✅ MATCH (18 728 407 rows) | |

### Adobe Commerce 2.4.7-p10 - 116k products, 2 store views, live scheduled updates

Correctness-only verification of the staging support added in 1.1.0 - no timings were
recorded for this run.

| Check | Result |
|---|---|
| Full reindex output vs core | ✅ MATCH (row count + per-store CRC32 fingerprints) |
| Partial (mview) reindex output vs core | ✅ MATCH |
| Product with 3 live staging versions | ✅ exactly one snapshot row per store |

Contributed verification, [#1](https://github.com/SimpleMage/magento2-category-product-indexer/pull/1).

Your mileage will vary by catalog size, taxonomy shape, and MySQL/MariaDB tuning - but the architectural advantage holds across all measured workloads.

## Installation

### Via composer (recommended once published)

```bash
composer require simplemage/module-category-product-indexer
bin/magento module:enable SimpleMage_CategoryProductIndexer
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Manual installation (until published)

```bash
# Drop the module into app/code/
mkdir -p app/code/SimpleMage
cp -r <this-repo> app/code/SimpleMage/CategoryProductIndexer

# Enable and compile
bin/magento module:enable SimpleMage_CategoryProductIndexer
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Usage

After installation the module takes over automatically - no configuration needed. Existing reindex commands work exactly the same:

```bash
# Full reindex of the (now-fast) catalog_category_product
bin/magento indexer:reindex catalog_category_product

# Or the linked pair
bin/magento indexer:reindex catalog_category_product catalog_product_category

# Or the whole indexer set
bin/magento indexer:reindex
```

If you had a stuck indexer prior to installing this module, also run:

```bash
bin/magento indexer:reset catalog_category_product catalog_product_category
```

This clears any stale lock state from prior failed runs.

### Verifying it's active

```bash
bin/magento dev:di:info "Magento\\Catalog\\Model\\Indexer\\Category\\Product\\Action\\Full"
```

Should report:

```
Preference: SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\FullAction
```

### Disabling (fallback to core)

If you ever need to revert to core Magento indexing (debugging, comparison, etc.):

```bash
bin/magento module:disable SimpleMage_CategoryProductIndexer
bin/magento setup:di:compile
bin/magento cache:flush
```

With the module merely *disabled*, the snapshot tables (`simplemage_*`) are kept on
disk - harmless, and instantly reusable if you re-enable. A full
`bin/magento module:uninstall SimpleMage_CategoryProductIndexer` drops them via
`Setup/Uninstall.php`; after a manual (file-delete) removal, drop them yourself:

```sql
DROP TABLE IF EXISTS simplemage_product_eav_snapshot;
DROP TABLE IF EXISTS simplemage_category_eav_snapshot;
DROP TABLE IF EXISTS simplemage_category_ancestor_map;
DROP TABLE IF EXISTS simplemage_category_product_anchor_snapshot;
```

## What gets overridden

This module installs three `<preference>` rewrites in `etc/di.xml`:

| Core class | Replacement |
|---|---|
| `Magento\Catalog\Model\Indexer\Category\Product\Action\Full` | `SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\FullAction` |
| `Magento\Catalog\Model\Indexer\Category\Product\Action\Rows` | `SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\RowsAction` |
| `Magento\Catalog\Model\Indexer\Product\Category\Action\Rows` | `SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\ProductCategoryRowsAction` |

All three replacements **extend** their core counterparts. Every overridden hook is gated on a per-run `snapshotReady` flag: when the snapshot build/refresh fails (lock wait timeout, disk full, DB error, etc.), the flag stays `false`, the failure is logged, and the entire run executes core's original EAV-JOIN implementations - so a failed snapshot never breaks indexing or corrupts output, it just runs slower.

## Compatibility

- ✅ **Magento Open Source 2.4.6 - 2.4.9** (tested on 2.4.6-p14, 2.4.7-p7, and 2.4.8-p4 via Mage-OS 2.2.x)
- ✅ **Adobe Commerce 2.4.6 - 2.4.9** including staging — EAV joins resolve the metadata link field (`row_id`) and all snapshot reads are built as framework `Select` objects, so the staging `FromRenderer` applies current-version filters (verified byte-identical on a 2.4.7-p10 EE catalog with live scheduled updates)
- ✅ **Mage-OS 1.x / 2.x / 3.x** - `getVersion()` stays Magento-compatible, so detection below works unchanged
- ✅ **PHP 8.2 / 8.3 / 8.4 / 8.5** - matches the PHP window of the supported Magento releases; CI runs the full matrix
- ✅ **MySQL 8.0** and **MariaDB 10.4 - 10.11**
- ✅ Multi-store, multi-website
- ✅ Anchor categories
- ✅ Configurable products (parent-child visibility resolution)
- ✅ Disabled-product handling (respects `dev/indexer/include_disabled_products`)
- ⚠️  Custom indexer extensions: any third-party module with its own `<preference>` on the same three core classes will conflict. Check before installing.

### Version-adaptive semantics

Core changed `catalog_category_product` behavior between releases; the module
mirrors the **installed** core so output stays byte-identical on every version:

| Core change | Introduced | How the module adapts |
|---|---|---|
| Non-anchor + anchor selects filter category `is_active` | 2.4.8 | `CoreBehavior::filtersInactiveCategories()` - `version_compare()` on `ProductMetadataInterface::getVersion()` (Mage-OS reports the Magento-compatible version, e.g. 2.2.x → `2.4.8-p4`) |
| `_tmp` operations must use the adapter that created the TEMPORARY table | 2.4.9 | Feature detection: `method_exists($tableMaintainer, 'getSameAdapterConnection')` |

For forks or backports where `getVersion()` is unreliable (e.g. git-dev installs
reporting `UnknownVersion`), pin the behavior explicitly in `di.xml`:

```xml
<type name="SimpleMage\CategoryProductIndexer\Model\Indexer\CategoryProduct\CoreBehavior">
    <arguments>
        <argument name="filtersInactiveCategories" xsi:type="boolean">true</argument>
    </arguments>
</type>
```

## Architecture

### Class layout

```
SimpleMage/CategoryProductIndexer/
├── etc/
│   ├── module.xml
│   ├── di.xml                       ← 3 <preference> rewrites
│   ├── db_schema.xml                ← canonical snapshot-table declarations
│   └── db_schema_whitelist.json
├── Model/
│   └── Indexer/
│       └── CategoryProduct/
│           ├── FullAction.php                   ← rewrites core Full (executeFull path)
│           ├── RowsAction.php                   ← rewrites core Rows (category-change path)
│           ├── ProductCategoryRowsAction.php    ← rewrites core Rows (product-change path)
│           ├── SnapshotBuilder.php              ← builds + maintains snapshot tables
│           ├── SnapshotAwareSelectsTrait.php    ← shared Select-rewriting logic for Rows
│           └── CoreBehavior.php                 ← version-adaptive core semantics (see Compatibility)
├── Setup/
│   └── Uninstall.php                ← drops snapshot tables on module:uninstall
├── Test/
│   ├── Unit/                        ← phpunit unit tests
│   └── Integration/                 ← phpunit integration tests (Magento test env)
├── composer.json
├── registration.php
├── LICENSE
└── README.md
```

### Reindex flow (Full)

```
┌─────────────────────────────────────────────────────────────┐
│ FullAction::execute()                                       │
└─────┬───────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────┐
│ SnapshotBuilder::ensureFresh()                              │
│   ├─ acquire GET_LOCK (blocking; timeout → core fallback)   │
│   ├─ build simplemage_product_eav_snapshot   (chunked, ~5k)     │
│   ├─ build simplemage_category_eav_snapshot                     │
│   ├─ build simplemage_category_ancestor_map                     │
│   ├─ build simplemage_category_product_anchor_snapshot          │
│   └─ release lock                                           │
└─────┬───────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────┐
│ parent::execute() ← core Magento Full, but with rewritten   │
│                     Select objects that read from snapshot  │
│                     instead of EAV tables                   │
└─────────────────────────────────────────────────────────────┘
```

### Why "snapshot tables" are permanent (not temp)

Unlike `temp_catalog_category_tree_index_*` which Magento drops/rebuilds on every run, our snapshot tables stay alive between reindexes. Reasons:

1. **Partial-reindex reuse** - `RowsAction` and `ProductCategoryRowsAction` refresh only the affected rows in the existing snapshot via `refreshForCategories()` / `refreshForProducts()` instead of rebuilding the whole thing. A temp table would be gone between calls.
2. **Forked-worker compatibility** - the snapshot must survive the `pcntl_fork()` that Magento's `ProcessManager` performs in batch mode. Per-connection temp tables wouldn't be visible in forked workers.

### Why a `trait` for the Rows path

`Magento\Catalog\Model\Indexer\Category\Product\Action\Rows` and `Magento\Catalog\Model\Indexer\Product\Category\Action\Rows` share Select-building logic via their common `AbstractAction` parent - but at a `protected` granularity that we cannot easily wrap or proxy.

`SnapshotAwareSelectsTrait` is composed into both Rows replacement classes and provides shared snapshot-aware Select rewriters. The trait pattern lets us keep zero code duplication while still extending the right core classes individually.

## Testing

```bash
# Run unit tests
vendor/bin/phpunit -c app/code/SimpleMage/CategoryProductIndexer/Test/Unit/phpunit.xml.dist

# Run integration tests (requires Magento test environment)
dev/tests/integration/phpunit \
  app/code/SimpleMage/CategoryProductIndexer/Test/Integration/
```

The integration suite verifies that:

1. The di.xml preference resolves to our `FullAction`
2. A full reindex through the snapshot path terminates and produces non-empty per-store index tables
3. Two consecutive full reindexes produce **bit-identical** output (order-stable MD5 fingerprints per store)

The module-vs-core bit-identical comparison (toggling the DI preference between runs) requires a separate process per DI configuration and lives in the companion `SimpleMage_IndexerBenchmark` module (`simplemage:bench:snapshot -d <label>`) - results for real catalogs are listed under [Measured impact](#measured-impact). The unit suite additionally pins the SnapshotBuilder public contract, guards `etc/db_schema.xml` against drifting from the runtime DDL, and asserts every product-snapshot INSERT populates `is_salable_composite`.

## Known limitations

- **Bit-identical module-vs-core verifier not yet automated in-repo.** Output is verified MD5-identical to core on real catalogs (see [Measured impact](#measured-impact)); the in-repo integration suite pins preference wiring, non-empty output, and run-to-run determinism, but the comparison runner that toggles core vs. snapshot mode needs a separate process per DI configuration and lives in the companion `SimpleMage_IndexerBenchmark` module. Tracked in [#4](https://github.com/simplemage/magento2-category-product-indexer/issues/4).
- **New store views require a full reindex.** Snapshot rows are materialised per existing store view; after creating a store view, run `bin/magento indexer:reindex catalog_category_product` once (core requires the same).

## License

Released under the **MIT License** - see [LICENSE](LICENSE) for the full text.

## Contributing

Pull requests welcome. For substantial changes, please open an issue first to discuss what you'd like to change.

When submitting:

- Run `vendor/bin/phpcs` (Magento Coding Standard)
- Run `vendor/bin/phpstan analyse` at level 8
- Add or update tests in `Test/Unit/` and/or `Test/Integration/`
- Include a measurable performance number if your change touches the hot path

## Reporting bugs

If you hit *"Could not acquire lock"* or any other reindex failure with this module installed, please attach:

1. `bin/magento indexer:status catalog_category_product catalog_product_category`
2. Output of `SHOW ENGINE INNODB STATUS \G` from the time of failure
3. Approximate catalog size (`SELECT COUNT(*) FROM catalog_product_entity`), number of store views, and whether you use anchor categories
4. Magento version and PHP/MySQL versions

Issues: https://github.com/simplemage/magento2-category-product-indexer/issues
