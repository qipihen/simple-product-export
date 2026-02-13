# Release Notes v4.7.8

Release Date: 2026-02-13

## Highlights

- Product / Page / Post now support field-level export selection.
- Product / Page / Post / Taxonomy now support import column whitelist by header name.
- Import result summary now reports unmatched whitelist headers for easier troubleshooting.

## Changes

### 1. Field-level export for post entities

- Added export field picker in admin UI for:
  - product
  - page
  - post
- Export handlers now accept selected fields and only output chosen columns.
- Attachment helper URL columns (`*_url`) are now generated only for selected custom fields.

### 2. Column whitelist for import

- Added optional import input: `仅导入这些列名（可选）` for:
  - product
  - page
  - post
  - taxonomy
- Import writes now honor whitelist by header index:
  - base fields
  - SEO fields
  - custom fields
- If whitelist is configured but no header matches, import fails fast with a clear error.
- If part of whitelist is missing in CSV headers, import completes and reports missing names in summary.

### 3. Support utilities

- Added post-type export field metadata helpers:
  - `spe_get_post_type_base_export_fields`
  - `spe_get_post_type_export_exclude_keys`
  - `spe_get_post_type_custom_fields`
  - `spe_get_post_type_export_field_options`
  - `spe_resolve_export_selected_fields_from_options`
  - `spe_resolve_post_type_export_fields`
- Added import whitelist helpers:
  - `spe_parse_import_column_filter`
  - `spe_resolve_import_column_filter_from_request`
  - `spe_import_column_allowed`

## Verification

- `php -l simple-product-export.php`
- `php tests/test_entity_registry.php`
- `php tests/test_mapping_engine.php`
- `php tests/test_match_engine.php`
- `php tests/test_post_import_context_and_match.php`
- `php tests/test_taxonomy_import_context_and_match.php`
- `php tests/test_import_insert_helpers.php`
- `php tests/regression_csv_contract.php`
