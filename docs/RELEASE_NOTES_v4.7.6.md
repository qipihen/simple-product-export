# Release Notes v4.7.6

## 关键更新

- 新增上传文件签名检测：拦截误传的 ZIP/XLSX（`PK\x03\x04`）。
- 分类 SEO 元数据同步增强：AIOSEO / Rank Math / Yoast 同步写入与回读验证。
- 分类导入调试日志增强：包含表头匹配、ID 识别、逐行处理信息。
- ACF 字段写入策略改进：优先 `update_field()`，必要时回退 `term_meta`/`post_meta`。
- 表头标准化增强：处理 BOM 与首尾空白，降低列名匹配失败概率。
- `*_url` 辅助列过滤：避免覆盖真实业务字段。

## 建议导入流程

1. 先从目标站导出最新 CSV 作为模板。
2. 仅按模板列名改值，不改表头。
3. 上传 UTF-8 `.csv` 文本文件（不要上传 xlsx）。
4. 导入后查看调试日志并回读抽样行。

## 本地文档

- `docs/CSV_CONTRACT.md`
- `docs/FAILURE_MATRIX.md`
