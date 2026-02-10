# FileBird Pro - WooCommerce 图片自动重命名模块

[![License](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-brightgreen.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-ff6745.svg)](https://woocommerce.com/)

## 简介

这是一个为 FileBird Pro 插件开发的扩展模块，专门用于 WooCommerce 网站的图片自动化管理。该模块能够根据产品信息自动重命名、分类和管理产品图片。

### 主要功能

- **智能文件重命名**: 根据产品 SKU、Slug 或标题自动生成英文文件名
- **图片类型识别**: 自动识别主图、枪头、场景、细节、包装、安装等图片类型
- **中文字段翻译**: EV 充电桩相关术语自动翻译为英文
- **物理文件夹管理**: 按产品分类自动创建文件夹并移动图片
- **FileBird 虚拟文件夹同步**: 自动同步到 FileBird 的虚拟文件夹系统
- **Alt 标签自动生成**: 根据产品信息自动生成 SEO 友好的 Alt 标签
- **批量处理**: 支持按分类或手动选择产品进行批量处理
- **备份与回滚**: 自动备份原始数据，支持一键回滚

## 目录结构

```
filebird-pro/
├── includes/
│   ├── Classes/
│   │   ├── Modules/
│   │   │   └── WooImageRename/
│   │   │       ├── PinyinConverter.php       # 拼音转换器
│   │   │       ├── AltGenerator.php          # Alt 标签生成器
│   │   │       ├── FolderSync.php            # 虚拟文件夹同步器
│   │   │       ├── RollbackManager.php       # 备份回滚管理器
│   │   │       ├── FileMover.php             # 文件移动器
│   │   │       ├── FileRenamer.php           # 文件重命名器
│   │   │       ├── ImageProcessor.php        # 图片处理引擎
│   │   │       └── ModuleWooImageRename.php  # 主模块类
│   │   └── Core.php                          # 核心（已修改）
│   └── Admin/
│       └── Settings.php                      # 设置页面（已修改）
├── views/
│   └── woo-image-rename/
│       └── settings-page.php                 # 设置页面视图
└── assets/
    └── css/
        └── woo-image-rename.css              # 模块样式
```

## 安装说明

### 前置要求

- WordPress 5.8+
- PHP 7.4+
- WooCommerce 5.0+
- FileBird Pro 6.0+

### 安装步骤

1. 将所有文件复制到 FileBird Pro 插件目录
2. 确保 WooCommerce 已启用
3. 模块会自动加载，无需手动激活

## 使用指南

### 设置页面

访问 **WordPress 后台 > FileBird > Woo 图片重命名** 进入设置页面。

### 处理选项

| 选项 | 说明 |
|------|------|
| 重命名文件 | 根据产品信息重命名图片文件 |
| 创建物理文件夹 | 按产品分类创建物理文件夹并移动文件 |
| 更新 Alt 标签 | 自动生成并更新图片的 Alt 标签 |
| 同步到 FileBird 虚拟文件夹 | 同步到 FileBird 的虚拟文件夹系统 |

### 文件命名规则

**格式**: `{产品slug}-{图片类型}-{序号}.{扩展名}`

**示例**:
```
gem-series-35kw-type1-portable-ev-charger-main-1.jpg
gem-series-35kw-type1-portable-ev-charger-connector-1.jpg
gem-series-35kw-type1-portable-ev-charger-scene-1.jpg
```

### 图片类型映射

| 中文关键词 | 英文类型 | 说明 |
|-----------|---------|------|
| 主图、main、cover、首页 | main | 产品主图 |
| 枪头、枪、connector、插头 | connector | 充电枪头 |
| 场景、scene、环境、应用 | scene | 使用场景图 |
| 细节、detail、详情、特写 | detail | 产品细节图 |
| 包装、package、包装盒 | package | 产品包装图 |
| 安装、installation、安装图 | installation | 安装示意图 |

### 中文字段翻译

| 中文 | 英文 |
|------|------|
| 便携式充电桩 | portable-ev-charger |
| 美标 | type1 |
| 欧标 | type2 |
| 宝石系列 | gem-series |
| 枪头 | connector |
| 主图 | main |

## REST API

模块提供以下 REST API 端点：

### 预览更改
```
POST /wp-json/filebird-woo/v1/preview
```

**请求参数**:
```json
{
  "product_ids": [123, 456],
  "options": {
    "rename_files": true,
    "move_files": true,
    "update_alt": true,
    "sync_folders": true
  }
}
```

### 处理图片
```
POST /wp-json/filebird-woo/v1/process
```

**请求参数**: 同预览 API

**响应示例**:
```json
{
  "success": true,
  "total_success": 10,
  "total_failed": 0,
  "backup_keys": {
    "123": "1676123456"
  }
}
```

### 回滚操作
```
POST /wp-json/filebird-woo/v1/rollback
```

**请求参数**:
```json
{
  "backup_key": "1676123456"
}
```

### 获取历史记录
```
GET /wp-json/filebird-woo/v1/history
```

### 获取产品列表
```
GET /wp-json/filebird-woo/v1/products?page=1&per_page=20&category=slug
```

## 产品页面集成

在产品编辑页面添加了快捷处理按钮：

```
产品编辑页面 > 图片重命名 > 重命名此产品图片
```

## 代码示例

### 编程方式处理单个产品

```php
use FileBird\Classes\Modules\WooImageRename\ImageProcessor;

$processor = new ImageProcessor();
$result = $processor->processProduct($product_id, [
    'rename_files' => true,
    'move_files' => true,
    'update_alt' => true,
    'sync_folders' => true,
]);

if ($result['success']) {
    echo "成功处理 {$result['success_count']} 张图片";
    echo "备份键: {$result['backup_key']}";
}
```

### 预览更改

```php
$processor = new ImageProcessor();
$preview = $processor->previewChanges($product_id, [
    'rename_files' => true,
    'move_files' => true,
]);

print_r($preview['previews']);
```

### 回滚操作

```php
use FileBird\Classes\Modules\WooImageRename\RollbackManager;

$result = RollbackManager::restoreFromBackup($backup_key);
```

## 过滤器和钩子

### 可用过滤器

```php
// 自定义 Alt 标签格式
add_filter('fbv_woo_image_alt', function($alt_text, $product_id, $sequence) {
    $product = wc_get_product($product_id);
    return $product->get_title() . ' - Image ' . $sequence;
}, 10, 3);
```

## 技术架构

### 组件关系图

```
ModuleWooImageRename (主模块)
├── REST API 端点注册
├── 设置页面渲染
└── 产品页面按钮
    │
    └── ImageProcessor (处理引擎)
        ├── PinyinConverter (拼音转换)
        ├── FileRenamer (文件重命名)
        ├── FileMover (文件移动)
        ├── AltGenerator (Alt 标签)
        ├── FolderSync (文件夹同步)
        └── RollbackManager (备份回滚)
```

## 故障排除

### 模块未加载

1. 确认 WooCommerce 已启用
2. 检查 PHP 版本 >= 7.4
3. 查看 WordPress 调试日志

### 文件移动失败

1. 检查 uploads 目录写入权限
2. 确认磁盘空间充足
3. 查看备份记录获取错误详情

### 回滚失败

1. 备份保留 30 天，过期自动清理
2. 确认 backup_key 正确
3. 检查数据库中 wp_options 表的备份记录

## 安全注意事项

- 所有 REST API 端点需要 `manage_options` 权限
- 文件操作前自动创建备份
- 支持 CSRF 保护（WordPress nonce）

## 更新日志

### 1.0.0 (2025-02-10)
- 初始版本发布
- 支持图片自动重命名
- 支持物理文件夹管理
- 支持虚拟文件夹同步
- 支持备份与回滚
- 中文字段翻译功能

## 贡献

欢迎提交 Issue 和 Pull Request。

## 许可证

GPLv2 或更高版本

## 作者

由 Claude AI 协助开发

---

**注意**: 此模块需要 FileBird Pro 插件才能正常工作。
