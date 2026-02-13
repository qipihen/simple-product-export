# Universal Import Export Platform Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 把当前插件从模板化 CSV 工具升级为通用导入导出平台，覆盖大多数客户站的数据迁移和批量维护场景。

**Architecture:** 采用分层架构（Source Parser -> Mapping Engine -> Match Engine -> Writer/Exporter -> Job Runner），前台用导入向导驱动配置，后台用任务执行器做分批处理和日志。保持当前已稳定能力（ACF 优先写入、SEO 按激活插件同步、taxonomy 字段懒加载），在其上逐步泛化。

**Tech Stack:** WordPress Plugin API, PHP 7.4+, MySQL, WP Cron, admin-ajax, plain JS, CSV/XML/XLSX parser (按阶段引入)

---

### Task 1: 定义产品边界（必须做 vs 明确不做）

**Files:**
- Create: `docs/PRD-universal-import-export.md`
- Modify: `README.md`

**Step 1: 写失败标准（反向验收）**
- 定义什么不算成功：
  - 仍依赖固定列顺序。
  - 无法在无 ID 场景按唯一键更新。
  - 大文件导入仍需整文件入内存。

**Step 2: 写成功标准（MVP）**
- 导入向导支持：文件识别、字段映射、匹配策略、预览、执行。
- 导出向导支持：多实体、多条件、多字段模板。
- 支持 post type + taxonomy + ACF 字段自动发现。

**Step 3: 更新 README 的能力矩阵**
- 新增“当前支持 / 规划中 / 不做”的表格，防止需求漂移。

**Step 4: 文档校验**
- Run: `rg -n "规划中|不做|成功标准|失败标准" docs/PRD-universal-import-export.md README.md`
- Expected: 命中上述关键段落。

**Step 5: Commit**
```bash
git add docs/PRD-universal-import-export.md README.md
git commit -m "docs: define universal import/export product boundaries"
```

### Task 2: 实体注册中心（支持所有站点通用）

**Files:**
- Create: `includes/class-spe-entity-registry.php`
- Modify: `simple-product-export.php`
- Test: `tests/test_entity_registry.php`

**Step 1: 写失败测试**
- 断言可发现所有 public post types 与 public taxonomies。
- 断言可通过 filter 排除敏感实体。

**Step 2: 运行失败测试**
- Run: `php tests/test_entity_registry.php`
- Expected: FAIL（类不存在或行为未实现）。

**Step 3: 最小实现**
- 新增 registry 类统一返回：
  - post entities（post/page/product + custom post types）
  - taxonomy entities（category/product_cat + custom taxonomies）
- 提供 filter：`spe_entity_registry_exclude`。

**Step 4: 运行测试**
- Run: `php tests/test_entity_registry.php`
- Expected: PASS。

**Step 5: Commit**
```bash
git add includes/class-spe-entity-registry.php simple-product-export.php tests/test_entity_registry.php
git commit -m "feat: add entity registry for universal post types and taxonomies"
```

### Task 3: 通用字段发现与映射引擎

**Files:**
- Create: `includes/class-spe-field-discovery.php`
- Create: `includes/class-spe-mapping-engine.php`
- Modify: `simple-product-export.php`
- Test: `tests/test_mapping_engine.php`

**Step 1: 写失败测试**
- 输入 CSV header，断言可自动映射到标准字段（title/slug/content/excerpt/meta fields）。
- 断言 ACF 字段按实体类型注入候选映射。

**Step 2: 运行失败测试**
- Run: `php tests/test_mapping_engine.php`
- Expected: FAIL。

**Step 3: 最小实现**
- 字段发现：统一聚合 base + SEO + meta + ACF。
- 映射引擎：自动匹配 + 人工覆盖 + 保存 profile。

**Step 4: 运行测试**
- Run: `php tests/test_mapping_engine.php`
- Expected: PASS。

**Step 5: Commit**
```bash
git add includes/class-spe-field-discovery.php includes/class-spe-mapping-engine.php simple-product-export.php tests/test_mapping_engine.php
git commit -m "feat: add universal field discovery and mapping engine"
```

### Task 4: 匹配策略（ID / slug / 自定义唯一键）

**Files:**
- Create: `includes/class-spe-match-engine.php`
- Modify: `simple-product-export.php`
- Test: `tests/test_match_engine.php`

**Step 1: 写失败测试**
- ID 匹配成功。
- 无 ID 时 slug 匹配成功。
- 自定义 meta 唯一键匹配成功。
- 匹配冲突返回错误行。

**Step 2: 运行失败测试**
- Run: `php tests/test_match_engine.php`
- Expected: FAIL。

**Step 3: 最小实现**
- MatchEngine 输出统一结果：`matched_id`, `action(update|insert|skip)`, `error`。

**Step 4: 运行测试**
- Run: `php tests/test_match_engine.php`
- Expected: PASS。

**Step 5: Commit**
```bash
git add includes/class-spe-match-engine.php simple-product-export.php tests/test_match_engine.php
git commit -m "feat: add import match strategies by id slug and unique meta"
```

### Task 5: 导入执行器（流式 + 分批 + 幂等）

**Files:**
- Create: `includes/class-spe-import-runner.php`
- Modify: `simple-product-export.php`
- Test: `tests/test_import_runner.php`

**Step 1: 写失败测试**
- 大文件不整表读内存（按行读取）。
- 分批处理可断点续跑。
- 重跑同一批不会重复写入（幂等策略）。

