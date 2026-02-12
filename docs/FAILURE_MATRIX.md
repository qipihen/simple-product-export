# Failure Matrix (Import/Export Plugin Only)

## Symptom: 导入日志出现 `PK`，所有行跳过

Root cause:
- 上传的是 XLSX/ZIP（二进制）而不是纯 CSV 文本。

Fix:
- 在导入入口先检查文件头 `PK\x03\x04` 并直接报错。
- 强制用户上传 UTF-8 `.csv` 文件。

## Symptom: `ID列索引: 无`，每行都显示 “ID为空或无效”

Root cause:
- 表头不匹配（缺少 `ID` / `term_id`，或表头被污染）。

Fix:
- 先做 BOM/空格清洗再匹配表头。
- 导入前校验并提示必须存在主键列。

## Symptom: `applications` / `faq_items` 导入后编辑页仍为空

Root cause:
- CSV 列名与真实 ACF 字段名不一致。
- Repeater/Object 字段序列化格式与导入器预期不一致。

Fix:
- 以最新导出 CSV 的字段名为唯一真值，不手写猜测列名。
- 统一使用 JSON 字符串格式导入复杂字段。

## Symptom: Meta Title / Meta Description 导入后不生效

Root cause:
- 仅写了某一种 SEO 插件键，目标站读取的是另一种存储。

Fix:
- 分类导入后同步写入并回读验证：
- AIOSEO（term meta + `aioseo_terms` 表）
- Rank Math（`rank_math_title` / `rank_math_description`）
- Yoast（`wpseo_taxonomy_meta` + term meta）

## Symptom: 分类描述与 SEO 描述互相覆盖

Root cause:
- 把 `description` 列当成分类描述或 SEO 描述时语义混淆。

Fix:
- 明确区分：
- 分类描述列：`描述`
- SEO 描述列：`Meta Description`（或映射规则中显式声明）

## Symptom: 导入了 `*_url` 列后媒体字段异常

Root cause:
- 辅助 URL 列被当成业务字段写入，覆盖了真实字段结构。

Fix:
- 若同时存在 `field` 与 `field_url`，导入时跳过 `field_url`。
- 仅保留真实字段作为写入目标。

## Symptom: Slug 传入路径形式导致更新异常

Root cause:
- `slug` 列中带路径（如 `a/b/c`），未做提取和清洗。

Fix:
- 取最后一段并 `sanitize_title()` 后再写入。

## Symptom: 新增 ACF 字段导出看不到

Root cause:
- 导出器只会导出有值字段；仅导入字段定义但没有值时不会出现在导出 CSV。

Fix:
- 先导入 ACF 字段组定义。
- 给字段写入至少一条测试值后再导出核对。
