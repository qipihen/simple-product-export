<?php
/*
Plugin Name: 产品导入导出工具
Plugin URI: https://github.com/yourusername/simple-product-export
Description: 导出/导入 产品、页面、文章 和分类 CSV，包含所有自定义字段，支持筛选导出
Version: 4.7.0
Author: zhangkun
License: GPL v2 or later
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'spe_add_admin_menu');

function spe_add_admin_menu()
{
    add_menu_page(
        '内容导入导出',
        '导入导出工具',
        'manage_options',
        'content-import-export',
        'spe_admin_page',
        'dashicons-migrate',
        30
    );
}

function spe_admin_page()
{
    if (isset($_GET['spe_action']) && $_GET['spe_action'] === 'export_products') {
        spe_export_products();
    }
    if (isset($_GET['spe_action']) && $_GET['spe_action'] === 'export_taxonomies') {
        $taxonomy = isset($_GET['spe_taxonomy']) ? sanitize_text_field($_GET['spe_taxonomy']) : 'product_cat';
        spe_export_taxonomies($taxonomy);
    }
    if (isset($_GET['spe_action']) && $_GET['spe_action'] === 'export_pages') {
        spe_export_pages();
    }
    if (isset($_GET['spe_action']) && $_GET['spe_action'] === 'export_posts') {
        spe_export_posts();
    }

    if (isset($_POST['spe_import_products']) && wp_verify_nonce($_POST['spe_import_products'], 'spe_import')) {
        $result = spe_import_products();
    }
    if (isset($_POST['spe_import_taxonomies']) && wp_verify_nonce($_POST['spe_import_taxonomies'], 'spe_import_tax')) {
        $taxonomy = isset($_POST['spe_taxonomy']) ? sanitize_text_field($_POST['spe_taxonomy']) : 'product_cat';
        $result = spe_import_taxonomies($taxonomy);
    }
    if (isset($_POST['spe_import_pages']) && wp_verify_nonce($_POST['spe_import_pages'], 'spe_import_pages')) {
        $result = spe_import_pages();
    }
    if (isset($_POST['spe_import_posts']) && wp_verify_nonce($_POST['spe_import_posts'], 'spe_import_posts')) {
        $result = spe_import_posts();
    }

    ?>
    <div class="wrap">
        <h1>📦 内容导入导出工具</h1>

        <?php if (isset($result)): ?>
            <div class="notice <?php echo $result['error'] ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                <p><?php echo esc_html($result['message']); ?></p>
            </div>
            <?php if (!empty($result['debug'])): ?>
                <div class="notice notice-info is-dismissible" style="margin-top: 10px;">
                    <p><strong>📋 调试信息：</strong></p>
                    <pre
                        style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 300px; overflow-y: auto; font-size: 12px; margin: 10px 0;"><?php echo esc_html($result['debug']); ?></pre>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <h2 style="margin-top: 20px;">🛍️ 产品 (Products)</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 10px;">
            <div class="card">
                <h2>📤 产品导出</h2>
                <p>导出产品到 CSV（支持筛选）</p>
                <ul>
                    <li>✅ 基础字段：ID、标题、Slug</li>
                    <li>✅ 描述：短描述、长描述</li>
                    <li>✅ AIOSEO Meta</li>
                    <li>✅ 所有 ACF/自定义字段</li>
                </ul>
                <form method="get" action="<?php echo admin_url('admin.php'); ?>" id="export-products-form">
                    <input type="hidden" name="page" value="content-import-export">
                    <input type="hidden" name="spe_action" value="export_products">

                    <button type="button" class="button"
                        onclick="var panel=document.getElementById('product-filter-panel');var btn=this;if(panel.style.display==='none'){panel.style.display='block';btn.textContent='收起筛选选项';}else{panel.style.display='none';btn.textContent='展开筛选选项';}">
                        展开筛选选项
                    </button>

                    <div id="product-filter-panel"
                        style="display:none; margin-top: 15px; padding: 15px; background: #f7f7f7; border: 1px solid #ddd; border-radius: 4px;">
                        <p style="margin-top:0;"><strong>分类筛选：</strong></p>
                        <select name="spe_categories[]" multiple size="5" style="width: 100%; margin-bottom: 10px;">
                            <?php
                            $product_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                            foreach ($product_cats as $cat) {
                                echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . ' (ID: ' . $cat->term_id . ')</option>';
                            }
                            ?>
                        </select>
                        <p style="font-size: 12px; color: #666; margin: 0 0 15px 0;">按住 Ctrl/Cmd 可多选</p>

                        <p><strong>关键词搜索：</strong></p>
                        <input type="text" name="spe_keyword" placeholder="搜索标题或内容..."
                            style="width: 100%; margin-bottom: 5px;">

                        <select name="spe_keyword_scope" style="width: 100%; margin-bottom: 10px;">
                            <option value="all">搜索范围：全部（标题+内容）</option>
                            <option value="title">搜索范围：仅标题</option>
                            <option value="content">搜索范围：仅内容</option>
                        </select>

                        <button type="button" class="button"
                            onclick="document.querySelector('#export-products-form select[name=\'spe_categories[]\']').selectedIndex=-1;document.querySelector('#export-products-form input[name=\'spe_keyword\']').value='';">
                            重置筛选
                        </button>
                    </div>

                    <p style="margin-top: 15px;">
                        <button type="submit" class="button button-primary button-large">
                            下载产品 CSV
                        </button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h2>📥 产品导入</h2>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('spe_import', 'spe_import_products'); ?>
                    <input type="file" name="spe_import_file" accept=".csv" required>
                    <button type="submit" class="button button-secondary" style="margin-top: 10px;">
                        上传产品 CSV
                    </button>
                </form>
            </div>
        </div>

        <h2 style="margin-top: 30px;">📄 页面 (Pages)</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 10px;">
            <div class="card">
                <h2>📤 页面导出</h2>
                <p>导出所有页面到 CSV</p>
                <ul>
                    <li>✅ 基础字段：ID、标题、Slug</li>
                    <li>✅ 内容、摘要</li>
                    <li>✅ AIOSEO Meta</li>
                    <li>✅ 所有 ACF/自定义字段</li>
                </ul>
                <a href="<?php echo admin_url('admin.php?page=content-import-export&spe_action=export_pages'); ?>"
                    class="button button-primary button-large">
                    下载页面 CSV
                </a>
            </div>

            <div class="card">
                <h2>📥 页面导入</h2>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('spe_import_pages', 'spe_import_pages'); ?>
                    <input type="file" name="spe_import_pages_file" accept=".csv" required>
                    <button type="submit" class="button button-secondary" style="margin-top: 10px;">
                        上传页面 CSV
                    </button>
                </form>
            </div>
        </div>

        <h2 style="margin-top: 30px;">📝 文章 (Posts)</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 10px;">
            <div class="card">
                <h2>📤 文章导出</h2>
                <p>导出文章到 CSV（支持筛选）</p>
                <ul>
                    <li>✅ 基础字段：ID、标题、Slug</li>
                    <li>✅ 内容、摘要</li>
                    <li>✅ AIOSEO Meta</li>
                    <li>✅ 所有 ACF/自定义字段</li>
                </ul>
                <form method="get" action="<?php echo admin_url('admin.php'); ?>" id="export-posts-form">
                    <input type="hidden" name="page" value="content-import-export">
                    <input type="hidden" name="spe_action" value="export_posts">

                    <button type="button" class="button"
                        onclick="var panel=document.getElementById('posts-filter-panel');var btn=this;if(panel.style.display==='none'){panel.style.display='block';btn.textContent='收起筛选选项';}else{panel.style.display='none';btn.textContent='展开筛选选项';}">
                        展开筛选选项
                    </button>

                    <div id="posts-filter-panel"
                        style="display:none; margin-top: 15px; padding: 15px; background: #f7f7f7; border: 1px solid #ddd; border-radius: 4px;">
                        <p style="margin-top:0;"><strong>分类筛选：</strong></p>
                        <select name="spe_categories[]" multiple size="5" style="width: 100%; margin-bottom: 10px;">
                            <?php
                            $post_cats = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
                            foreach ($post_cats as $cat) {
                                echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . ' (ID: ' . $cat->term_id . ')</option>';
                            }
                            ?>
                        </select>
                        <p style="font-size: 12px; color: #666; margin: 0 0 15px 0;">按住 Ctrl/Cmd 可多选</p>

                        <p><strong>关键词搜索：</strong></p>
                        <input type="text" name="spe_keyword" placeholder="搜索标题或内容..."
                            style="width: 100%; margin-bottom: 5px;">

                        <select name="spe_keyword_scope" style="width: 100%; margin-bottom: 10px;">
                            <option value="all">搜索范围：全部（标题+内容）</option>
                            <option value="title">搜索范围：仅标题</option>
                            <option value="content">搜索范围：仅内容</option>
                        </select>

                        <button type="button" class="button"
                            onclick="document.querySelector('#export-posts-form select[name=\'spe_categories[]\']').selectedIndex=-1;document.querySelector('#export-posts-form input[name=\'spe_keyword\']').value='';">
                            重置筛选
                        </button>
                    </div>

                    <p style="margin-top: 15px;">
                        <button type="submit" class="button button-primary button-large">
                            下载文章 CSV
                        </button>
                    </p>
                </form>
            </div>

            <div class="card">
                <h2>📥 文章导入</h2>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('spe_import_posts', 'spe_import_posts'); ?>
                    <input type="file" name="spe_import_posts_file" accept=".csv" required>
                    <button type="submit" class="button button-secondary" style="margin-top: 10px;">
                        上传文章 CSV
                    </button>
                </form>
            </div>
        </div>

        <h2 style="margin-top: 30px;">📂 分类/自定义分类法 (Taxonomies)</h2>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 10px;">
            <div class="card">
                <h2>📤 分类导出</h2>
                <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                    <input type="hidden" name="page" value="content-import-export">
                    <input type="hidden" name="spe_action" value="export_taxonomies">

                    <p>选择要导出的分类法：</p>
                    <select name="spe_taxonomy" style="width: 100%; margin-bottom: 10px;">
                        <?php
                        $taxonomies = get_taxonomies(['public' => true], 'objects');
                        foreach ($taxonomies as $tax) {
                            $selected = ($tax->name === 'product_cat') ? 'selected' : '';
                            echo '<option value="' . esc_attr($tax->name) . '" ' . $selected . '>' . esc_html($tax->label) . ' (' . $tax->name . ')</option>';
                        }
                        ?>
                    </select>

                    <button type="submit" class="button button-primary button-large">
                        下载分类 CSV
                    </button>
                </form>
            </div>

            <div class="card">
                <h2>📥 分类导入</h2>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('spe_import_tax', 'spe_import_taxonomies'); ?>

                    <p>选择要导入的目标分类法：</p>
                    <select name="spe_taxonomy" style="width: 100%; margin-bottom: 10px;">
                        <?php
                        foreach ($taxonomies as $tax) {
                            $selected = ($tax->name === 'product_cat') ? 'selected' : '';
                            echo '<option value="' . esc_attr($tax->name) . '" ' . $selected . '>' . esc_html($tax->label) . ' (' . $tax->name . ')</option>';
                        }
                        ?>
                    </select>

                    <input type="file" name="spe_import_taxonomy_file" accept=".csv" required>
                    <button type="submit" class="button button-secondary" style="margin-top: 10px;">
                        上传分类 CSV
                    </button>
                </form>
            </div>
        </div>

        <div class="card" style="margin-top: 30px;">
            <h2>💡 使用说明</h2>
            <ul>
                <li><strong>URL 更新</strong>: 导入后如果修改了 Slug 列，页面/文章的 URL 会自动更新</li>
                <li><strong>ID 匹配</strong>: 导入时根据 ID 列匹配现有内容进行更新</li>
                <li><strong>自定义字段</strong>: 所有自定义字段（包括 ACF）都会被导出和导入</li>
                <li><strong>附件字段</strong>: 附件类型的字段会额外生成 _url 列显示图片链接</li>
                <li><strong>筛选导出</strong>: 点击"展开筛选选项"可按分类和关键词筛选后导出</li>
            </ul>
        </div>
    </div>
    <?php
}

/**
 * 导出产品
 */