**Step 2: 运行失败测试**
- Run: `php tests/test_import_runner.php`
- Expected: FAIL。

**Step 3: 最小实现**
- runner 维护 job state（offset, counters, error rows）。
- 仅记录异常行 + 汇总，兼容你现有日志偏好。

**Step 4: 运行测试**
- Run: `php tests/test_import_runner.php`
- Expected: PASS。

**Step 5: Commit**
```bash
git add includes/class-spe-import-runner.php simple-product-export.php tests/test_import_runner.php
git commit -m "feat: add streaming batch import runner with resumable jobs"
```

### Task 6: 导出构建器（多实体、多字段模板）

**Files:**
- Create: `includes/class-spe-export-builder.php`
- Modify: `simple-product-export.php`
- Test: `tests/test_export_builder.php`

**Step 1: 写失败测试**
- 一次任务导出多个实体到 zip。
- 每个实体支持独立字段模板与筛选条件。

**Step 2: 运行失败测试**
- Run: `php tests/test_export_builder.php`
- Expected: FAIL。

**Step 3: 最小实现**
- 复用当前 bundle 导出逻辑，升级为实体化配置驱动。

**Step 4: 运行测试**
- Run: `php tests/test_export_builder.php`
- Expected: PASS。

**Step 5: Commit**
```bash
git add includes/class-spe-export-builder.php simple-product-export.php tests/test_export_builder.php
git commit -m "feat: add configurable multi-entity export builder"
```

### Task 7: 导入/导出向导 UI（接近 All Import/Export 使用体验）

**Files:**
- Modify: `simple-product-export.php`
- Create: `assets/spe-admin.css`
- Create: `assets/spe-admin.js`
- Test: `tests/test_admin_endpoints.php`

**Step 1: 写失败测试**
- AJAX endpoint 返回字段候选、映射预览、dry-run 统计。

**Step 2: 运行失败测试**
- Run: `php tests/test_admin_endpoints.php`
- Expected: FAIL。

**Step 3: 最小实现**
- Step1 选实体 + 上传文件。
- Step2 字段映射。
- Step3 匹配策略。
- Step4 dry-run。
- Step5 执行任务 + 状态轮询。

**Step 4: 运行测试**
- Run: `php tests/test_admin_endpoints.php`
- Expected: PASS。

**Step 5: Commit**
```bash
git add simple-product-export.php assets/spe-admin.css assets/spe-admin.js tests/test_admin_endpoints.php
git commit -m "feat: add import export wizard admin ui"
```

### Task 8: 任务调度与自动化（对齐企业站需求）

**Files:**
- Create: `includes/class-spe-scheduler.php`
- Modify: `simple-product-export.php`
- Test: `tests/test_scheduler.php`

**Step 1: 写失败测试**
- 可按 cron 周期执行导入/导出 profile。
- 失败任务可重试并告警。

**Step 2: 运行失败测试**
- Run: `php tests/test_scheduler.php`
- Expected: FAIL。

**Step 3: 最小实现**
- 基于 WP Cron + option/state 存储任务定义与状态。

**Step 4: 运行测试**
- Run: `php tests/test_scheduler.php`
- Expected: PASS。

**Step 5: Commit**
```bash
git add includes/class-spe-scheduler.php simple-product-export.php tests/test_scheduler.php
git commit -m "feat: add scheduled import export jobs"
```

### Task 9: 兼容性保障与迁移

**Files:**
- Create: `docs/MIGRATION_GUIDE.md`
- Modify: `README.md`
- Test: `tests/regression_csv_contract.php`

**Step 1: 回归清单**
- 现有 product/page/post/taxonomy 导入导出不回归。
- 现有 ACF + SEO 行为不回归。

**Step 2: 运行回归**
- Run: `php tests/regression_csv_contract.php`
- Expected: PASS。

**Step 3: 写迁移文档**
- 老入口继续保留，新向导入口标注 beta。

**Step 4: Commit**
```bash
git add docs/MIGRATION_GUIDE.md README.md tests/regression_csv_contract.php
git commit -m "docs: add migration guide and compatibility guarantees"
```

### Task 10: 统一验收命令与发布门禁

**Files:**
- Modify: `README.md`

**Step 1: 执行完整验证**
Run:
```bash
php tests/regression_csv_contract.php && \
php tests/test_entity_registry.php && \
php tests/test_mapping_engine.php && \
php tests/test_match_engine.php && \
php tests/test_import_runner.php && \
php tests/test_export_builder.php && \
php tests/test_admin_endpoints.php && \
php tests/test_scheduler.php
```
Expected: 全部 PASS。

**Step 2: 发布打包**
- 仅打包必要文件（源码 + docs + tests + README）。

**Step 3: Commit**
```bash
git add README.md
git commit -m "chore: add verification gate for universal platform rollout"
```

## Milestone 切分建议

- M1（2 周）: Task 1-4
- M2（2 周）: Task 5-7
- M3（1 周）: Task 8-10

## First-principles 裁剪（避免做成臃肿版本）

- 不做视觉 page builder 级复杂拖拽，仅做字段映射必要交互。
- 不做低价值模板预设泛滥，优先“可保存 profile + 可复制 profile”。
- 不做跨站连接器（Shopify/Airtable）直到本地 CSV/XML/XLSX 跑稳。
- 不做过度日志，只保留异常行 + 汇总 + 下载错误报告。
