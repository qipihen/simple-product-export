# Release Notes v4.7.7

Release Date: 2026-02-13

## Highlights

- Import profile templates are now applied during real import execution.
- Taxonomy export field options are no longer preloaded for all taxonomies on admin page load.
- Added GitHub Actions release pipeline for versioned ZIP artifacts.

## Changes

### 1. Import profile behavior completed

- Added profile selection resolver and merged profile config into import runtime:
  - product
  - page
  - post
  - taxonomy
- Saving a profile now auto-selects the newly saved profile in UI.
- Profile selection now syncs form defaults (allow insert + unique key field) before submit.

### 2. Admin taxonomy field loading performance

- Removed synchronous field option generation during admin page render.
- Kept AJAX-based on-demand field loading per selected taxonomy.
- Added in-request cache for taxonomy field options to avoid duplicate computation.

### 3. Release management

- Added `scripts/build-release-zip.sh` to produce reproducible plugin ZIP packages.
- Added `.github/workflows/release.yml`:
  - Trigger on pushed tags matching `v*`
  - Build `simple-product-export-v{version}.zip`
  - Publish GitHub Release with ZIP artifacts

## Verification

- `php -l simple-product-export.php`
- `php tests/test_entity_registry.php`
- `php tests/test_mapping_engine.php`
- `php tests/test_match_engine.php`
- `php tests/test_post_import_context_and_match.php`
- `php tests/test_taxonomy_import_context_and_match.php`
- `php tests/test_import_insert_helpers.php`
- `php tests/regression_csv_contract.php`
