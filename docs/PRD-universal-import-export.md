# PRD：Universal Import Export Platform

## 1. 背景

当前插件已经具备“模板 CSV 回写”能力，但目标是成为可在不同客户站复用的通用导入导出平台，体验和能力接近 `WP All Import / WP All Export`。

## 2. 产品目标

- 支持任意 public post type / taxonomy 的导入导出。
- 支持 ACF 字段自动发现与回写（post + taxonomy）。
- 支持字段映射、匹配策略、dry-run、分批执行。
- 支持保存导入/导出配置（profile）并复用。

## 3. 非目标（当前阶段不做）

- 不做页面构建器级可视化拖拽。
- 不做跨平台连接器（Shopify/Airtable/Notion）直连。
- 不做“全量操作日志”长期留存，仅保留异常行和汇总。
- 不做复杂权限系统（先沿用 `manage_options`）。

## 4. 成功标准（MVP）

- 业务人员可在不改代码前提下完成：
  - 选择实体 -> 上传文件 -> 字段映射 -> dry-run -> 执行。
- 无 ID 文件可通过 `slug` 或自定义唯一键完成更新匹配。
- 大文件导入不会整表读入内存，支持分批处理和续跑。
- 导出支持多实体一次打包，并可保存字段模板。

## 5. 失败标准（判定为未完成）

- 仍依赖固定列顺序才能导入。
- 文件稍大就超时或内存峰值过高。
- 无法稳定识别 ACF 字段，导致字段缺失。
- SEO 写入跨插件污染（未按激活插件隔离）。

## 6. 核心能力模型

### 6.1 Source Parser

- 输入格式：CSV（稳定）、XML/XLSX（后续阶段）。
- 输出统一行结构：`headers + row iterator`。

### 6.2 Mapping Engine

- 自动映射：按列名别名智能匹配。
- 手动映射：允许用户覆写自动结果。
- profile 持久化：实体 + 字段映射 + 匹配策略。

### 6.3 Match Engine

- 策略优先级：`ID > slug > unique_meta`。
- 冲突时生成错误行，不盲写。

### 6.4 Runner

- 流式读取。
- 分批写入（batch size 可配）。
- 状态可恢复：`offset/counters/error_rows`。

### 6.5 Writer

- post writer（post/page/product/custom post type）。
- term writer（category/product_cat/custom taxonomy）。
- ACF-first 写入 + fallback meta。
- SEO 仅写当前激活插件。

## 7. 可扩展点

- `spe_entity_registry_exclude`
- `spe_mapping_aliases`
- `spe_match_strategies`
- `spe_import_row_before_write`
- `spe_import_row_after_write`

## 8. 里程碑

- M1：实体注册 + 字段发现 + 匹配引擎
- M2：导入执行器 + 导出构建器 + 向导 UI
- M3：调度 + profile 管理 + 兼容回归

## 9. 风险与约束

- 单文件插件会降低可维护性，建议逐步拆到 `includes/`。
- 老站点插件冲突不可避免，需要 hooks 机制降低硬耦合。
- XLSX/XML 引入后需严格限制内存和执行时长。

## 10. 验收方式

- 以自动化回归测试 + 真实样本文件回放为准。
- 验收报告只包含：异常行样本、汇总统计、性能指标。