function spe_export_products()
{
    if (!current_user_can('manage_options'))
        wp_die('没有权限');

    // 获取筛选参数
    $filter_categories = isset($_GET['spe_categories']) ? array_map('intval', (array) $_GET['spe_categories']) : [];
    $filter_keyword = isset($_GET['spe_keyword']) ? sanitize_text_field($_GET['spe_keyword']) : '';
    $filter_keyword_scope = isset($_GET['spe_keyword_scope']) ? sanitize_text_field($_GET['spe_keyword_scope']) : 'all';

    // 构建文件名后缀
    $suffix = '';
    if (!empty($filter_categories)) {
        $suffix .= '-cat' . count($filter_categories);
    }
    if (!empty($filter_keyword)) {
        $suffix .= '-search';
    }

    while (ob_get_level())
        ob_end_clean();
    set_time_limit(0);

    $filename = 'products-export' . $suffix . '-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    global $wpdb;

    // 构建查询条件
    $where_conditions = ["p.post_type = 'product'", "p.post_status IN ('publish', 'draft', 'private')"];

    // 分类筛选
    if (!empty($filter_categories)) {
        $cat_ids = implode(',', $filter_categories);
        $where_conditions[] = "
            p.ID IN (
                SELECT object_id FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.term_id IN ($cat_ids)
            )
        ";
    }

    // 关键词筛选
    if (!empty($filter_keyword)) {
        $keyword_like = '%' . $wpdb->esc_like($filter_keyword) . '%';
        switch ($filter_keyword_scope) {
            case 'title':
                $where_conditions[] = $wpdb->prepare("p.post_title LIKE %s", $keyword_like);
                break;
            case 'content':
                $where_conditions[] = $wpdb->prepare("p.post_content LIKE %s", $keyword_like);
                break;
            case 'all':
            default:
                $where_conditions[] = $wpdb->prepare("(p.post_title LIKE %s OR p.post_content LIKE %s)", $keyword_like, $keyword_like);
                break;
        }
    }

    $where_clause = implode(' AND ', $where_conditions);

    // 获取产品
    $products = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_name, p.post_excerpt, p.post_content
        FROM {$wpdb->posts} p
        WHERE $where_clause
        ORDER BY p.ID
    ");

    // 检查空结果
    if (empty($products)) {
        if (!empty($filter_categories) || !empty($filter_keyword)) {
            fputcsv($output, ['提示：没有找到符合筛选条件的产品']);
            fputcsv($output, ['筛选条件：']);
            if (!empty($filter_categories)) {
                $cat_names = [];
                foreach ($filter_categories as $cat_id) {
                    $term = get_term($cat_id, 'product_cat');
                    if ($term && !is_wp_error($term)) {
                        $cat_names[] = $term->name;
                    }
                }
                fputcsv($output, ['分类: ' . implode(', ', $cat_names)]);
            }
            if (!empty($filter_keyword)) {
                fputcsv($output, ['关键词: ' . $filter_keyword]);
            }
        } else {
            fputcsv($output, ['ID', '标题', 'Slug', '短描述', '长描述']);
        }
        fclose($output);
        exit;
    }

    if (empty($products)) {
        fputcsv($output, ['ID', '标题', 'Slug', '短描述', '长描述']);
        fclose($output);
        exit;
    }

    // 扫描第一个产品获取所有 meta keys
    $first_id = $products[0]->ID;
    $all_meta_keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} 
        WHERE post_id = %d 
        AND meta_key NOT LIKE '\_%'
        ORDER BY meta_key",
        $first_id
    ));

    // 排除 WordPress 内部字段
    $exclude_keys = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_thumbnail_id',
        '_product_image_gallery',
        '_product_version',
        '_wp_page_template',
        '_stock',
        '_stock_status',
        '_manage_stock',
        '_backorders',
        '_sold_individually',
        '_regular_price',
        '_sale_price',
        '_price',
        '_wc_average_rating',
        '_wc_review_count',
        '_product_attributes',
        '_default_attributes',
        '_variation_description',
        '_sku',
        '_downloadable_files',
        '_download_limit',
        '_download_expiry',
        '_purchase_note',
        '_virtual',
        '_downloadable',
        '_weight',
        '_length',
        '_width',
        '_height',
        '_children',
        '_featured',
        'total_sales'
    ];

    $custom_fields = array_values(array_diff($all_meta_keys, $exclude_keys));
    sort($custom_fields);

    // 基础字段
    $header = ['ID', '标题', 'Slug', '短描述', '长描述'];

    // AIOSEO 字段
    $header[] = 'Meta Title';
    $header[] = 'Meta Description';

    // 所有自定义字段
    foreach ($custom_fields as $field) {
        $header[] = $field;
    }

    // 检测哪些字段是附件类型，添加 URL 辅助列
    $attachment_fields = [];
    foreach ($products as $p) {
        foreach ($custom_fields as $field) {
            if (in_array($field, $attachment_fields))
                continue;
            $value = get_post_meta($p->ID, $field, true);
            if (is_numeric($value) && $value > 0 && wp_get_attachment_url($value)) {
                $attachment_fields[] = $field;
            }
        }
    }

    // 为附件字段添加 URL 列标题
    foreach ($custom_fields as $field) {
        if (in_array($field, $attachment_fields)) {
            $header[] = $field . '_url';
        }
    }

    fputcsv($output, $header);

    // 数据行
    foreach ($products as $p) {
        $id = $p->ID;

        // 短描述和长描述
        $short_desc = $p->post_excerpt ?: '';
        $long_desc = $p->post_content ?: '';

        // 清理换行
        $short_desc = str_replace(["\r\n", "\n", "\r"], ' ', $short_desc);
        $long_desc = str_replace(["\r\n", "\n", "\r"], ' ', $long_desc);

        // AIOSEO
        $meta_title = '';
        $meta_desc = '';

        // 尝试多种方式获取 Meta Title
        $aioseo_title = get_post_meta($id, '_aioseo_title', true);
        if (is_array($aioseo_title)) {
            $meta_title = $aioseo_title['title'] ?? '';
        } elseif (is_string($aioseo_title)) {
            $meta_title = $aioseo_title;
        }
        if (empty($meta_title)) {
            $meta_title = get_post_meta($id, '_aioseop_title', true);
        }

        // 尝试多种方式获取 Meta Description
        $aioseo_desc = get_post_meta($id, '_aioseo_description', true);
        if (is_array($aioseo_desc)) {
            $meta_desc = $aioseo_desc['description'] ?? '';
        } elseif (is_string($aioseo_desc)) {
            $meta_desc = $aioseo_desc;
        }
        if (empty($meta_desc)) {
            $meta_desc = get_post_meta($id, '_aioseop_description', true);
        }

        $row = [$id, $p->post_title, $p->post_name, $short_desc, $long_desc, $meta_title, $meta_desc];

        // 自定义字段值
        foreach ($custom_fields as $field) {
            $value = get_post_meta($id, $field, true);

            // 处理数组
            if (is_array($value)) {
                if (isset($value['url'])) {
                    $value = $value['url'];
                } elseif (isset($value['ID'])) {
                    $value = $value['ID'];
                } elseif (isset($value[0]) && is_array($value[0])) {
                    // 多选或 Repeater
                    $values = [];
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $values[] = isset($item['label']) ? $item['label'] : (isset($item['name']) ? $item['name'] : json_encode($item));
                        } else {
                            $values[] = $item;
                        }
                    }
                    $value = implode(', ', $values);
                } else {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }

            // 清理换行
            $value = str_replace(["\r\n", "\n", "\r"], ' ', $value);
            $row[] = $value;

            // 如果是附件 ID，添加 URL 辅助列
            if (is_numeric($value) && $value > 0) {
                $url = wp_get_attachment_url($value);
                if ($url) {
                    $row[] = $url;
                }
            }
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/**
 * 导出分类（支持自定义分类法）
 */
function spe_export_taxonomies($taxonomy = 'product_cat')
{
    if (!current_user_can('manage_options'))
        wp_die('没有权限');

    while (ob_get_level())
        ob_end_clean();
    set_time_limit(0);

    $filename = $taxonomy . '-export-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    global $wpdb;

    // 获取所有分类
    $categories = $wpdb->get_results($wpdb->prepare("
        SELECT t.term_id, t.name, t.slug, tt.description, tt.parent
        FROM {$wpdb->terms} t
        INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy = %s
        ORDER BY t.term_id
    ", $taxonomy));

    if (empty($categories)) {
        fputcsv($output, ['ID', '标题', 'Slug', '描述', '父分类 ID', 'Meta Title', 'Meta Description']);
        fclose($output);
        exit;
    }

    // 扫描第一个分类的 meta
    $first_id = $categories[0]->term_id;
    $all_meta_keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT meta_key FROM {$wpdb->termmeta} 
        WHERE term_id = %d 
        AND meta_key NOT LIKE '\_%'
        ORDER BY meta_key",
        $first_id
    ));

    $exclude_keys = ['_product_count', '_thumbnail_id'];
    $custom_fields = array_values(array_diff($all_meta_keys, $exclude_keys));
    sort($custom_fields);

    $header = ['ID', '标题', 'Slug', '描述', '父分类 ID'];

    // AIOSEO 字段
    $header[] = 'Meta Title';
    $header[] = 'Meta Description';

    foreach ($custom_fields as $field) {
        $header[] = $field;
    }

    // 检测哪些字段是附件类型，添加 URL 辅助列
    $attachment_fields = [];
    foreach ($categories as $cat) {
        foreach ($custom_fields as $field) {
            if (in_array($field, $attachment_fields))
                continue;
            $value = get_term_meta($cat->term_id, $field, true);
            if (is_numeric($value) && $value > 0 && wp_get_attachment_url($value)) {
                $attachment_fields[] = $field;
            }
        }
    }

    // 为附件字段添加 URL 列标题
    foreach ($custom_fields as $field) {
        if (in_array($field, $attachment_fields)) {
            $header[] = $field . '_url';
        }
    }

    fputcsv($output, $header);

    foreach ($categories as $cat) {
        $id = $cat->term_id;

        // AIOSEO - 分类使用 termmeta
        $meta_title = '';
        $meta_desc = '';

        // 尝试多种方式获取 Meta Title
        $aioseo_title = get_term_meta($id, '_aioseo_title', true);
        if (is_array($aioseo_title)) {
            $meta_title = $aioseo_title['title'] ?? '';
        } elseif (is_string($aioseo_title)) {
            $meta_title = $aioseo_title;
        }
        if (empty($meta_title)) {
            $meta_title = get_term_meta($id, '_aioseop_title', true);
        }

        // 尝试多种方式获取 Meta Description
        $aioseo_desc = get_term_meta($id, '_aioseo_description', true);
        if (is_array($aioseo_desc)) {
            $meta_desc = $aioseo_desc['description'] ?? '';
        } elseif (is_string($aioseo_desc)) {
            $meta_desc = $aioseo_desc;
        }
        if (empty($meta_desc)) {
            $meta_desc = get_term_meta($id, '_aioseop_description', true);
        }

        $row = [
            $id,
            $cat->name,
            $cat->slug,
            str_replace(["\r\n", "\n", "\r"], ' ', $cat->description),
            $cat->parent ?: '',
            $meta_title,
            $meta_desc
        ];

        foreach ($custom_fields as $field) {
            $value = get_term_meta($cat->term_id, $field, true);
            if (is_array($value)) {
                if (isset($value['url'])) {
                    $value = $value['url'];
                } elseif (isset($value['ID'])) {
                    $value = $value['ID'];
                } else {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }
            $value = str_replace(["\r\n", "\n", "\r"], ' ', $value);
            $row[] = $value;

            // 如果是附件 ID，添加 URL 辅助列
            if (is_numeric($value) && $value > 0) {
                $url = wp_get_attachment_url($value);
                if ($url) {
                    $row[] = $url;
                }
            }
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/**
 * 导入产品
 */
function spe_import_products()
{
    if (empty($_FILES['spe_import_file']['tmp_name'])) {
        return ['error' => true, 'message' => '请选择 CSV 文件'];
    }

    $file = $_FILES['spe_import_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle)
        return ['error' => true, 'message' => '无法读取文件'];

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['error' => true, 'message' => 'CSV 文件为空或格式错误'];
    }

    $id_col = array_search('ID', $header);
    $title_col = array_search('标题', $header);
    if ($title_col === false)
        $title_col = array_search('Title', $header);

    $slug_col = array_search('Slug', $header);

    $short_desc_col = array_search('短描述', $header);
    if ($short_desc_col === false)
        $short_desc_col = array_search('Short Description', $header);

    $long_desc_col = array_search('长描述', $header);
    if ($long_desc_col === false)
        $long_desc_col = array_search('Long Description', $header);
    $meta_title_col = array_search('Meta Title', $header);
    $meta_desc_col = array_search('Meta Description', $header);

    $custom_cols = [];
    foreach ($header as $idx => $col_name) {
        // 排除所有已知的标准列（中英文）
        if (!in_array($col_name, ['ID', '标题', 'Title', 'Slug', '短描述', 'Short Description', '长描述', 'Long Description', 'Meta Title', 'Meta Description'])) {
            $custom_cols[$idx] = $col_name;
        }
    }

    $updated = 0;
    $not_found = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $product_id = ($id_col !== false) ? intval($row[$id_col]) : 0;
        if (!$product_id)
            continue;

        $product = wc_get_product($product_id);
        if (!$product) {
            $not_found++;
            continue;
        }

        // 更新基础字段
        $update_data = [];
        if ($title_col !== false && !empty($row[$title_col])) {
            $update_data['post_title'] = $row[$title_col];
        }
        if ($slug_col !== false && !empty($row[$slug_col])) {
            $update_data['post_name'] = sanitize_title($row[$slug_col]);
        }
        if ($short_desc_col !== false) {
            $update_data['post_excerpt'] = $row[$short_desc_col];
        }
        if ($long_desc_col !== false) {
            $update_data['post_content'] = $row[$long_desc_col];
        }

        if (!empty($update_data)) {
            $update_data['ID'] = $product_id;
            wp_update_post($update_data);
        }

        // AIOSEO - 支持多种存储方式
        if ($meta_title_col !== false && $row[$meta_title_col] !== '') {
            $meta_title = $row[$meta_title_col];

            // 方式1: aioseo_posts 表 (AIOSEO 4.x)
            global $wpdb;
            $aioseo_table = $wpdb->prefix . 'aioseo_posts';
            if ($wpdb->get_var("SHOW TABLES LIKE '$aioseo_table'") == $aioseo_table) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $aioseo_table WHERE post_id = %d",
                    $product_id
                ));

                if ($existing) {
                    $wpdb->update(
                        $aioseo_table,
                        ['title' => $meta_title],
                        ['post_id' => $product_id],
                        ['%s'],
                        ['%d']
                    );
                } else {
                    $wpdb->insert(
                        $aioseo_table,
                        ['post_id' => $product_id, 'title' => $meta_title],
                        ['%d', '%s']
                    );
                }
            }

            // 方式2: postmeta (兼容其他版本)
            update_post_meta($product_id, '_aioseo_title', $meta_title);
            update_post_meta($product_id, '_aioseop_title', $meta_title);
        }

        if ($meta_desc_col !== false && $row[$meta_desc_col] !== '') {
            $meta_desc = $row[$meta_desc_col];

            // 方式1: aioseo_posts 表 (AIOSEO 4.x)
            global $wpdb;
            $aioseo_table = $wpdb->prefix . 'aioseo_posts';
            if ($wpdb->get_var("SHOW TABLES LIKE '$aioseo_table'") == $aioseo_table) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $aioseo_table WHERE post_id = %d",
                    $product_id
                ));

                if ($existing) {
                    $wpdb->update(
                        $aioseo_table,
                        ['description' => $meta_desc],
                        ['post_id' => $product_id],
                        ['%s'],
                        ['%d']
                    );
                } else {
                    $wpdb->insert(
                        $aioseo_table,
                        ['post_id' => $product_id, 'description' => $meta_desc],
                        ['%d', '%s']
                    );
                }
            }

            // 方式2: postmeta (兼容其他版本)
            update_post_meta($product_id, '_aioseo_description', $meta_desc);
            update_post_meta($product_id, '_aioseop_description', $meta_desc);
        }

        // 自定义字段
        foreach ($custom_cols as $idx => $field_name) {
            $value = $row[$idx] ?? '';
            if ($value !== '') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
                update_post_meta($product_id, $field_name, $value);
            }
        }

        $updated++;
    }

    fclose($handle);

    $msg = "产品导入完成！更新了 {$updated} 个产品";
    if ($not_found > 0)
        $msg .= "，{$not_found} 个 ID 未找到";
    return ['error' => false, 'message' => $msg, 'debug' => ''];
}

