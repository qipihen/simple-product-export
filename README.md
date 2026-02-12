# WordPress 内容导入导出工具

一个功能强大的 WordPress 插件，用于批量导入和导出产品、页面、文章和分类，支持自定义字段和 SEO 元数据。

## 项目背景

该插件最初是为 WooCommerce 产品批量导入导出而开发的工具。随着项目需求扩展，逐步增加了对 WordPress 页面、文章和分类的支持，并添加了筛选导出功能，方便用户批量管理网站内容。

### 主要功能

- 产品（WooCommerce）导入导出
- 页面（Page）导入导出
- 文章（Post）导入导出
- 产品分类导入导出
- 支持所有自定义字段（ACF）自动识别
- SEO 元数据同步（按当前激活插件：AIOSEO / Rank Math / Yoast）
- 按分类和关键词筛选导出
- 分类字段级选择导出（支持最小化回导）
- 异常行 + 汇总导入日志输出

## 版本信息

**当前版本：** 4.7.6

## 本地交付文件（都在本目录）

- 插件源码：`simple-product-export.php`
- 最新打包：`simple-product-export-v4.7.6.zip`
- 通用安装包别名：`simple-product-export.zip`
- 文档目录：`docs/`
  - `docs/GITHUB_PROJECT_OVERVIEW.md`（GitHub 功能总览）
  - `docs/RELEASE_NOTES_v4.7.6.md`
  - `docs/CSV_CONTRACT.md`
  - `docs/FAILURE_MATRIX.md`

## GitHub 文档入口

- 项目功能总览：`docs/GITHUB_PROJECT_OVERVIEW.md`
- CSV 契约：`docs/CSV_CONTRACT.md`
- 常见问题矩阵：`docs/FAILURE_MATRIX.md`

## 安装说明

1. 将 `simple-product-export.php` 文件上传到 WordPress 插件目录
   ```
   wp-content/plugins/simple-product-export/
   ```

2. 在 WordPress 后台启用插件

3. 在左侧菜单找到"导入导出工具"

## 使用指南

### 导出内容

1. 进入"导入导出工具"页面
2. 展开需要导出的内容类型
3. （可选）设置筛选条件：
   - 选择分类（多选）
   - 输入关键词搜索
4. 点击"导出"按钮下载 CSV 文件

### 导入内容

1. 导出 CSV 文件作为模板
2. 使用 Excel 或文本编辑器编辑 CSV 文件
3. 保存为 UTF-8 编码的 CSV 格式
4. 在"导入"区域选择编辑后的 CSV 文件
5. 点击"导入"按钮

**注意：** 导入时建议先备份数据库。

## CSV 格式说明

### 产品分类 CSV 格式

| 列名 | 说明 | 示例 |
|------|------|------|
| ID | 分类 ID | 21 |
| 标题/名称 | 分类名称 | AC Portable EV Chargers |
| Slug | URL 别名（支持 parent/child 格式） | portable-ev-chargers/gem-series |
| 描述 | 分类描述 | Experience charging freedom... |
| 父分类 ID | 父分类的 ID | 21 |
| Meta Title | SEO 标题 | Portable EV Charger Manufacturer |
| Meta Description | SEO 描述 | Experience charging freedom... |
| order | 排序顺序 | 2 |
| order_url | 保留字段 | - |

### Slug 路径格式支持

从 v4.2.1 开始，插件支持层级 slug 格式：

```
portable-ev-charger/gem-series
```

插件会自动提取最后一部分作为实际 slug，即 `gem-series`。

## 版本历史

### v4.7.6 (2026-02-12)
- 新增 ZIP/XLSX 误传检测（`PK\x03\x04` 文件头）
- 增强分类 SEO 同步与回读验证（AIOSEO / Rank Math / Yoast）
- 增强分类导入调试日志（列索引、逐行处理信息）
- 优化 ACF 优先写入与辅助 URL 列过滤逻辑

### v4.3.1 (2026-02-10)
- 增强调试信息输出，显示所有 term_meta keys
- 验证 AIOSEO 数据存储结果
- 支持多种 AIOSEO 格式同时存储

