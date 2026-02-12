# CSV Contract (simple-product-export)

## Global

- Encoding: UTF-8
- Format: plain CSV text (not XLSX/ZIP)
- Primary matching: by `ID` (or `term_id` for taxonomy import)

## Product Import (posts)

Recognized base columns:
- `ID` (required)
- `标题` or `Title`
- `Slug`
- `短描述` or `Short Description`
- `长描述` or `Long Description`
- `Meta Title`
- `Meta Description`

Other columns:
- Treated as custom fields and written through ACF-first path.
- Helper URL columns (`*_url`) should not replace base field columns.

## Taxonomy Import (e.g. product_cat)

Recognized base columns:
- `ID` or `term_id` (required)
- `标题` / `名称` / `name`
- `Slug` / `slug`
- `描述` (term description)
- `父分类 ID` / `parent`
- `Meta Title` (fallback alias: `title`)
- `Meta Description` (fallback alias: `description`)

Other columns:
- Treated as taxonomy custom fields and written through ACF-first path.
- If both `field` and `field_url` exist, import `field`, ignore `field_url`.

## Complex Field Serialization

For repeaters/objects/media arrays:
- Use JSON string in one column value.
- Keep one stable schema per field.
- Validate by exporting a sample row and confirming round-trip consistency.
