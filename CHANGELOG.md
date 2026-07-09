# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-07-09

Adobe Commerce (staging) support. Open Source and Mage-OS behaviour is unchanged —
verified byte-for-byte on a 447k-product catalog (see *Verified* below).

### Added

- **Adobe Commerce (staging) compatibility** ([#1](https://github.com/SimpleMage/magento2-category-product-indexer/pull/1), thanks [@TuVanDev](https://github.com/TuVanDev))
  - EAV joins and `catalog_product_relation.parent_id` now resolve the entity link
    field through `MetadataPool` (`row_id` on Adobe Commerce, `entity_id` on Open
    Source), mirroring core `AbstractAction`.
  - Every snapshot `INSERT ... SELECT` (product, category, and the composite-salability
    subquery) is built as a framework `Select` object instead of a raw SQL string, so
    Adobe Commerce's staging `FromRenderer` injects the `created_in`/`updated_in`
    current-version filters at assemble time. Raw SQL bypassed that renderer and would
    have read every historical version of an entity with a scheduled update.

### Fixed

- **Snapshot key joins on Adobe Commerce.** Three joins in `SnapshotAwareSelectsTrait`
  matched the snapshot tables against the metadata link field (`cp.row_id`) instead of
  the stable `entity_id` they are keyed by, silently dropping products whose `row_id`
  diverges from `entity_id`. No effect on Open Source, where the two are identical.

### Changed

- **`SnapshotBuilder::__construct()` now requires `MetadataPool`.** Autowired by DI —
  no `di.xml` changes needed — but run `bin/magento setup:di:compile` after upgrading.
  Technically breaking for anyone extending `SnapshotBuilder` directly.

### Verified

- **Magento Open Source 2.4.6-p14** — 447,730 products, 538 categories, 3 store views:
  full-reindex index output **byte-for-byte identical to 1.0.0** (676,395 rows, matching
  MD5), identical query profile (421 queries, 208 slow queries, 8 temp tables), no
  performance regression (212.3 s vs 214.8 s), partial reindex idempotent, 22/22 unit
  tests green.
- **Adobe Commerce 2.4.7-p10** — 116k products, 2 store views, live scheduled updates:
  full and partial reindex output byte-identical to core; a product with three live
  staging versions yields exactly one snapshot row per store.

## [1.0.0] - 2026-06-12

Initial public release.

### Added

- Drop-in replacement for Magento 2's `catalog_category_product` indexer using the
  snapshot pattern: EAV state is materialised once into helper tables, cutting the main
  `INSERT ... SELECT` from 13 JOINs to 2.
- Full reindex (`FullAction`) plus both partial-reindex paths (`RowsAction` for
  category triggers, `ProductCategoryRowsAction` for product triggers).
- Store-aware anchor snapshot reproducing core's per-store `is_active` semantics.
- Version-adaptive core behaviour (`CoreBehavior`): mirrors the inactive-category
  filtering introduced in 2.4.8 and the `getSameAdapterConnection()` temp-table
  handling introduced in 2.4.9.
- Genuine fallback to core: every overridden hook is gated on a per-run `snapshotReady`
  flag, so a failed snapshot build logs and runs the stock EAV-JOIN path rather than
  producing wrong output.
- Declarative schema (`etc/db_schema.xml` + whitelist) and `Setup/Uninstall.php`.
- Blocking advisory lock serialising all snapshot writes.

### Performance

Measured against core Magento on real production databases: **2.6×–7.7× faster full
reindex**. A 447k-product store where core never completed a reindex now finishes in
2 min 40 s. Output verified MD5-identical to core.

[1.1.0]: https://github.com/SimpleMage/magento2-category-product-indexer/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/SimpleMage/magento2-category-product-indexer/releases/tag/v1.0.0