### v4.3.0 (2026-02-10)
- 添加 `flush_rewrite_rules()` 刷新 permalink 结构
- 使 slug 更改立即生效

### v4.2.1 (2026-02-10)
- 支持 slug 路径格式（parent/child）
- 自动提取子分类 slug

### v4.2.0 (2026-02-10)
- 修复 AIOSEO 4.0+ 分类元数据格式
- 支持 `aioseo_term` 数组格式

### v4.1.7 (2026-02-10)
- 修复 CSV ID 中的 BOM 字符问题
- 使用正则表达式提取纯数字 ID
- 添加详细调试信息输出

### v4.1.1 (2026-02-10)
- 修复分类导入列匹配问题
- 优先匹配"标题"列，回退到"名称"列
- 添加分类 AIOSEO 元字段处理

### v4.1.0 (2026-02-10)
- 添加筛选 UI 界面
- 支持按分类和关键词筛选导出
- 使用纯 JavaScript 实现展开/折叠功能

### v4.0.0 (2026-02-10)
- 添加页面和文章的导入导出功能
- 更改菜单为顶级菜单"导入导出工具"
- 添加页面/文章的 AIOSEO 支持

## 技术特性

### 支持的自定义字段

插件会自动扫描并导出所有自定义字段（不包含 `_` 和 `wp_` 前缀的字段除外），包括：
- ACF (Advanced Custom Fields) 字段
- Yoast SEO 字段
- All In One SEO 字段
- 其他第三方插件字段

### AIOSEO 支持

插件支持 All In One SEO 插件的多种格式：

**文章/页面格式：**
- `_aioseo_title` - SEO 标题
- `_aioseo_description` - SEO 描述
- `_aioseo_keywords` - SEO 关键词

**分类格式：**
- `aioseo_term` - AIOSEO 4.0+ 数组格式
- `_aioseo_title` - 旧版标题格式
- `_aioseop_title` - 更旧版标题格式

### BOM 字符处理

从 v4.1.7 开始，插件使用正则表达式处理 CSV 中的 BOM（字节顺序标记）字符：

```php
$cat_id = preg_replace('/[^0-9]/', '', $cat_id);
```

这确保了即使 CSV 文件包含 UTF-8 BOM 标记，ID 也能正确识别。

## 已知问题

### 1. URL 更新可能不立即生效

**问题：** 导入后修改的 URL 在前端可能仍显示旧 URL

**可能原因：**
- 浏览器缓存
- WordPress 缓存插件
- CDN 缓存
- Permalink 未刷新

**解决方案：**
- 清除浏览器缓存
- 清除 WordPress 缓存插件缓存
- 刷新 Permalink 设置（设置 → 固定链接 → 保存更改）
- 插件已添加 `flush_rewrite_rules()` 调用

### 2. Meta Title 更新可能不显示

**问题：** 导入后 Meta Title 在前端未更新

**调试方法：**
1. 查看导入后的调试信息
2. 检查 `aioseo_term` 是否正确存储
3. 验证数据库中的 `wp_termmeta` 表

**存储格式：**
```php
// AIOSEO 4.0+ 格式
update_term_meta($cat_id, 'aioseo_term', [
    'title' => 'Your Meta Title',
    'description' => 'Your Meta Description'
]);
```

## 示例文件

项目包含一个示例 CSV 文件：`product-categories-updated-v6.csv`

该文件展示了完整的产品分类结构，包括：
- 主分类（Products）
- 子分类（AC Portable EV Chargers, AC Wallbox Chargers 等）
- 三级分类（Gem Series, Ticool Series 等）

## 技术栈

- **PHP 7.4+**
- **WordPress 5.0+**
- **WooCommerce 3.0+**（产品功能需要）
- **All In One SEO 4.0+**（SEO 功能建议）

## 贡献

欢迎提交 Issue 和 Pull Request。

## 许可证

GPL v2 or later

## 作者

zhangkun

## 链接

- GitHub: https://github.com/qipihen/simple-product-export