/**
 * 导入分类（支持自定义分类法）
 */
function spe_import_taxonomies($taxonomy = 'product_cat')
{
    if (empty($_FILES['spe_import_taxonomy_file']['tmp_name'])) {
        return ['error' => true, 'message' => '请选择 CSV 文件'];
    }

    $file = $_FILES['spe_import_taxonomy_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle)
        return ['error' => true, 'message' => '无法读取文件'];

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['error' => true, 'message' => 'CSV 文件为空或格式错误'];
    }

    // 标准化表头为小写，用于不区分大小写匹配
    $header_lower = array_map('strtolower', $header);

    // 匹配ID列（支持多种格式）
    $id_col = false;
    foreach ($header as $idx => $col) {
        if (strtolower($col) === 'id' || strtolower($col) === 'term_id') {
            $id_col = $idx;
            break;
        }
    }

    // 匹配名称列（支持多种格式）
    $name_col = array_search('标题', $header);
    if ($name_col === false)
        $name_col = array_search('名称', $header);
    if ($name_col === false)
        $name_col = array_search('name', $header_lower);

    // 匹配slug列
    $slug_col = array_search('Slug', $header);
    if ($slug_col === false)
        $slug_col = array_search('slug', $header_lower);

    // 匹配描述列（AIOSEO用description作为SEO描述，不是分类描述）
    $desc_col = array_search('描述', $header);
    if ($desc_col === false) {
        // AIOSEO格式中没有分类描述，description是SEO描述
        $desc_col = false;
    }

    // 匹配父分类ID列
    $parent_col = array_search('父分类 ID', $header);
    if ($parent_col === false)
        $parent_col = array_search('parent', $header_lower);

    // 匹配SEO标题列（AIOSEO用title列）
    $meta_title_col = array_search('Meta Title', $header);
    if ($meta_title_col === false)
        $meta_title_col = array_search('title', $header_lower);

    // 匹配SEO描述列（AIOSEO用description列）
    $meta_desc_col = array_search('Meta Description', $header);
    if ($meta_desc_col === false)
        $meta_desc_col = array_search('description', $header_lower);

    $custom_cols = [];
    foreach ($header as $idx => $col_name) {
        $col_lower = strtolower($col_name);
        // 排除已知的标准列
        if (!in_array($col_lower, ['id', 'term_id', '标题', '名称', 'name', 'slug', '描述', 'parent', '父分类 id', 'meta title', 'meta description', 'title', 'description'])) {
            // 跳过 _url 结尾的列
            if (substr($col_name, -4) !== '_url') {
                $custom_cols[$idx] = $col_name;
            }
        }
    }

    $updated = 0;
    $not_found = 0;
    $no_changes = 0;
    $processed = 0; // 实际处理的行数
    $errors = []; // 记录错误信息
    $row_count = 0; // 记录读取的行数

    // 记录调试信息到文件，方便排查
    $debug_log = "分类导入开始 - " . date('Y-m-d H:i:s') . "\n";
    $debug_log .= "找到的列: " . implode(', ', $header) . "\n";
    $debug_log .= "ID列索引: " . ($id_col !== false ? $id_col : '无') . "\n";
    $debug_log .= "名称列索引: " . ($name_col !== false ? $name_col : '无') . "\n";
    $debug_log .= "Slug列索引: " . ($slug_col !== false ? $slug_col : '无') . "\n";
    $debug_log .= "文件大小: " . filesize($file) . " 字节\n";

    // 先读取所有行进行统计
    $all_rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $all_rows[] = $row;
    }
    $debug_log .= "CSV总行数(含表头): " . count($all_rows) . "\n";
    $debug_log .= "数据行数: " . max(0, count($all_rows) - 1) . "\n";

    // 重新处理每一行数据
    foreach ($all_rows as $row_index => $row) {
        $row_count++;

        // 跳过第一行（表头）
        if ($row_index === 0) {
            $debug_log .= "跳过表头行\n";
            continue;
        }

        $debug_log .= "\n--- 处理第 $row_count 行 ---\n";
        $debug_log .= "行数组: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";

        $cat_id = '';
        if ($id_col !== false && isset($row[$id_col])) {
            $cat_id = trim($row[$id_col]);
        }
        $debug_log .= "原始ID值: '" . $cat_id . "' (长度: " . strlen($cat_id) . ", ID列索引: " . ($id_col !== false ? $id_col : '无') . ")\n";

        // 尝试提取数字ID
        $cat_id = preg_replace('/[^0-9]/', '', $cat_id);
        $cat_id = intval($cat_id);

        $debug_log .= "提取的ID: $cat_id\n";

        if (!$cat_id) {
            $debug_log .= "跳过: ID为空或无效\n";
            continue;
        }

        $cat = get_term($cat_id, $taxonomy);
        if (!$cat || is_wp_error($cat)) {
            $debug_log .= "跳过: ID $cat_id 未找到\n";
            $not_found++;
            continue;
        }

        $processed++; // 实际处理了这个分类
        $update_data = [];
        $debug_info = "ID $cat_id: ";
        $row_debug = "行数据: " . implode(' | ', $row) . "\n";

        // 名称更新（只要列存在且值设置了就更新，包括空字符串）
        if ($name_col !== false && isset($row[$name_col])) {
            $old_name = $cat->name;
            $new_name = $row[$name_col];
            $row_debug .= "  名称检查: 旧='{$old_name}' 新='{$new_name}' 长度=" . strlen($new_name) . "\n";
            if ($old_name !== $new_name) {
                $update_data['name'] = $new_name;
                $debug_info .= "名称 '$old_name' -> '$new_name'; ";
            }
        }

        // Slug更新 - 支持带斜杠的路径格式
        if ($slug_col !== false && isset($row[$slug_col])) {
            $old_slug = $cat->slug;
            $new_slug_raw = $row[$slug_col];

            // 如果 slug 包含斜杠，提取最后一部分
            if (strpos($new_slug_raw, '/') !== false) {
                $slug_parts = explode('/', $new_slug_raw);
                $new_slug = end($slug_parts);
                $debug_info .= "检测到路径格式 '$new_slug_raw'，提取最后一部分: '$new_slug'; ";
            } else {
                $new_slug = $new_slug_raw;
            }

            $new_slug = sanitize_title($new_slug);
            $row_debug .= "  Slug检查: 旧='{$old_slug}' 新='{$new_slug}' (原始='{$new_slug_raw}')\n";

            if ($old_slug !== $new_slug) {
                $update_data['slug'] = $new_slug;
                $debug_info .= "Slug '$old_slug' -> '$new_slug'; ";
            }
        }

        // 描述更新
        if ($desc_col !== false && isset($row[$desc_col])) {
            $old_desc = $cat->description;
            $new_desc = $row[$desc_col];
            if ($old_desc !== $new_desc) {
                $update_data['description'] = $new_desc;
                $debug_info .= "描述已修改; ";
            }
        }

        if (!empty($update_data)) {
            $result = wp_update_term($cat_id, $taxonomy, $update_data);
            if (is_wp_error($result)) {
                $error_msg = "ID $cat_id: " . $result->get_error_message();
                $errors[] = $error_msg;
                $debug_log .= "错误: $error_msg\n";
            } else {
                $debug_log .= "更新成功: $debug_info\n";
                $updated++;
            }
        } else {
            // 没有变化
            $debug_log .= "无变化: ID $cat_id\n";
            $no_changes++;
        }

        // 父分类单独处理
        if ($parent_col !== false && isset($row[$parent_col])) {
            $parent_id = !empty($row[$parent_col]) ? intval($row[$parent_col]) : 0;
            wp_update_term($cat_id, $taxonomy, ['parent' => $parent_id]);
            $debug_log .= "  父分类设置为: $parent_id\n";
        }

        // AIOSEO Meta Title - 支持多种格式
        if ($meta_title_col !== false && isset($row[$meta_title_col]) && $row[$meta_title_col] !== '') {
            $meta_title_value = $row[$meta_title_col];

            // 先检查现有的 meta 数据
            $existing_aioseo = get_term_meta($cat_id);
            $debug_log .= "  现有 term_meta keys: " . implode(', ', array_keys($existing_aioseo)) . "\n";

            // 尝试所有可能的 AIOSEO 格式
            // 格式1: aioseo_term 数组格式（AIOSEO 4.0+）
            $aioseo_term = get_term_meta($cat_id, 'aioseo_term', true);
            if (!is_array($aioseo_term)) {
                $aioseo_term = [];
            }
            $aioseo_term['title'] = $meta_title_value;
            update_term_meta($cat_id, 'aioseo_term', $aioseo_term);
            $debug_log .= "  存储 aioseo_term: " . json_encode($aioseo_term) . "\n";

            // 格式2: _aioseo_title 数组格式
            update_term_meta($cat_id, '_aioseo_title', ['title' => $meta_title_value]);
            $debug_log .= "  存储 _aioseo_title 数组: " . json_encode(['title' => $meta_title_value]) . "\n";

            // 格式3: _aioseo_title 字符串格式（旧版兼容）
            update_term_meta($cat_id, '_aioseo_title', $meta_title_value);
            $debug_log .= "  存储 _aioseo_title 字符串: $meta_title_value\n";

            // 格式4: _aioseop_title 字符串格式（旧版）
            update_term_meta($cat_id, '_aioseop_title', $meta_title_value);

            // 验证存储
            $verify_aioseo = get_term_meta($cat_id, 'aioseo_term', true);
            $debug_log .= "  验证 aioseo_term: " . json_encode($verify_aioseo) . "\n";

            $debug_log .= "  更新 Meta Title: {$row[$meta_title_col]}\n";
        }

        // AIOSEO Meta Description - 支持多种格式
        if ($meta_desc_col !== false && isset($row[$meta_desc_col]) && $row[$meta_desc_col] !== '') {
            $meta_desc_value = $row[$meta_desc_col];

            // 格式1: aioseo_term 数组格式（AIOSEO 4.0+）
            $aioseo_term = get_term_meta($cat_id, 'aioseo_term', true);
            if (!is_array($aioseo_term)) {
                $aioseo_term = [];
            }
            $aioseo_term['description'] = $meta_desc_value;
            update_term_meta($cat_id, 'aioseo_term', $aioseo_term);

            // 格式2: _aioseo_description 数组格式
            update_term_meta($cat_id, '_aioseo_description', ['description' => $meta_desc_value]);

            // 格式3: _aioseo_description 字符串格式（旧版兼容）
            update_term_meta($cat_id, '_aioseo_description', $meta_desc_value);

            // 格式4: _aioseop_description 字符串格式（旧版）
            update_term_meta($cat_id, '_aioseop_description', $meta_desc_value);

            $debug_log .= "  更新 Meta Description: {$row[$meta_desc_col]}\n";
        }

        // 自定义字段
        foreach ($custom_cols as $idx => $field_name) {
            $value = $row[$idx] ?? '';
            if ($value !== '') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
                update_term_meta($cat_id, $field_name, $value);
                $debug_log .= "  更新自定义字段 $field_name\n";
            }
        }

        $debug_log .= $row_debug . "\n";
    }

    // 完成调试日志
    $debug_log .= "\n导入完成 - 处理: $processed, 更新: $updated, 无变化: $no_changes, 未找到: $not_found\n";

    fclose($handle);

    // 刷新 permalink 结构，使 slug 更改生效
    flush_rewrite_rules();
    $debug_log .= "已刷新 permalink 结构\n";

    // 构建详细消息
    $msg = "分类导入完成！共处理 {$processed} 个分类";
    if ($updated > 0)
        $msg .= "（其中 {$updated} 个有更新）";
    if ($no_changes > 0)
        $msg .= "，{$no_changes} 个无变化";
    if ($not_found > 0)
        $msg .= "，{$not_found} 个 ID 未找到";
    if (!empty($errors)) {
        $msg .= "。错误: " . implode('; ', array_slice($errors, 0, 3));
        if (count($errors) > 3)
            $msg .= " 等";
    }
    $msg .= "。已刷新 permalink 结构。";

    // 返回调试信息以便在页面上显示
    return ['error' => false, 'message' => $msg, 'debug' => $debug_log];
}

/**
 * 导出页面
 */
function spe_export_pages()
{
    if (!current_user_can('manage_options'))
        wp_die('没有权限');

    while (ob_get_level())
        ob_end_clean();
    set_time_limit(0);

    $filename = 'pages-export-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    global $wpdb;

    // 获取所有页面
    $pages = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_name, p.post_excerpt, p.post_content
        FROM {$wpdb->posts} p
        WHERE p.post_type = 'page'
        AND p.post_status IN ('publish', 'draft', 'private')
        ORDER BY p.ID
    ");

    if (empty($pages)) {
        fputcsv($output, ['ID', '标题', 'Slug', '摘要', '内容']);
        fclose($output);
        exit;
    }

    // 扫描第一个页面获取所有 meta keys
    $first_id = $pages[0]->ID;
    $all_meta_keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
        WHERE post_id = %d
        AND meta_key NOT LIKE '\_%'
        ORDER BY meta_key",
        $first_id
    ));

    // 排除 WordPress 内部字段
    $exclude_keys = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_thumbnail_id',
        '_wp_page_template',
    ];

    $custom_fields = array_values(array_diff($all_meta_keys, $exclude_keys));
    sort($custom_fields);

    // 基础字段
    $header = ['ID', '标题', 'Slug', '摘要', '内容'];

    // AIOSEO 字段
    $header[] = 'Meta Title';
    $header[] = 'Meta Description';

    // 所有自定义字段
    foreach ($custom_fields as $field) {
        $header[] = $field;
    }

    // 检测哪些字段是附件类型，添加 URL 辅助列
    $attachment_fields = [];
    foreach ($pages as $p) {
        foreach ($custom_fields as $field) {
            if (in_array($field, $attachment_fields))
                continue;
            $value = get_post_meta($p->ID, $field, true);
            if (is_numeric($value) && $value > 0 && wp_get_attachment_url($value)) {
                $attachment_fields[] = $field;
            }
        }
    }

    // 为附件字段添加 URL 列标题
    foreach ($custom_fields as $field) {
        if (in_array($field, $attachment_fields)) {
            $header[] = $field . '_url';
        }
    }

    fputcsv($output, $header);

    // 数据行
    foreach ($pages as $p) {
        $id = $p->ID;

        // 摘要和内容
        $excerpt = $p->post_excerpt ?: '';
        $content = $p->post_content ?: '';

        // 清理换行
        $excerpt = str_replace(["\r\n", "\n", "\r"], ' ', $excerpt);
        $content = str_replace(["\r\n", "\n", "\r"], ' ', $content);

        // AIOSEO
        $meta_title = '';
        $meta_desc = '';

        // 尝试多种方式获取 Meta Title
        $aioseo_title = get_post_meta($id, '_aioseo_title', true);
        if (is_array($aioseo_title)) {
            $meta_title = $aioseo_title['title'] ?? '';
        } elseif (is_string($aioseo_title)) {
            $meta_title = $aioseo_title;
        }
        if (empty($meta_title)) {
            $meta_title = get_post_meta($id, '_aioseop_title', true);
        }

        // 尝试多种方式获取 Meta Description
        $aioseo_desc = get_post_meta($id, '_aioseo_description', true);
        if (is_array($aioseo_desc)) {
            $meta_desc = $aioseo_desc['description'] ?? '';
        } elseif (is_string($aioseo_desc)) {
            $meta_desc = $aioseo_desc;
        }
        if (empty($meta_desc)) {
            $meta_desc = get_post_meta($id, '_aioseop_description', true);
        }

        $row = [$id, $p->post_title, $p->post_name, $excerpt, $content, $meta_title, $meta_desc];

        // 自定义字段值
        foreach ($custom_fields as $field) {
            $value = get_post_meta($id, $field, true);

            // 处理数组
            if (is_array($value)) {
                if (isset($value['url'])) {
                    $value = $value['url'];
                } elseif (isset($value['ID'])) {
                    $value = $value['ID'];
                } elseif (isset($value[0]) && is_array($value[0])) {
                    // 多选或 Repeater
                    $values = [];
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $values[] = isset($item['label']) ? $item['label'] : (isset($item['name']) ? $item['name'] : json_encode($item));
                        } else {
                            $values[] = $item;
                        }
                    }
                    $value = implode(', ', $values);
                } else {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }

            // 清理换行
            $value = str_replace(["\r\n", "\n", "\r"], ' ', $value);
            $row[] = $value;

            // 如果是附件 ID，添加 URL 辅助列
            if (is_numeric($value) && $value > 0) {
                $url = wp_get_attachment_url($value);
                if ($url) {
                    $row[] = $url;
                }
            }
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/**
 * 导出文章
 */
function spe_export_posts()
{
    if (!current_user_can('manage_options'))
        wp_die('没有权限');

    // 获取筛选参数
    $filter_categories = isset($_GET['spe_categories']) ? array_map('intval', (array) $_GET['spe_categories']) : [];
    $filter_keyword = isset($_GET['spe_keyword']) ? sanitize_text_field($_GET['spe_keyword']) : '';
    $filter_keyword_scope = isset($_GET['spe_keyword_scope']) ? sanitize_text_field($_GET['spe_keyword_scope']) : 'all';

    // 构建文件名后缀
    $suffix = '';
    if (!empty($filter_categories)) {
        $suffix .= '-cat' . count($filter_categories);
    }
    if (!empty($filter_keyword)) {
        $suffix .= '-search';
    }

    while (ob_get_level())
        ob_end_clean();
    set_time_limit(0);

    $filename = 'posts-export' . $suffix . '-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    global $wpdb;

    // 构建查询条件
    $where_conditions = ["p.post_type = 'post'", "p.post_status IN ('publish', 'draft', 'private')"];

    // 分类筛选（文章使用 category 分类法）
    if (!empty($filter_categories)) {
        $cat_ids = implode(',', $filter_categories);
        $where_conditions[] = "
            p.ID IN (
                SELECT object_id FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.taxonomy = 'category' AND tt.term_id IN ($cat_ids)
            )
        ";
    }

    // 关键词筛选
    if (!empty($filter_keyword)) {
        $keyword_like = '%' . $wpdb->esc_like($filter_keyword) . '%';
        switch ($filter_keyword_scope) {
            case 'title':
                $where_conditions[] = $wpdb->prepare("p.post_title LIKE %s", $keyword_like);
                break;
            case 'content':
                $where_conditions[] = $wpdb->prepare("p.post_content LIKE %s", $keyword_like);
                break;
            case 'all':
            default:
                $where_conditions[] = $wpdb->prepare("(p.post_title LIKE %s OR p.post_content LIKE %s)", $keyword_like, $keyword_like);
                break;
        }
    }

    $where_clause = implode(' AND ', $where_conditions);

    // 获取文章
    $posts = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_name, p.post_excerpt, p.post_content
        FROM {$wpdb->posts} p
        WHERE $where_clause
        ORDER BY p.ID
    ");

    // 检查空结果
    if (empty($posts)) {
        if (!empty($filter_categories) || !empty($filter_keyword)) {
            fputcsv($output, ['提示：没有找到符合筛选条件的文章']);
            fputcsv($output, ['筛选条件：']);
            if (!empty($filter_categories)) {
                $cat_names = [];
                foreach ($filter_categories as $cat_id) {
                    $term = get_term($cat_id, 'category');
                    if ($term && !is_wp_error($term)) {
                        $cat_names[] = $term->name;
                    }
                }
                fputcsv($output, ['分类: ' . implode(', ', $cat_names)]);
            }
            if (!empty($filter_keyword)) {
                fputcsv($output, ['关键词: ' . $filter_keyword]);
            }
        } else {
            fputcsv($output, ['ID', '标题', 'Slug', '摘要', '内容']);
        }
        fclose($output);
        exit;
    }

    // 扫描第一个文章获取所有 meta keys
    $first_id = $posts[0]->ID;
    $all_meta_keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
        WHERE post_id = %d
        AND meta_key NOT LIKE '\_%'
        ORDER BY meta_key",
        $first_id
    ));

    // 排除 WordPress 内部字段
    $exclude_keys = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_thumbnail_id',
        '_wp_page_template',
    ];

    $custom_fields = array_values(array_diff($all_meta_keys, $exclude_keys));
    sort($custom_fields);

    // 基础字段
    $header = ['ID', '标题', 'Slug', '摘要', '内容'];

    // AIOSEO 字段
    $header[] = 'Meta Title';
    $header[] = 'Meta Description';

    // 所有自定义字段
    foreach ($custom_fields as $field) {
        $header[] = $field;
    }

    // 检测哪些字段是附件类型，添加 URL 辅助列
    $attachment_fields = [];
    foreach ($posts as $p) {
        foreach ($custom_fields as $field) {
            if (in_array($field, $attachment_fields))
                continue;
            $value = get_post_meta($p->ID, $field, true);
            if (is_numeric($value) && $value > 0 && wp_get_attachment_url($value)) {
                $attachment_fields[] = $field;
            }
        }
    }

    // 为附件字段添加 URL 列标题
    foreach ($custom_fields as $field) {
        if (in_array($field, $attachment_fields)) {
            $header[] = $field . '_url';
        }
    }

    fputcsv($output, $header);

    // 数据行
    foreach ($posts as $p) {
        $id = $p->ID;

        // 摘要和内容
        $excerpt = $p->post_excerpt ?: '';
        $content = $p->post_content ?: '';

        // 清理换行
        $excerpt = str_replace(["\r\n", "\n", "\r"], ' ', $excerpt);
        $content = str_replace(["\r\n", "\n", "\r"], ' ', $content);

        // AIOSEO
        $meta_title = '';
        $meta_desc = '';

        // 尝试多种方式获取 Meta Title
        $aioseo_title = get_post_meta($id, '_aioseo_title', true);
        if (is_array($aioseo_title)) {
            $meta_title = $aioseo_title['title'] ?? '';
        } elseif (is_string($aioseo_title)) {
            $meta_title = $aioseo_title;
        }
        if (empty($meta_title)) {
            $meta_title = get_post_meta($id, '_aioseop_title', true);
        }

        // 尝试多种方式获取 Meta Description
        $aioseo_desc = get_post_meta($id, '_aioseo_description', true);
        if (is_array($aioseo_desc)) {
            $meta_desc = $aioseo_desc['description'] ?? '';
        } elseif (is_string($aioseo_desc)) {
            $meta_desc = $aioseo_desc;
        }
        if (empty($meta_desc)) {
            $meta_desc = get_post_meta($id, '_aioseop_description', true);
        }

        $row = [$id, $p->post_title, $p->post_name, $excerpt, $content, $meta_title, $meta_desc];

        // 自定义字段值
        foreach ($custom_fields as $field) {
            $value = get_post_meta($id, $field, true);

            // 处理数组
            if (is_array($value)) {
                if (isset($value['url'])) {
                    $value = $value['url'];
                } elseif (isset($value['ID'])) {
                    $value = $value['ID'];
                } elseif (isset($value[0]) && is_array($value[0])) {
                    // 多选或 Repeater
                    $values = [];
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $values[] = isset($item['label']) ? $item['label'] : (isset($item['name']) ? $item['name'] : json_encode($item));
                        } else {
                            $values[] = $item;
                        }
                    }
                    $value = implode(', ', $values);
                } else {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }

            // 清理换行
            $value = str_replace(["\r\n", "\n", "\r"], ' ', $value);
            $row[] = $value;

            // 如果是附件 ID，添加 URL 辅助列
            if (is_numeric($value) && $value > 0) {
                $url = wp_get_attachment_url($value);
                if ($url) {
                    $row[] = $url;
                }
            }
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/**
 * 导入页面
 */
function spe_import_pages()
{
    if (empty($_FILES['spe_import_pages_file']['tmp_name'])) {
        return ['error' => true, 'message' => '请选择 CSV 文件'];
    }

    $file = $_FILES['spe_import_pages_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle)
        return ['error' => true, 'message' => '无法读取文件'];

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['error' => true, 'message' => 'CSV 文件为空或格式错误'];
    }

    // 支持中英文列名
    $id_col = array_search('ID', $header);

    $title_col = array_search('标题', $header);
    if ($title_col === false)
        $title_col = array_search('Title', $header);

    $slug_col = array_search('Slug', $header);

    $excerpt_col = array_search('摘要', $header);
    if ($excerpt_col === false)
        $excerpt_col = array_search('Excerpt', $header);

    $content_col = array_search('内容', $header);
    if ($content_col === false)
        $content_col = array_search('Content', $header);

    $meta_title_col = array_search('Meta Title', $header);
    $meta_desc_col = array_search('Meta Description', $header);

    $custom_cols = [];
    foreach ($header as $idx => $col_name) {
        // 排除所有已知的标准列（中英文）
        if (!in_array($col_name, ['ID', '标题', 'Title', 'Slug', '摘要', 'Excerpt', '内容', 'Content', 'Meta Title', 'Meta Description'])) {
            // 跳过 _url 结尾的列
            if (substr($col_name, -4) !== '_url') {
                $custom_cols[$idx] = $col_name;
            }
        }
    }

    $updated = 0;
    $not_found = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $page_id = ($id_col !== false) ? intval($row[$id_col]) : 0;
        if (!$page_id)
            continue;

        // 检查是否是页面
        $post = get_post($page_id);
        if (!$post || $post->post_type !== 'page') {
            $not_found++;
            continue;
        }

        // 更新基础字段
        $update_data = [];
        if ($title_col !== false && !empty($row[$title_col])) {
            $update_data['post_title'] = $row[$title_col];
        }
        if ($slug_col !== false && !empty($row[$slug_col])) {
            $update_data['post_name'] = sanitize_title($row[$slug_col]);
        }
        if ($excerpt_col !== false) {
            $update_data['post_excerpt'] = $row[$excerpt_col];
        }
        if ($content_col !== false) {
            $update_data['post_content'] = $row[$content_col];
        }

        if (!empty($update_data)) {
            $update_data['ID'] = $page_id;
            wp_update_post($update_data);
        }

        // AIOSEO - 支持多种存储方式
        if ($meta_title_col !== false && $row[$meta_title_col] !== '') {
            $meta_title = $row[$meta_title_col];

            // 方式1: aioseo_posts 表 (AIOSEO 4.x)
            global $wpdb;
            $aioseo_table = $wpdb->prefix . 'aioseo_posts';
            if ($wpdb->get_var("SHOW TABLES LIKE '$aioseo_table'") == $aioseo_table) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $aioseo_table WHERE post_id = %d",
                    $page_id
                ));
                if ($existing) {
                    $wpdb->update($aioseo_table, ['title' => $meta_title], ['post_id' => $page_id], ['%s'], ['%d']);
                } else {
                    $wpdb->insert($aioseo_table, ['post_id' => $page_id, 'title' => $meta_title], ['%d', '%s']);
                }
            }

            // 方式2: postmeta (兼容其他版本)
            update_post_meta($page_id, '_aioseo_title', $meta_title);
            update_post_meta($page_id, '_aioseop_title', $meta_title);
        }

        if ($meta_desc_col !== false && $row[$meta_desc_col] !== '') {
            $meta_desc = $row[$meta_desc_col];

            // 方式1: aioseo_posts 表 (AIOSEO 4.x)
            global $wpdb;
            $aioseo_table = $wpdb->prefix . 'aioseo_posts';
            if ($wpdb->get_var("SHOW TABLES LIKE '$aioseo_table'") == $aioseo_table) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $aioseo_table WHERE post_id = %d",
                    $page_id
                ));
                if ($existing) {
                    $wpdb->update($aioseo_table, ['description' => $meta_desc], ['post_id' => $page_id], ['%s'], ['%d']);
                } else {
                    $wpdb->insert($aioseo_table, ['post_id' => $page_id, 'description' => $meta_desc], ['%d', '%s']);
                }
            }

            // 方式2: postmeta (兼容其他版本)
            update_post_meta($page_id, '_aioseo_description', $meta_desc);
            update_post_meta($page_id, '_aioseop_description', $meta_desc);
        }

        // 自定义字段
        foreach ($custom_cols as $idx => $field_name) {
            $value = $row[$idx] ?? '';
            if ($value !== '') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
                update_post_meta($page_id, $field_name, $value);
            }
        }

        $updated++;
    }

    fclose($handle);

    $msg = "页面导入完成！更新了 {$updated} 个页面";
    if ($not_found > 0)
        $msg .= "，{$not_found} 个 ID 未找到或不是页面类型";
    return ['error' => false, 'message' => $msg, 'debug' => ''];
}

/**
 * 导入文章
 */
function spe_import_posts()
{
    if (empty($_FILES['spe_import_posts_file']['tmp_name'])) {
        return ['error' => true, 'message' => '请选择 CSV 文件'];
    }

    $file = $_FILES['spe_import_posts_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle)
        return ['error' => true, 'message' => '无法读取文件'];

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return ['error' => true, 'message' => 'CSV 文件为空或格式错误'];
    }

    // 支持中英文列名
    $id_col = array_search('ID', $header);

    $title_col = array_search('标题', $header);
    if ($title_col === false)
        $title_col = array_search('Title', $header);

    $slug_col = array_search('Slug', $header);

    $excerpt_col = array_search('摘要', $header);
    if ($excerpt_col === false)
        $excerpt_col = array_search('Excerpt', $header);

    $content_col = array_search('内容', $header);
    if ($content_col === false)
        $content_col = array_search('Content', $header);

    $meta_title_col = array_search('Meta Title', $header);
    $meta_desc_col = array_search('Meta Description', $header);

    $custom_cols = [];
    foreach ($header as $idx => $col_name) {
        // 排除所有已知的标准列（中英文）
        if (!in_array($col_name, ['ID', '标题', 'Title', 'Slug', '摘要', 'Excerpt', '内容', 'Content', 'Meta Title', 'Meta Description'])) {
            // 跳过 _url 结尾的列
            if (substr($col_name, -4) !== '_url') {
                $custom_cols[$idx] = $col_name;
            }
        }
    }

    $updated = 0;
    $not_found = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $post_id = ($id_col !== false) ? intval($row[$id_col]) : 0;
        if (!$post_id)
            continue;

        // 检查是否是文章
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            $not_found++;
            continue;
        }

        // 更新基础字段
        $update_data = [];
        if ($title_col !== false && !empty($row[$title_col])) {
            $update_data['post_title'] = $row[$title_col];
        }
        if ($slug_col !== false && !empty($row[$slug_col])) {
            $update_data['post_name'] = sanitize_title($row[$slug_col]);
        }
        if ($excerpt_col !== false) {
            $update_data['post_excerpt'] = $row[$excerpt_col];
        }
        if ($content_col !== false) {
            $update_data['post_content'] = $row[$content_col];
        }

        if (!empty($update_data)) {
            $update_data['ID'] = $post_id;
            wp_update_post($update_data);
        }

        // AIOSEO - 支持多种存储方式
        if ($meta_title_col !== false && $row[$meta_title_col] !== '') {
            $meta_title = $row[$meta_title_col];

            // 方式1: aioseo_posts 表 (AIOSEO 4.x)
            global $wpdb;
            $aioseo_table = $wpdb->prefix . 'aioseo_posts';
            if ($wpdb->get_var("SHOW TABLES LIKE '$aioseo_table'") == $aioseo_table) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $aioseo_table WHERE post_id = %d",
                    $post_id
                ));
                if ($existing) {
                    $wpdb->update($aioseo_table, ['title' => $meta_title], ['post_id' => $post_id], ['%s'], ['%d']);
                } else {
                    $wpdb->insert($aioseo_table, ['post_id' => $post_id, 'title' => $meta_title], ['%d', '%s']);
                }
            }

            // 方式2: postmeta (兼容其他版本)
            update_post_meta($post_id, '_aioseo_title', $meta_title);
            update_post_meta($post_id, '_aioseop_title', $meta_title);
        }

        if ($meta_desc_col !== false && $row[$meta_desc_col] !== '') {
            $meta_desc = $row[$meta_desc_col];

            // 方式1: aioseo_posts 表 (AIOSEO 4.x)
            global $wpdb;
            $aioseo_table = $wpdb->prefix . 'aioseo_posts';
            if ($wpdb->get_var("SHOW TABLES LIKE '$aioseo_table'") == $aioseo_table) {
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $aioseo_table WHERE post_id = %d",
                    $post_id
                ));
                if ($existing) {
                    $wpdb->update($aioseo_table, ['description' => $meta_desc], ['post_id' => $post_id], ['%s'], ['%d']);
                } else {
                    $wpdb->insert($aioseo_table, ['post_id' => $post_id, 'description' => $meta_desc], ['%d', '%s']);
                }
            }

            // 方式2: postmeta (兼容其他版本)
            update_post_meta($post_id, '_aioseo_description', $meta_desc);
            update_post_meta($post_id, '_aioseop_description', $meta_desc);
        }

        // 自定义字段
        foreach ($custom_cols as $idx => $field_name) {
            $value = $row[$idx] ?? '';
            if ($value !== '') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $value = $decoded;
                }
                update_post_meta($post_id, $field_name, $value);
            }
        }

        $updated++;
    }

    fclose($handle);

    $msg = "文章导入完成！更新了 {$updated} 个文章";
    if ($not_found > 0)
        $msg .= "，{$not_found} 个 ID 未找到或不是文章类型";
    return ['error' => false, 'message' => $msg, 'debug' => ''];
}