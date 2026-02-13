<?php
/*
Plugin Name: 产品导入导出工具
Plugin URI: https://github.com/yourusername/simple-product-export
Description: 导出/导入 产品、页面、文章 和分类 CSV，包含所有自定义字段，支持筛选导出
Version: 4.7.6
Author: zhangkun
License: GPL v2 or later
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'spe_add_admin_menu');
add_action('wp_ajax_spe_get_taxonomy_export_fields', 'spe_ajax_get_taxonomy_export_fields');

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

/**
 * AJAX: 按需读取指定 taxonomy 的可导出字段列表
 */
function spe_ajax_get_taxonomy_export_fields()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }

    check_ajax_referer('spe_tax_fields_nonce', 'nonce');

    $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field((string) $_POST['taxonomy']) : 'product_cat';
    if (!taxonomy_exists($taxonomy)) {
        wp_send_json_error(['message' => 'invalid taxonomy'], 400);
    }

    $options = spe_get_taxonomy_export_field_options($taxonomy);
    wp_send_json_success(['options' => $options]);
}

/**
 * 标准化 CSV 表头：去除 BOM、首尾空格，避免列匹配失败
 */
function spe_normalize_csv_header($header)
{
    if (!is_array($header)) {
        return [];
    }

    foreach ($header as $idx => $col_name) {
        if (!is_string($col_name)) {
            continue;
        }
        $clean = trim($col_name);
        $clean = str_replace("\xEF\xBB\xBF", '', $clean);
        $clean = preg_replace('/^\x{FEFF}/u', '', $clean);
        $header[$idx] = $clean;
    }

    return $header;
}

/**
 * 构建不区分大小写的 CSV 表头索引
 */
function spe_build_header_index($header)
{
    $index = [];
    if (!is_array($header)) {
        return $index;
    }

    foreach ($header as $idx => $col_name) {
        if (!is_string($col_name)) {
            continue;
        }

        $key = strtolower(trim($col_name));
        if ($key === '' || array_key_exists($key, $index)) {
            continue;
        }

        $index[$key] = $idx;
    }

    return $index;
}

/**
 * 按别名列表查找表头列索引（不区分大小写）
 */
function spe_find_header_col($header, $aliases)
{
    if (!is_array($aliases) || empty($aliases)) {
        return false;
    }

    $index = spe_build_header_index($header);
    foreach ($aliases as $alias) {
        $key = strtolower(trim((string) $alias));
        if ($key !== '' && array_key_exists($key, $index)) {
            return $index[$key];
        }
    }

    return false;
}

/**
 * 判断是否为“附件 URL 辅助列”（例如 image + image_url）
 */
function spe_should_skip_helper_url_column($col_name, $header)
{
    if (!is_string($col_name) || substr($col_name, -4) !== '_url') {
        return false;
    }

    $base_col = substr($col_name, 0, -4);
    if ($base_col === '') {
        return false;
    }

    return in_array($base_col, $header, true);
}

/**
 * 从单行文本推断 CSV 分隔符
 */
function spe_detect_csv_delimiter_from_line($line)
{
    if (!is_string($line) || $line === '') {
        return ',';
    }

    $line = str_replace("\xEF\xBB\xBF", '', $line);
    $line = preg_replace('/^\x{FEFF}/u', '', $line);

    $candidates = [',', ';', "\t", '|'];
    $best_delimiter = ',';
    $best_count = 0;

    foreach ($candidates as $delimiter) {
        $cols = str_getcsv($line, $delimiter, '"', '\\');
        $count = is_array($cols) ? count($cols) : 0;
        if ($count > $best_count) {
            $best_count = $count;
            $best_delimiter = $delimiter;
        }
    }

    return $best_count > 1 ? $best_delimiter : ',';
}

/**
 * 推断文件的 CSV 分隔符
 */
function spe_detect_csv_delimiter($file_path)
{
    if (!is_string($file_path) || $file_path === '' || !file_exists($file_path)) {
        return ',';
    }

    $fp = fopen($file_path, 'r');
    if (!$fp) {
        return ',';
    }

    $line = fgets($fp);
    fclose($fp);

    if ($line === false) {
        return ',';
    }

    return spe_detect_csv_delimiter_from_line($line);
}

/**
 * 打开 CSV 文件并返回句柄与分隔符
 */
function spe_open_csv_file($file_path)
{
    $delimiter = spe_detect_csv_delimiter($file_path);
    $handle = fopen($file_path, 'r');

    return [$handle, $delimiter];
}

/**
 * 组装自定义字段行片段，确保与表头顺序一致
 */
function spe_build_custom_export_row_segments($custom_fields, $attachment_field_map, $custom_values)
{
    $value_segment = [];
    $url_segment = [];

    foreach ($custom_fields as $field) {
        $field = (string) $field;
        $value = $custom_values[$field] ?? '';
        $value_segment[] = $value;

        if (!empty($attachment_field_map[$field])) {
            $url = '';
            if (is_numeric($value) && $value > 0) {
                $resolved = wp_get_attachment_url(intval($value));
                if ($resolved) {
                    $url = $resolved;
                }
            }
            $url_segment[] = $url;
        }
    }

    return [
        'values' => $value_segment,
        'urls' => $url_segment,
    ];
}

/**
 * 检测是否误上传了 ZIP/XLSX 文件（文件头以 PK\x03\x04 开始）
 */
function spe_is_zip_signature_file($file_path)
{
    if (!is_string($file_path) || $file_path === '' || !file_exists($file_path)) {
        return false;
    }

    $fp = fopen($file_path, 'rb');
    if (!$fp) {
        return false;
    }
    $magic = fread($fp, 4);
    fclose($fp);

    return $magic === "PK\x03\x04";
}

/**
 * 尝试将 JSON 字符串解析为数组
 */
function spe_maybe_decode_json_value($value)
{
    if (!is_string($value)) {
        return $value;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return $value;
    }

    $decoded = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
    }

    return $value;
}

/**
 * 判断数组是否为关联数组
 */
function spe_is_assoc_array($arr)
{
    if (!is_array($arr)) {
        return false;
    }
    return array_keys($arr) !== range(0, count($arr) - 1);
}

/**
 * 将 URL / 数组格式媒体值转换为附件 ID
 */
function spe_media_value_to_attachment_id($value)
{
    if (is_numeric($value)) {
        return intval($value);
    }

    if (is_array($value)) {
        if (!empty($value['ID']) && is_numeric($value['ID'])) {
            return intval($value['ID']);
        }
        if (!empty($value['id']) && is_numeric($value['id'])) {
            return intval($value['id']);
        }
        if (!empty($value['url']) && is_string($value['url'])) {
            $id = attachment_url_to_postid($value['url']);
            if ($id) {
                return intval($id);
            }
        }
        return 0;
    }

    if (is_string($value)) {
        $url = trim($value);
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $id = attachment_url_to_postid($url);
            if ($id) {
                return intval($id);
            }
        }
    }

    return 0;
}

/**
 * 递归准备 ACF 字段值（重点处理 repeater / image / file / gallery）
 */
function spe_prepare_acf_value($field, $value)
{
    if (!is_array($field) || empty($field['type'])) {
        return $value;
    }

    $type = $field['type'];

    if (in_array($type, ['image', 'file'], true)) {
        $id = spe_media_value_to_attachment_id($value);
        if ($id > 0) {
            return $id;
        }
        return $value;
    }

    if ($type === 'gallery') {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            $id = spe_media_value_to_attachment_id($item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    if ($type === 'repeater') {
        if (!is_array($value)) {
            return [];
        }

        $rows = spe_is_assoc_array($value) ? [$value] : $value;
        $sub_fields = !empty($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [];
        $prepared_rows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $prepared_row = [];
            foreach ($sub_fields as $sub_field) {
                $sub_name = $sub_field['name'] ?? '';
                if ($sub_name === '' || !array_key_exists($sub_name, $row)) {
                    continue;
                }
                $prepared_row[$sub_name] = spe_prepare_acf_value($sub_field, $row[$sub_name]);
            }

            if (!empty($prepared_row)) {
                $prepared_rows[] = $prepared_row;
            }
        }

        return $prepared_rows;
    }

    if ($type === 'group') {
        if (!is_array($value)) {
            return [];
        }
        $sub_fields = !empty($field['sub_fields']) && is_array($field['sub_fields']) ? $field['sub_fields'] : [];
        $prepared = [];
        foreach ($sub_fields as $sub_field) {
            $sub_name = $sub_field['name'] ?? '';
            if ($sub_name === '' || !array_key_exists($sub_name, $value)) {
                continue;
            }
            $prepared[$sub_name] = spe_prepare_acf_value($sub_field, $value[$sub_name]);
        }
        return $prepared;
    }

    return $value;
}

/**
 * 优先按 ACF 字段写入（可维护 repeater 结构），失败再回退 post_meta
 */
function spe_update_post_custom_field($post_id, $field_name, $raw_value)
{
    $value = spe_maybe_decode_json_value($raw_value);

    if (function_exists('update_field') && function_exists('get_field_object')) {
        $acf_field = get_field_object($field_name, $post_id, false, false);
        if (is_array($acf_field) && !empty($acf_field['key'])) {
            $prepared = spe_prepare_acf_value($acf_field, $value);
            // update_field 返回 false 也可能只是“值未变化”，此时不应回退写 meta 覆盖结构
            update_field($acf_field['key'], $prepared, $post_id);
            return true;
        }
    }

    update_post_meta($post_id, $field_name, $value);
    return false;
}

/**
 * 优先按 ACF taxonomy 字段写入（可维护 repeater 结构），失败再回退 term_meta
 */
function spe_update_term_custom_field($term_id, $field_name, $raw_value)
{
    $value = spe_maybe_decode_json_value($raw_value);
    $object_id = 'term_' . intval($term_id);

    if (function_exists('update_field') && function_exists('get_field_object')) {
        $acf_field = get_field_object($field_name, $object_id, false, false);
        if (is_array($acf_field) && !empty($acf_field['key'])) {
            $prepared = spe_prepare_acf_value($acf_field, $value);
            // update_field 返回 false 也可能只是“值未变化”，此时不应回退写 meta 覆盖结构
            update_field($acf_field['key'], $prepared, $object_id);
            return true;
        }
    }

    update_term_meta($term_id, $field_name, $value);
    return false;
}

/**
 * 获取 AIOSEO terms 表信息（如存在）
 */
function spe_get_aioseo_terms_table_info()
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'aioseo_terms';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

    if ($exists !== $table) {
        $cache = ['table' => '', 'columns' => []];
        return $cache;
    }

    $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
    if (!is_array($columns)) {
        $columns = [];
    }

    $cache = ['table' => $table, 'columns' => $columns];
    return $cache;
}

/**
 * 在候选列中找到第一个存在于表结构的列名
 */
function spe_pick_existing_column($columns, $candidates)
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return '';
}

/**
 * 从 AIOSEO terms 表读取分类 SEO 标题和描述（如果有）
 */
function spe_get_aioseo_term_row($term_id, $taxonomy = '')
{
    $info = spe_get_aioseo_terms_table_info();
    if (empty($info['table']) || empty($info['columns'])) {
        return ['title' => '', 'description' => ''];
    }

    $columns = $info['columns'];
    $table = $info['table'];

    $id_col = spe_pick_existing_column($columns, ['term_id', 'term_taxonomy_id', 'object_id', 'taxonomy_id']);
    $tax_col = spe_pick_existing_column($columns, ['taxonomy', 'taxonomies']);
    $title_col = spe_pick_existing_column($columns, ['title', 'seo_title']);
    $desc_col = spe_pick_existing_column($columns, ['description', 'seo_description', 'meta_description']);

    if (!$id_col || (!$title_col && !$desc_col)) {
        return ['title' => '', 'description' => ''];
    }

    $select_cols = [];
    if ($title_col) {
        $select_cols[] = "`{$title_col}` AS title";
    }
    if ($desc_col) {
        $select_cols[] = "`{$desc_col}` AS description";
    }

    if (empty($select_cols)) {
        return ['title' => '', 'description' => ''];
    }

    global $wpdb;
    $where = ["`{$id_col}` = %d"];
    $args = [intval($term_id)];

    if ($tax_col && $taxonomy !== '') {
        $where[] = "`{$tax_col}` = %s";
        $args[] = $taxonomy;
    }

    $sql = "SELECT " . implode(', ', $select_cols) . " FROM `{$table}` WHERE " . implode(' AND ', $where) . " LIMIT 1";
    $row = $wpdb->get_row($wpdb->prepare($sql, ...$args), ARRAY_A);

    if (!$row && $tax_col && $taxonomy !== '') {
        // 某些站点可能未记录 taxonomy 列，回退到 term_id 单条件查询
        $sql = "SELECT " . implode(', ', $select_cols) . " FROM `{$table}` WHERE `{$id_col}` = %d LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, intval($term_id)), ARRAY_A);
    }

    return [
        'title' => isset($row['title']) ? (string) $row['title'] : '',
        'description' => isset($row['description']) ? (string) $row['description'] : '',
    ];
}

/**
 * 将分类 SEO 标题和描述同步写入 AIOSEO terms 表（如存在）
 */
function spe_sync_aioseo_term_row($term_id, $taxonomy = '', $title = null, $description = null)
{
    $info = spe_get_aioseo_terms_table_info();
    if (empty($info['table']) || empty($info['columns'])) {
        return false;
    }

    $columns = $info['columns'];
    $table = $info['table'];

    $id_col = spe_pick_existing_column($columns, ['term_id', 'term_taxonomy_id', 'object_id', 'taxonomy_id']);
    $tax_col = spe_pick_existing_column($columns, ['taxonomy', 'taxonomies']);
    $title_col = spe_pick_existing_column($columns, ['title', 'seo_title']);
    $desc_col = spe_pick_existing_column($columns, ['description', 'seo_description', 'meta_description']);

    if (!$id_col) {
        return false;
    }

    $data = [];
    $formats = [];

    if ($title !== null && $title_col) {
        $data[$title_col] = $title;
        $formats[] = '%s';
    }
    if ($description !== null && $desc_col) {
        $data[$desc_col] = $description;
        $formats[] = '%s';
    }

    if (empty($data)) {
        return false;
    }

    global $wpdb;
    $where = [$id_col => intval($term_id)];
    $where_formats = ['%d'];

    if ($tax_col && $taxonomy !== '') {
        $where[$tax_col] = $taxonomy;
        $where_formats[] = '%s';
    }

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1) FROM `{$table}` WHERE `{$id_col}` = %d",
        intval($term_id)
    ));

    if (intval($existing) > 0) {
        $wpdb->update($table, $data, $where, $formats, $where_formats);
        return true;
    }

    $insert_data = $data;
    $insert_data[$id_col] = intval($term_id);
    $insert_formats = $formats;
    $insert_formats[] = '%d';

    if ($tax_col && $taxonomy !== '') {
        $insert_data[$tax_col] = $taxonomy;
        $insert_formats[] = '%s';
    }

    $wpdb->insert($table, $insert_data, $insert_formats);
    return true;
}

/**
 * 读取 Rank Math 分类 SEO 字段
 */
function spe_get_rankmath_term_meta($term_id)
{
    return [
        'title' => (string) get_term_meta($term_id, 'rank_math_title', true),
        'description' => (string) get_term_meta($term_id, 'rank_math_description', true),
    ];
}

/**
 * 同步 Rank Math 分类 SEO 字段
 */
function spe_sync_rankmath_term_meta($term_id, $title = null, $description = null)
{
    if ($title !== null) {
        update_term_meta($term_id, 'rank_math_title', $title);
    }
    if ($description !== null) {
        update_term_meta($term_id, 'rank_math_description', $description);
    }
}

/**
 * 读取 Yoast 分类 SEO 字段（option: wpseo_taxonomy_meta）
 */
function spe_get_yoast_term_meta($term_id, $taxonomy = '')
{
    if ($taxonomy === '') {
        return ['title' => '', 'description' => ''];
    }

    $all = get_option('wpseo_taxonomy_meta', []);
    if (!is_array($all) || !isset($all[$taxonomy]) || !isset($all[$taxonomy][$term_id]) || !is_array($all[$taxonomy][$term_id])) {
        return ['title' => '', 'description' => ''];
    }

    $row = $all[$taxonomy][$term_id];
    return [
        'title' => (string) ($row['wpseo_title'] ?? $row['title'] ?? ''),
        'description' => (string) ($row['wpseo_desc'] ?? $row['desc'] ?? ''),
    ];
}

/**
 * 同步 Yoast 分类 SEO 字段（option: wpseo_taxonomy_meta）
 */
function spe_sync_yoast_term_meta($term_id, $taxonomy = '', $title = null, $description = null)
{
    if ($taxonomy === '' || ($title === null && $description === null)) {
        return false;
    }

    $all = get_option('wpseo_taxonomy_meta', []);
    if (!is_array($all)) {
        $all = [];
    }
    if (!isset($all[$taxonomy]) || !is_array($all[$taxonomy])) {
        $all[$taxonomy] = [];
    }
    if (!isset($all[$taxonomy][$term_id]) || !is_array($all[$taxonomy][$term_id])) {
        $all[$taxonomy][$term_id] = [];
    }

    if ($title !== null) {
        $all[$taxonomy][$term_id]['wpseo_title'] = $title;
        $all[$taxonomy][$term_id]['title'] = $title;
        update_term_meta($term_id, 'wpseo_title', $title);
    }
    if ($description !== null) {
        $all[$taxonomy][$term_id]['wpseo_desc'] = $description;
        $all[$taxonomy][$term_id]['desc'] = $description;
        update_term_meta($term_id, 'wpseo_desc', $description);
    }

    update_option('wpseo_taxonomy_meta', $all, false);
    return true;
}

/**
 * 统一读取分类 SEO 标题/描述（AIOSEO > Rank Math > Yoast）
 */
function spe_get_term_seo_meta($term_id, $taxonomy = '')
{
    $meta_title = '';
    $meta_desc = '';

    // AIOSEO termmeta
    $aioseo_title = get_term_meta($term_id, '_aioseo_title', true);
    if (is_array($aioseo_title)) {
        $meta_title = $aioseo_title['title'] ?? '';
    } elseif (is_string($aioseo_title)) {
        $meta_title = $aioseo_title;
    }
    if (empty($meta_title)) {
        $meta_title = get_term_meta($term_id, '_aioseop_title', true);
    }

    $aioseo_desc = get_term_meta($term_id, '_aioseo_description', true);
    if (is_array($aioseo_desc)) {
        $meta_desc = $aioseo_desc['description'] ?? '';
    } elseif (is_string($aioseo_desc)) {
        $meta_desc = $aioseo_desc;
    }
    if (empty($meta_desc)) {
        $meta_desc = get_term_meta($term_id, '_aioseop_description', true);
    }

    // AIOSEO table
    if (empty($meta_title) || empty($meta_desc)) {
        $aioseo_row = spe_get_aioseo_term_row($term_id, $taxonomy);
        if (empty($meta_title) && !empty($aioseo_row['title'])) {
            $meta_title = $aioseo_row['title'];
        }
        if (empty($meta_desc) && !empty($aioseo_row['description'])) {
            $meta_desc = $aioseo_row['description'];
        }
    }

    // Rank Math fallback
    if (empty($meta_title) || empty($meta_desc)) {
        $rankmath = spe_get_rankmath_term_meta($term_id);
        if (empty($meta_title) && !empty($rankmath['title'])) {
            $meta_title = $rankmath['title'];
        }
        if (empty($meta_desc) && !empty($rankmath['description'])) {
            $meta_desc = $rankmath['description'];
        }
    }

    // Yoast fallback
    if (empty($meta_title) || empty($meta_desc)) {
        $yoast = spe_get_yoast_term_meta($term_id, $taxonomy);
        if (empty($meta_title) && !empty($yoast['title'])) {
            $meta_title = $yoast['title'];
        }
        if (empty($meta_desc) && !empty($yoast['description'])) {
            $meta_desc = $yoast['description'];
        }
    }

    return [
        'title' => (string) $meta_title,
        'description' => (string) $meta_desc,
    ];
}

/**
 * 检测当前激活的 SEO 提供方（按优先级）
 */
function spe_get_active_seo_provider()
{
    if (
        defined('AIOSEO_VERSION')
        || class_exists('AIOSEO\\Plugin\\AIOSEO')
        || class_exists('AIOSEO\\Plugin\\Common\\Main')
    ) {
        return 'aioseo';
    }

    if (
        defined('RANK_MATH_VERSION')
        || class_exists('RankMath')
        || class_exists('RankMath\\Loader')
    ) {
        return 'rankmath';
    }

    if (
        defined('WPSEO_VERSION')
        || class_exists('WPSEO_Options')
    ) {
        return 'yoast';
    }

    return '';
}

/**
 * 仅同步到当前激活的 SEO 插件，避免跨插件副作用
 */
function spe_sync_term_seo_meta_by_active_provider($term_id, $taxonomy, $title = null, $description = null)
{
    $provider = spe_get_active_seo_provider();
    if ($provider === 'aioseo') {
        if ($title !== null) {
            update_term_meta($term_id, '_aioseo_title', $title);
            update_term_meta($term_id, '_aioseop_title', $title);
        }
        if ($description !== null) {
            update_term_meta($term_id, '_aioseo_description', $description);
            update_term_meta($term_id, '_aioseop_description', $description);
        }
        $synced = spe_sync_aioseo_term_row($term_id, $taxonomy, $title, $description);
        return ['provider' => 'aioseo', 'synced' => $synced];
    }

    if ($provider === 'rankmath') {
        spe_sync_rankmath_term_meta($term_id, $title, $description);
        return ['provider' => 'rankmath', 'synced' => true];
    }

    if ($provider === 'yoast') {
        $synced = spe_sync_yoast_term_meta($term_id, $taxonomy, $title, $description);
        return ['provider' => 'yoast', 'synced' => $synced];
    }

    return ['provider' => '', 'synced' => false];
}

/**
 * 获取 AIOSEO posts 表信息（如存在）
 */
function spe_get_aioseo_posts_table_info()
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'aioseo_posts';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

    if ($exists !== $table) {
        $cache = ['table' => '', 'columns' => []];
        return $cache;
    }

    $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
    if (!is_array($columns)) {
        $columns = [];
    }

    $cache = ['table' => $table, 'columns' => $columns];
    return $cache;
}

/**
 * 将文章 SEO 标题和描述同步写入 AIOSEO posts 表（如存在）
 */
function spe_sync_aioseo_post_row($post_id, $title = null, $description = null)
{
    $info = spe_get_aioseo_posts_table_info();
    if (empty($info['table']) || empty($info['columns'])) {
        return false;
    }

    $columns = $info['columns'];
    $table = $info['table'];

    $id_col = spe_pick_existing_column($columns, ['post_id', 'object_id', 'id']);
    $title_col = spe_pick_existing_column($columns, ['title', 'seo_title']);
    $desc_col = spe_pick_existing_column($columns, ['description', 'seo_description', 'meta_description']);

    if (!$id_col) {
        return false;
    }

    $data = [];
    $formats = [];

    if ($title !== null && $title_col) {
        $data[$title_col] = $title;
        $formats[] = '%s';
    }
    if ($description !== null && $desc_col) {
        $data[$desc_col] = $description;
        $formats[] = '%s';
    }

    if (empty($data)) {
        return false;
    }

    global $wpdb;
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1) FROM `{$table}` WHERE `{$id_col}` = %d",
        intval($post_id)
    ));

    if (intval($existing) > 0) {
        $wpdb->update(
            $table,
            $data,
            [$id_col => intval($post_id)],
            $formats,
            ['%d']
        );
        return true;
    }

    $insert_data = $data;
    $insert_data[$id_col] = intval($post_id);
    $insert_formats = $formats;
    $insert_formats[] = '%d';

    $wpdb->insert($table, $insert_data, $insert_formats);
    return true;
}

/**
 * 同步 Rank Math 文章 SEO 字段
 */
function spe_sync_rankmath_post_meta($post_id, $title = null, $description = null)
{
    if ($title !== null) {
        update_post_meta($post_id, 'rank_math_title', $title);
    }
    if ($description !== null) {
        update_post_meta($post_id, 'rank_math_description', $description);
    }
}

/**
 * 同步 Yoast 文章 SEO 字段
 */
function spe_sync_yoast_post_meta($post_id, $title = null, $description = null)
{
    if ($title !== null) {
        update_post_meta($post_id, '_yoast_wpseo_title', $title);
    }
    if ($description !== null) {
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $description);
    }
}

/**
 * 仅同步文章 SEO 到当前激活插件，避免跨插件副作用
 */
function spe_sync_post_seo_meta_by_active_provider($post_id, $title = null, $description = null)
{
    $provider = spe_get_active_seo_provider();

    if ($provider === 'aioseo') {
        if ($title !== null) {
            update_post_meta($post_id, '_aioseo_title', $title);
            update_post_meta($post_id, '_aioseop_title', $title);
        }
        if ($description !== null) {
            update_post_meta($post_id, '_aioseo_description', $description);
            update_post_meta($post_id, '_aioseop_description', $description);
        }
        $synced = spe_sync_aioseo_post_row($post_id, $title, $description);
        return ['provider' => 'aioseo', 'synced' => $synced];
    }

    if ($provider === 'rankmath') {
        spe_sync_rankmath_post_meta($post_id, $title, $description);
        return ['provider' => 'rankmath', 'synced' => true];
    }

    if ($provider === 'yoast') {
        spe_sync_yoast_post_meta($post_id, $title, $description);
        return ['provider' => 'yoast', 'synced' => true];
    }

    return ['provider' => '', 'synced' => false];
}

/**
 * 分类导出的基础字段定义（固定顺序）
 */
function spe_get_taxonomy_base_export_fields()
{
    return [
        'id' => ['header' => 'ID', 'label' => 'ID（必选）'],
        'name' => ['header' => '标题', 'label' => '标题/名称'],
        'slug' => ['header' => 'Slug', 'label' => 'Slug'],
        'description' => ['header' => '描述', 'label' => '描述'],
        'parent' => ['header' => '父分类 ID', 'label' => '父分类 ID'],
        'meta_title' => ['header' => 'Meta Title', 'label' => 'Meta Title'],
        'meta_description' => ['header' => 'Meta Description', 'label' => 'Meta Description'],
    ];
}

/**
 * 分类导出默认保留的关键字段（即使当前没有值也可出现在可选字段中）
 */
function spe_get_taxonomy_required_custom_fields()
{
    // 通用插件默认不强制注入业务字段，可通过过滤器按站点扩展
    $fields = apply_filters('spe_required_taxonomy_custom_fields', []);
    return is_array($fields) ? array_values(array_unique($fields)) : [];
}

/**
 * 判断 ACF location 规则是否匹配指定 taxonomy
 */
function spe_acf_location_rule_matches_taxonomy($rule, $taxonomy)
{
    if (!is_array($rule)) {
        return false;
    }

    $param = isset($rule['param']) ? (string) $rule['param'] : '';
    if (!in_array($param, ['taxonomy', 'term_taxonomy'], true)) {
        return false;
    }

    $operator = isset($rule['operator']) ? (string) $rule['operator'] : '==';
    $value = isset($rule['value']) ? (string) $rule['value'] : '';
    $matched = ($value === 'all' || $value === $taxonomy);

    if ($operator === '!=') {
        return !$matched;
    }

    return $matched;
}

/**
 * 从 ACF 字段组定义中收集 taxonomy 字段名（即使当前 term_meta 没有值）
 */
function spe_get_taxonomy_acf_field_names($taxonomy)
{
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return [];
    }

    $groups = acf_get_field_groups();
    if (!is_array($groups) || empty($groups)) {
        return [];
    }

    $fields = [];
    foreach ($groups as $group) {
        $locations = isset($group['location']) && is_array($group['location']) ? $group['location'] : [];
        if (empty($locations)) {
            continue;
        }

        $group_matches = false;
        foreach ($locations as $and_rules) {
            if (!is_array($and_rules) || empty($and_rules)) {
                continue;
            }

            $has_taxonomy_rule = false;
            $all_taxonomy_rules_match = true;
            foreach ($and_rules as $rule) {
                $param = isset($rule['param']) ? (string) $rule['param'] : '';
                if (!in_array($param, ['taxonomy', 'term_taxonomy'], true)) {
                    continue;
                }

                $has_taxonomy_rule = true;
                if (!spe_acf_location_rule_matches_taxonomy($rule, $taxonomy)) {
                    $all_taxonomy_rules_match = false;
                    break;
                }
            }

            if ($has_taxonomy_rule && $all_taxonomy_rules_match) {
                $group_matches = true;
                break;
            }
        }

        if (!$group_matches) {
            continue;
        }

        $group_key = isset($group['key']) ? (string) $group['key'] : '';
        if ($group_key === '') {
            continue;
        }

        $group_fields = acf_get_fields($group_key);
        if (!is_array($group_fields) || empty($group_fields)) {
            continue;
        }

        foreach ($group_fields as $field) {
            $name = isset($field['name']) ? trim((string) $field['name']) : '';
            if ($name === '' || strpos($name, '_') === 0) {
                continue;
            }
            $fields[] = $name;
        }
    }

    $fields = array_values(array_unique($fields));
    sort($fields);
    return $fields;
}

/**
 * 获取某个分类法可导出的自定义字段
 */
function spe_get_taxonomy_custom_fields($taxonomy)
{
    global $wpdb;

    $all_meta_keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT tm.meta_key
        FROM {$wpdb->termmeta} tm
        INNER JOIN {$wpdb->term_taxonomy} tt ON tm.term_id = tt.term_id
        WHERE tt.taxonomy = %s
        AND tm.meta_key NOT LIKE '\_%'
        ORDER BY tm.meta_key",
        $taxonomy
    ));

    if (!is_array($all_meta_keys)) {
        $all_meta_keys = [];
    }

    $exclude_keys = ['_product_count', '_thumbnail_id'];
    $custom_fields = array_values(array_diff($all_meta_keys, $exclude_keys));
    $acf_defined_fields = spe_get_taxonomy_acf_field_names($taxonomy);
    $custom_fields = array_values(array_unique(array_merge($custom_fields, $acf_defined_fields)));
    $custom_fields = array_values(array_unique(array_merge(spe_get_taxonomy_required_custom_fields(), $custom_fields)));
    sort($custom_fields);

    return $custom_fields;
}

/**
 * 获取某个分类法的字段选项（用于 UI 选择器）
 */
function spe_get_taxonomy_export_field_options($taxonomy)
{
    $options = [];
    $base = spe_get_taxonomy_base_export_fields();
    foreach ($base as $key => $meta) {
        $options[] = [
            'value' => $key,
            'label' => $meta['label'],
            'group' => 'base',
        ];
    }

    foreach (spe_get_taxonomy_custom_fields($taxonomy) as $field) {
        $options[] = [
            'value' => 'field:' . $field,
            'label' => $field,
            'group' => 'custom',
        ];
    }

    return $options;
}

/**
 * 解析并规范化分类导出字段选择（默认全选，且始终保留 ID）
 */
function spe_resolve_taxonomy_export_fields($taxonomy, $selected_fields)
{
    $options = spe_get_taxonomy_export_field_options($taxonomy);
    $valid_values = array_column($options, 'value');

    $selected = array_values(array_unique(array_filter(array_map(
        function ($item) {
            return sanitize_text_field((string) $item);
        },
        is_array($selected_fields) ? $selected_fields : []
    ))));

    if (empty($selected)) {
        $selected = $valid_values;
    }

    if (!in_array('id', $selected, true)) {
        $selected[] = 'id';
    }

    $selected_lookup = array_fill_keys($selected, true);
    $ordered_selected = [];
    foreach ($valid_values as $value) {
        if (isset($selected_lookup[$value])) {
            $ordered_selected[] = $value;
        }
    }

    if (!in_array('id', $ordered_selected, true)) {
        array_unshift($ordered_selected, 'id');
    }

    return $ordered_selected;
}

/**
 * 判断 ACF location 规则是否匹配指定 post_type
 */
function spe_acf_location_rule_matches_post_type($rule, $post_type)
{
    if (!is_array($rule)) {
        return false;
    }

    $param = isset($rule['param']) ? (string) $rule['param'] : '';
    if ($param !== 'post_type') {
        return false;
    }

    $operator = isset($rule['operator']) ? (string) $rule['operator'] : '==';
    $value = isset($rule['value']) ? (string) $rule['value'] : '';
    $matched = ($value === 'all' || $value === $post_type);

    if ($operator === '!=') {
        return !$matched;
    }

    return $matched;
}

/**
 * 从 ACF 字段组定义中收集指定 post_type 字段名（即使当前 post_meta 没有值）
 */
function spe_get_post_type_acf_field_names($post_type)
{
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return [];
    }

    $groups = acf_get_field_groups();
    if (!is_array($groups) || empty($groups)) {
        return [];
    }

    $fields = [];
    foreach ($groups as $group) {
        $locations = isset($group['location']) && is_array($group['location']) ? $group['location'] : [];
        if (empty($locations)) {
            continue;
        }

        $group_matches = false;
        foreach ($locations as $and_rules) {
            if (!is_array($and_rules) || empty($and_rules)) {
                continue;
            }

            $has_post_type_rule = false;
            $all_post_type_rules_match = true;
            foreach ($and_rules as $rule) {
                $param = isset($rule['param']) ? (string) $rule['param'] : '';
                if ($param !== 'post_type') {
                    continue;
                }

                $has_post_type_rule = true;
                if (!spe_acf_location_rule_matches_post_type($rule, $post_type)) {
                    $all_post_type_rules_match = false;
                    break;
                }
            }

            if ($has_post_type_rule && $all_post_type_rules_match) {
                $group_matches = true;
                break;
            }
        }

        if (!$group_matches) {
            continue;
        }

        $group_key = isset($group['key']) ? (string) $group['key'] : '';
        if ($group_key === '') {
            continue;
        }

        $group_fields = acf_get_fields($group_key);
        if (!is_array($group_fields) || empty($group_fields)) {
            continue;
        }

        foreach ($group_fields as $field) {
            $name = isset($field['name']) ? trim((string) $field['name']) : '';
            if ($name === '' || strpos($name, '_') === 0) {
                continue;
            }
            $fields[] = $name;
        }
    }

    $fields = array_values(array_unique($fields));
    sort($fields);
    return $fields;
}

/**
 * 合并导出自定义字段（meta 实际值 + ACF 定义），并排除内部字段
 */
function spe_merge_export_custom_fields($meta_keys, $exclude_keys, $acf_defined_fields = [])
{
    $meta_keys = is_array($meta_keys) ? $meta_keys : [];
    $exclude_keys = is_array($exclude_keys) ? $exclude_keys : [];
    $acf_defined_fields = is_array($acf_defined_fields) ? $acf_defined_fields : [];

    $custom_fields = array_values(array_diff($meta_keys, $exclude_keys));
    $custom_fields = array_values(array_unique(array_merge($custom_fields, $acf_defined_fields)));
    sort($custom_fields);

    return $custom_fields;
}

/**
 * 统一读取文章/页面 SEO 标题与描述（AIOSEO > Rank Math > Yoast）
 */
function spe_get_post_seo_meta($post_id)
{
    $meta_title = '';
    $meta_desc = '';

    $aioseo_title = get_post_meta($post_id, '_aioseo_title', true);
    if (is_array($aioseo_title)) {
        $meta_title = $aioseo_title['title'] ?? '';
    } elseif (is_string($aioseo_title)) {
        $meta_title = $aioseo_title;
    }
    if (empty($meta_title)) {
        $meta_title = get_post_meta($post_id, '_aioseop_title', true);
    }

    $aioseo_desc = get_post_meta($post_id, '_aioseo_description', true);
    if (is_array($aioseo_desc)) {
        $meta_desc = $aioseo_desc['description'] ?? '';
    } elseif (is_string($aioseo_desc)) {
        $meta_desc = $aioseo_desc;
    }
    if (empty($meta_desc)) {
        $meta_desc = get_post_meta($post_id, '_aioseop_description', true);
    }

    if (empty($meta_title)) {
        $meta_title = get_post_meta($post_id, 'rank_math_title', true);
    }
    if (empty($meta_desc)) {
        $meta_desc = get_post_meta($post_id, 'rank_math_description', true);
    }

    if (empty($meta_title)) {
        $meta_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
    }
    if (empty($meta_desc)) {
        $meta_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
    }

    return [
        'title' => (string) $meta_title,
        'description' => (string) $meta_desc,
    ];
}

/**
 * 构建分类导出 CSV 字符串（用于批量 ZIP 导出）
 */
function spe_generate_taxonomy_csv_string($taxonomy = 'product_cat', $selected_fields = [])
{
    $selected = spe_resolve_taxonomy_export_fields($taxonomy, $selected_fields);
    $selected_lookup = array_fill_keys($selected, true);
    $base_fields = spe_get_taxonomy_base_export_fields();
    $custom_fields = spe_get_taxonomy_custom_fields($taxonomy);

    $selected_custom_fields = [];
    foreach ($custom_fields as $field) {
        if (!empty($selected_lookup['field:' . $field])) {
            $selected_custom_fields[] = $field;
        }
    }

    $header = [];
    foreach ($base_fields as $base_key => $base_meta) {
        if (!empty($selected_lookup[$base_key])) {
            $header[] = $base_meta['header'];
        }
    }
    foreach ($selected_custom_fields as $field) {
        $header[] = $field;
    }

    global $wpdb;
    $categories = $wpdb->get_results($wpdb->prepare(
        "SELECT t.term_id, t.name, t.slug, tt.description, tt.parent
        FROM {$wpdb->terms} t
        INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy = %s
        ORDER BY t.term_id",
        $taxonomy
    ));

    $attachment_fields = [];
    foreach ($categories as $cat) {
        foreach ($selected_custom_fields as $field) {
            if (in_array($field, $attachment_fields, true)) {
                continue;
            }
            $value = get_term_meta($cat->term_id, $field, true);
            if (is_numeric($value) && $value > 0 && wp_get_attachment_url($value)) {
                $attachment_fields[] = $field;
            }
        }
    }
    foreach ($selected_custom_fields as $field) {
        if (in_array($field, $attachment_fields, true)) {
            $header[] = $field . '_url';
        }
    }
    $attachment_field_map = array_fill_keys($attachment_fields, true);

    if (empty($header)) {
        $header = ['ID'];
    }

    $output = fopen('php://temp', 'w+');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, $header);

    foreach ($categories as $cat) {
        $id = intval($cat->term_id);
        $row = [];

        if (!empty($selected_lookup['id'])) {
            $row[] = $id;
        }
        if (!empty($selected_lookup['name'])) {
            $row[] = $cat->name;
        }
        if (!empty($selected_lookup['slug'])) {
            $row[] = $cat->slug;
        }
        if (!empty($selected_lookup['description'])) {
            $row[] = str_replace(["\r\n", "\n", "\r"], ' ', (string) $cat->description);
        }
        if (!empty($selected_lookup['parent'])) {
            $row[] = $cat->parent ? intval($cat->parent) : '';
        }

        $need_meta_title = !empty($selected_lookup['meta_title']);
        $need_meta_desc = !empty($selected_lookup['meta_description']);
        if ($need_meta_title || $need_meta_desc) {
            $seo_meta = spe_get_term_seo_meta($id, $taxonomy);
            if ($need_meta_title) {
                $row[] = $seo_meta['title'];
            }
            if ($need_meta_desc) {
                $row[] = $seo_meta['description'];
            }
        }

        $custom_values = [];
        foreach ($selected_custom_fields as $field) {
            $value = get_term_meta($id, $field, true);
            if (is_array($value)) {
                if (isset($value['url'])) {
                    $value = $value['url'];
                } elseif (isset($value['ID'])) {
                    $value = $value['ID'];
                } else {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }
            $custom_values[$field] = str_replace(["\r\n", "\n", "\r"], ' ', (string) $value);
        }

        $segments = spe_build_custom_export_row_segments($selected_custom_fields, $attachment_field_map, $custom_values);
        $row = array_merge($row, $segments['values'], $segments['urls']);

        fputcsv($output, $row);
    }

    rewind($output);
    $content = stream_get_contents($output);
    fclose($output);

    return is_string($content) ? $content : '';
}

/**
 * 构建产品导出 CSV 字符串（用于批量 ZIP 导出）
 */
function spe_generate_products_csv_string()
{
    global $wpdb;
    $products = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_name, p.post_excerpt, p.post_content
        FROM {$wpdb->posts} p
        WHERE p.post_type = 'product'
        AND p.post_status IN ('publish', 'draft', 'private')
        ORDER BY p.ID
    ");

    $output = fopen('php://temp', 'w+');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if (empty($products)) {
        fputcsv($output, ['ID', '标题', 'Slug', '短描述', '长描述']);
        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);
        return is_string($content) ? $content : '';
    }

    $all_meta_keys = $wpdb->get_col("
        SELECT DISTINCT pm.meta_key
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'product'
        AND p.post_status IN ('publish', 'draft', 'private')
        AND pm.meta_key NOT LIKE '\_%'
        ORDER BY pm.meta_key
    ");

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

    $custom_fields = spe_merge_export_custom_fields(
        $all_meta_keys,
        $exclude_keys,
        spe_get_post_type_acf_field_names('product')
    );

    $header = ['ID', '标题', 'Slug', '短描述', '长描述', 'Meta Title', 'Meta Description'];
    foreach ($custom_fields as $field) {
        $header[] = $field;
    }

    $attachment_fields = [];
    foreach ($products as $p) {
        foreach ($custom_fields as $field) {
            if (in_array($field, $attachment_fields, true)) {
                continue;
            }
            $value = get_post_meta($p->ID, $field, true);
            if (is_numeric($value) && $value > 0 && wp_get_attachment_url($value)) {
                $attachment_fields[] = $field;
            }
        }
    }
    foreach ($custom_fields as $field) {
        if (in_array($field, $attachment_fields, true)) {
            $header[] = $field . '_url';
        }
    }
    $attachment_field_map = array_fill_keys($attachment_fields, true);

    fputcsv($output, $header);

    foreach ($products as $p) {
        $id = $p->ID;
        $short_desc = str_replace(["\r\n", "\n", "\r"], ' ', (string) ($p->post_excerpt ?: ''));
        $long_desc = str_replace(["\r\n", "\n", "\r"], ' ', (string) ($p->post_content ?: ''));
        $seo_meta = spe_get_post_seo_meta($id);

        $row = [$id, $p->post_title, $p->post_name, $short_desc, $long_desc, $seo_meta['title'], $seo_meta['description']];

        $custom_values = [];
        foreach ($custom_fields as $field) {
            $value = get_post_meta($id, $field, true);
            if (is_array($value)) {
                if (isset($value['url'])) {
                    $value = $value['url'];
                } elseif (isset($value['ID'])) {
                    $value = $value['ID'];
                } elseif (isset($value[0]) && is_array($value[0])) {
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
            $custom_values[$field] = str_replace(["\r\n", "\n", "\r"], ' ', (string) $value);
        }

        $segments = spe_build_custom_export_row_segments($custom_fields, $attachment_field_map, $custom_values);
        $row = array_merge($row, $segments['values'], $segments['urls']);
        fputcsv($output, $row);
    }

    rewind($output);
    $content = stream_get_contents($output);
    fclose($output);

    return is_string($content) ? $content : '';
}

/**
 * 构建页面导出 CSV 字符串（用于批量 ZIP 导出）
 */
function spe_generate_pages_csv_string()
{
    global $wpdb;
    $pages = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_name, p.post_excerpt, p.post_content
        FROM {$wpdb->posts} p
        WHERE p.post_type = 'page'
        AND p.post_status IN ('publish', 'draft', 'private')
        ORDER BY p.ID
    ");

    $output = fopen('php://temp', 'w+');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if (empty($pages)) {
        fputcsv($output, ['ID', '标题', 'Slug', '摘要', '内容']);
        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);
        return is_string($content) ? $content : '';
    }

    $all_meta_keys = $wpdb->get_col("
        SELECT DISTINCT pm.meta_key
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'page'
        AND p.post_status IN ('publish', 'draft', 'private')
        AND pm.meta_key NOT LIKE '\_%'
        ORDER BY pm.meta_key
    ");

    $exclude_keys = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_thumbnail_id',
        '_wp_page_template',
    ];

    $custom_fields = spe_merge_export_custom_fields(
        $all_meta_keys,
        $exclude_keys,
        spe_get_post_type_acf_field_names('product')
    );

    $header = ['ID', '标题', 'Slug', '摘要', '内容', 'Meta Title', 'Meta Description'];
    foreach ($custom_fields as $field) {
        $header[] = $field;
    }

    $attachment_fields = [];
    foreach ($pages as $p) {
        foreach ($custom_fields as $field) {
            if (in_array($field, $attachment_fields, true)) {
                continue;
            }
            $value = get_post_meta($p->ID, $field, true);
            if (is_numeric($value) && $value > 0 && wp_get_attachment_url($value)) {
                $attachment_fields[] = $field;
            }
        }
    }
    foreach ($custom_fields as $field) {
        if (in_array($field, $attachment_fields, true)) {
            $header[] = $field . '_url';
        }
    }
    $attachment_field_map = array_fill_keys($attachment_fields, true);

    fputcsv($output, $header);

    foreach ($pages as $p) {
        $id = $p->ID;
        $excerpt = str_replace(["\r\n", "\n", "\r"], ' ', (string) ($p->post_excerpt ?: ''));
        $content = str_replace(["\r\n", "\n", "\r"], ' ', (string) ($p->post_content ?: ''));
        $seo_meta = spe_get_post_seo_meta($id);

        $row = [$id, $p->post_title, $p->post_name, $excerpt, $content, $seo_meta['title'], $seo_meta['description']];

        $custom_values = [];
        foreach ($custom_fields as $field) {
            $value = get_post_meta($id, $field, true);
            if (is_array($value)) {
                if (isset($value['url'])) {
                    $value = $value['url'];
                } elseif (isset($value['ID'])) {
                    $value = $value['ID'];
                } elseif (isset($value[0]) && is_array($value[0])) {
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
            $custom_values[$field] = str_replace(["\r\n", "\n", "\r"], ' ', (string) $value);
        }

        $segments = spe_build_custom_export_row_segments($custom_fields, $attachment_field_map, $custom_values);
        $row = array_merge($row, $segments['values'], $segments['urls']);
        fputcsv($output, $row);
    }

    rewind($output);
    $content = stream_get_contents($output);
    fclose($output);

    return is_string($content) ? $content : '';
}

/**
 * 构建文章导出 CSV 字符串（用于批量 ZIP 导出）
 */
function spe_generate_posts_csv_string()
{
    global $wpdb;
    $posts = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_name, p.post_excerpt, p.post_content
        FROM {$wpdb->posts} p
        WHERE p.post_type = 'post'
        AND p.post_status IN ('publish', 'draft', 'private')
        ORDER BY p.ID
    ");

    $output = fopen('php://temp', 'w+');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if (empty($posts)) {
        fputcsv($output, ['ID', '标题', 'Slug', '摘要', '内容']);
        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);
        return is_string($content) ? $content : '';
    }

    $all_meta_keys = $wpdb->get_col("
        SELECT DISTINCT pm.meta_key
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'post'
        AND p.post_status IN ('publish', 'draft', 'private')
        AND pm.meta_key NOT LIKE '\_%'
        ORDER BY pm.meta_key
    ");

    $exclude_keys = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_thumbnail_id',
        '_wp_page_template',
    ];

    $custom_fields = spe_merge_export_custom_fields(
        $all_meta_keys,
        $exclude_keys,
        spe_get_post_type_acf_field_names('page')
    );

    $header = ['ID', '标题', 'Slug', '摘要', '内容', 'Meta Title', 'Meta Description'];
    foreach ($custom_fields as $field) {
        $header[] = $field;
    }

    $attachment_fields = [];
    foreach ($posts as $p) {
        foreach ($custom_fields as $field) {
            if (in_array($field, $attachment_fields, true)) {
                continue;
            }
            $value = get_post_meta($p->ID, $field, true);
            if (is_numeric($value) && $value > 0 && wp_get_attachment_url($value)) {
                $attachment_fields[] = $field;
            }
        }
    }
    foreach ($custom_fields as $field) {
        if (in_array($field, $attachment_fields, true)) {
            $header[] = $field . '_url';
        }
    }
    $attachment_field_map = array_fill_keys($attachment_fields, true);

    fputcsv($output, $header);

    foreach ($posts as $p) {
        $id = $p->ID;
        $excerpt = str_replace(["\r\n", "\n", "\r"], ' ', (string) ($p->post_excerpt ?: ''));
        $content = str_replace(["\r\n", "\n", "\r"], ' ', (string) ($p->post_content ?: ''));
        $seo_meta = spe_get_post_seo_meta($id);

        $row = [$id, $p->post_title, $p->post_name, $excerpt, $content, $seo_meta['title'], $seo_meta['description']];

        $custom_values = [];
        foreach ($custom_fields as $field) {
            $value = get_post_meta($id, $field, true);
            if (is_array($value)) {
                if (isset($value['url'])) {
                    $value = $value['url'];
                } elseif (isset($value['ID'])) {
                    $value = $value['ID'];
                } elseif (isset($value[0]) && is_array($value[0])) {
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
            $custom_values[$field] = str_replace(["\r\n", "\n", "\r"], ' ', (string) $value);
        }

        $segments = spe_build_custom_export_row_segments($custom_fields, $attachment_field_map, $custom_values);
        $row = array_merge($row, $segments['values'], $segments['urls']);
        fputcsv($output, $row);
    }

    rewind($output);
    $content = stream_get_contents($output);
    fclose($output);

    return is_string($content) ? $content : '';
}

/**
 * 批量导出（多类型一次打包 ZIP）
 */
function spe_export_bundle($taxonomy = 'product_cat', $types = [])
{
    if (!current_user_can('manage_options')) {
        wp_die('没有权限');
    }

    $allowed_types = ['taxonomy', 'product', 'page', 'post'];
    $selected_types = array_values(array_intersect(
        $allowed_types,
        array_map(
            function ($item) {
                return sanitize_text_field((string) $item);
            },
            is_array($types) ? $types : []
        )
    ));

    if (empty($selected_types)) {
        wp_die('请至少选择一种导出类型');
    }

    if (!class_exists('ZipArchive')) {
        wp_die('服务器缺少 ZipArchive 扩展，无法生成批量导出 ZIP');
    }

    if (!taxonomy_exists($taxonomy)) {
        $taxonomy = 'product_cat';
    }

    while (ob_get_level()) {
        ob_end_clean();
    }
    set_time_limit(0);

    $timestamp = date('Y-m-d-His');
    $tmp_dir = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
    $tmp_file = tempnam($tmp_dir, 'spe_bundle_');
    if ($tmp_file === false) {
        wp_die('无法创建导出临时文件');
    }
    @unlink($tmp_file);
    $zip_path = $tmp_file . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        wp_die('无法创建 ZIP 文件');
    }

    if (in_array('taxonomy', $selected_types, true)) {
        $zip->addFromString(
            $taxonomy . '-export-' . $timestamp . '.csv',
            spe_generate_taxonomy_csv_string($taxonomy, [])
        );
    }
    if (in_array('product', $selected_types, true)) {
        $zip->addFromString(
            'products-export-' . $timestamp . '.csv',
            spe_generate_products_csv_string()
        );
    }
    if (in_array('page', $selected_types, true)) {
        $zip->addFromString(
            'pages-export-' . $timestamp . '.csv',
            spe_generate_pages_csv_string()
        );
    }
    if (in_array('post', $selected_types, true)) {
        $zip->addFromString(
            'posts-export-' . $timestamp . '.csv',
            spe_generate_posts_csv_string()
        );
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="content-export-bundle-' . $timestamp . '.zip"');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    @unlink($zip_path);
    exit;
}

function spe_admin_page()
{
    if (isset($_GET['spe_action']) && $_GET['spe_action'] === 'export_bundle') {
        $taxonomy = isset($_GET['spe_bundle_taxonomy']) ? sanitize_text_field((string) $_GET['spe_bundle_taxonomy']) : 'product_cat';
        $types = isset($_GET['spe_bundle_types']) ? (array) $_GET['spe_bundle_types'] : [];
        spe_export_bundle($taxonomy, $types);
    }
    if (isset($_GET['spe_action']) && $_GET['spe_action'] === 'export_products') {
        spe_export_products();
    }
    if (isset($_GET['spe_action']) && $_GET['spe_action'] === 'export_taxonomies') {
        $taxonomy = isset($_GET['spe_taxonomy']) ? sanitize_text_field($_GET['spe_taxonomy']) : 'product_cat';
        $selected_fields = isset($_GET['spe_tax_fields']) ? (array) $_GET['spe_tax_fields'] : [];
        spe_export_taxonomies($taxonomy, $selected_fields);
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

    $taxonomies = get_taxonomies(['public' => true], 'objects');
    if (!is_array($taxonomies) || empty($taxonomies)) {
        $taxonomies = [];
    }

    $default_taxonomy = isset($taxonomies['product_cat'])
        ? 'product_cat'
        : (!empty($taxonomies) ? array_key_first($taxonomies) : 'product_cat');

    $export_taxonomy_ui = isset($_GET['spe_taxonomy']) ? sanitize_text_field($_GET['spe_taxonomy']) : $default_taxonomy;
    if (!isset($taxonomies[$export_taxonomy_ui])) {
        $export_taxonomy_ui = $default_taxonomy;
    }
    $bundle_taxonomy_ui = isset($_GET['spe_bundle_taxonomy']) ? sanitize_text_field($_GET['spe_bundle_taxonomy']) : $default_taxonomy;
    if (!isset($taxonomies[$bundle_taxonomy_ui])) {
        $bundle_taxonomy_ui = $default_taxonomy;
    }

    $selected_tax_fields_ui = spe_resolve_taxonomy_export_fields(
        $export_taxonomy_ui,
        isset($_GET['spe_tax_fields']) ? (array) $_GET['spe_tax_fields'] : []
    );

    $taxonomy_field_options = spe_get_taxonomy_export_field_options($export_taxonomy_ui);
    $tax_field_nonce = wp_create_nonce('spe_tax_fields_nonce');

    ?>
    <div class="wrap spe-admin">
        <style>
            .spe-admin {
                --spe-bg: #f1f4fa;
                --spe-surface: #ffffff;
                --spe-ink: #1f2937;
                --spe-muted: #5f6b7a;
                --spe-border: #d7dee9;
                --spe-accent: #2a67ff;
                --spe-accent-strong: #1f4fd0;
                --spe-shadow: 0 8px 22px rgba(31, 64, 128, 0.08);
                color: var(--spe-ink);
                font-family: "Segoe UI", "Avenir Next", "PingFang SC", "Noto Sans SC", sans-serif;
                padding-bottom: 24px;
            }

            .spe-admin .spe-shell {
                background: #f7f9fd;
                border: 1px solid var(--spe-border);
                border-radius: 16px;
                padding: 20px;
                position: relative;
                overflow: hidden;
            }

            .spe-admin .spe-shell::before {
                display: none;
            }

            .spe-admin .spe-hero {
                position: relative;
                background: linear-gradient(105deg, #ffffff 0%, #f5f8ff 100%);
                border: 1px solid #d7dfed;
                border-radius: 14px;
                padding: 18px 20px;
                margin-bottom: 18px;
                box-shadow: var(--spe-shadow);
                overflow: hidden;
            }

            .spe-admin .spe-hero::after {
                display: none;
            }

            .spe-admin .spe-title {
                margin: 0;
                font-size: 28px;
                line-height: 1.2;
                letter-spacing: .015em;
            }

            .spe-admin .spe-subtitle {
                margin: 8px 0 0;
                color: var(--spe-muted);
                font-size: 14px;
            }

            .spe-admin .spe-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 16px;
            }

            .spe-admin .spe-card {
                background: var(--spe-surface);
                border: 1px solid var(--spe-border);
                border-radius: 14px;
                padding: 18px;
                box-shadow: var(--spe-shadow);
                animation: speFadeUp .35s ease both;
                position: relative;
            }

            .spe-admin .spe-card::before {
                content: "";
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: 3px;
                border-radius: 14px 14px 0 0;
                background: linear-gradient(90deg, #2a67ff 0%, #4f88ff 100%);
            }

            .spe-admin .spe-card h3 {
                margin: 0 0 10px;
                font-size: 18px;
            }

            .spe-admin .spe-card.spe-card-feature {
                background: linear-gradient(140deg, #ffffff 0%, #f5f8ff 100%);
                border-color: #ccd7ee;
            }

            .spe-admin .spe-card p {
                margin: 0 0 12px;
                color: var(--spe-muted);
            }

            .spe-admin .spe-card ul {
                margin: 0 0 12px 16px;
            }

            .spe-admin .spe-card li {
                margin-bottom: 6px;
            }

            .spe-admin .spe-section {
                margin-top: 18px;
            }

            .spe-admin .spe-section h2 {
                margin: 0 0 12px;
                font-size: 18px;
            }

            .spe-admin .spe-form-row {
                margin-bottom: 10px;
            }

            .spe-admin label {
                display: block;
                font-weight: 600;
                margin-bottom: 4px;
            }

            .spe-admin input[type="text"],
            .spe-admin input[type="file"],
            .spe-admin select {
                width: 100%;
                min-height: 40px;
                border: 1px solid #c6d7d0;
                border-radius: 10px;
                background: #fff;
                padding: 8px 10px;
                font-size: 14px;
                color: var(--spe-ink);
            }

            .spe-admin input[type="text"]:focus,
            .spe-admin input[type="file"]:focus,
            .spe-admin select:focus {
                outline: 2px solid rgba(26, 143, 109, .28);
                border-color: var(--spe-accent);
            }

            .spe-admin .spe-button-row {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-top: 12px;
            }

            .spe-admin .button.button-primary {
                background: linear-gradient(120deg, var(--spe-accent) 0%, #4c80ff 100%);
                border-color: var(--spe-accent-strong);
                color: #fff;
                min-height: 40px;
                border-radius: 10px;
            }

            .spe-admin .button.button-secondary,
            .spe-admin .button.spe-button-ghost {
                border: 1px solid #cfd8ea;
                color: #35465e;
                background: #f7f9fd;
                min-height: 40px;
                border-radius: 10px;
            }

            .spe-admin .spe-filter-panel {
                display: none;
                margin-top: 10px;
                padding: 12px;
                border: 1px solid var(--spe-border);
                border-radius: 12px;
                background: #f8fafd;
            }

            .spe-admin .spe-filter-panel.is-open {
                display: block;
            }

            .spe-admin .spe-hint {
                margin-top: 6px;
                font-size: 12px;
                color: #60706a;
            }

            .spe-admin .spe-field-picker {
                border: 1px solid var(--spe-border);
                border-radius: 12px;
                padding: 12px;
                background: #f8fafd;
                margin-top: 8px;
            }

            .spe-admin .spe-bundle-types {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 8px;
                margin-top: 10px;
            }

            .spe-admin .spe-check-item {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 10px 12px;
                border-radius: 10px;
                border: 1px solid #d3dcec;
                background: #ffffff;
                font-size: 13px;
            }

            .spe-admin .spe-check-item input[type="checkbox"] {
                margin: 0;
            }

            .spe-admin .spe-field-toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                align-items: center;
                margin-bottom: 8px;
            }

            .spe-admin .spe-field-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
                max-height: 220px;
                overflow: auto;
                padding-right: 4px;
            }

            .spe-admin .spe-field-item {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 6px 8px;
                border-radius: 8px;
                border: 1px solid #e0ece7;
                background: #fff;
                font-size: 13px;
            }

            .spe-admin .spe-field-item.is-hidden {
                display: none;
            }

            .spe-admin .spe-field-empty {
                grid-column: 1 / -1;
                padding: 10px 12px;
                border-radius: 8px;
                border: 1px dashed #c8dcd5;
                background: #ffffff;
                color: #4f6660;
                font-size: 13px;
            }

            .spe-admin .spe-field-empty.is-error {
                border-color: #e3b4b4;
                color: #8f3030;
            }

            .spe-admin .spe-pill {
                margin-left: auto;
                font-size: 11px;
                line-height: 1;
                padding: 4px 6px;
                border-radius: 999px;
                color: #0d5c45;
                background: #d8f2e8;
            }

            .spe-admin .spe-debug {
                margin-top: 8px;
                background: #f6f9f8;
                border: 1px solid #d5e2dc;
                border-radius: 12px;
                padding: 10px;
                max-height: 280px;
                overflow: auto;
                font-size: 12px;
                font-family: "SFMono-Regular", "Menlo", "Monaco", monospace;
            }

            .spe-admin .spe-guide {
                margin-top: 16px;
            }

            @keyframes speFadeUp {
                from {
                    opacity: 0;
                    transform: translateY(6px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            @media (max-width: 1120px) {
                .spe-admin .spe-grid {
                    grid-template-columns: 1fr;
                }

                .spe-admin .spe-field-list {
                    grid-template-columns: 1fr;
                }

                .spe-admin .spe-bundle-types {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <div class="spe-shell">
            <div class="spe-hero">
                <h1 class="spe-title">内容导入导出工具</h1>
                <p class="spe-subtitle">支持产品、页面、文章与分类批量导入导出；分类支持字段级按需导出，便于最小化修改后回导。</p>
            </div>

            <?php if (isset($result)): ?>
                <div class="notice <?php echo $result['error'] ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                    <p><?php echo esc_html($result['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($result['debug'])): ?>
                <div class="spe-card">
                    <h3>调试信息</h3>
                    <pre class="spe-debug"><?php echo esc_html($result['debug']); ?></pre>
                </div>
            <?php endif; ?>

            <section class="spe-section">
                <h2>批量导出</h2>
                <div class="spe-grid">
                    <div class="spe-card spe-card-feature">
                        <h3>多类型一次导出（ZIP）</h3>
                        <p>可一次勾选并导出分类、页面、文章，下载后每种类型独立一个 CSV 文件。</p>
                        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" id="spe-bundle-export-form">
                            <input type="hidden" name="page" value="content-import-export">
                            <input type="hidden" name="spe_action" value="export_bundle">

                            <div class="spe-form-row">
                                <label for="spe-bundle-taxonomy">分类法（用于分类 CSV）</label>
                                <select id="spe-bundle-taxonomy" name="spe_bundle_taxonomy">
                                    <?php
                                    foreach ($taxonomies as $tax) {
                                        $selected_attr = selected($tax->name, $bundle_taxonomy_ui, false);
                                        echo '<option value="' . esc_attr($tax->name) . '" ' . $selected_attr . '>' . esc_html($tax->label) . ' (' . esc_html($tax->name) . ')</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="spe-bundle-types">
                                <label class="spe-check-item"><input type="checkbox" name="spe_bundle_types[]" value="taxonomy" checked>分类（taxonomy）</label>
                                <label class="spe-check-item"><input type="checkbox" name="spe_bundle_types[]" value="product" checked>产品（product）</label>
                                <label class="spe-check-item"><input type="checkbox" name="spe_bundle_types[]" value="page" checked>页面（page）</label>
                                <label class="spe-check-item"><input type="checkbox" name="spe_bundle_types[]" value="post" checked>文章（post）</label>
                            </div>
                            <p class="spe-hint">至少勾选一个类型。支持一次性导出 taxonomy/product/page/post。</p>

                            <div class="spe-button-row">
                                <button type="submit" class="button button-primary">下载批量 ZIP</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <section class="spe-section">
                <h2>产品</h2>
                <div class="spe-grid">
                    <div class="spe-card">
                        <h3>产品导出</h3>
                        <p>导出产品 CSV，支持分类和关键词筛选。</p>
                        <ul>
                            <li>基础字段：ID、标题、Slug</li>
                            <li>描述字段：短描述、长描述</li>
                            <li>SEO 字段：Meta Title / Meta Description</li>
                            <li>自定义字段：ACF 与普通 Meta</li>
                        </ul>
                        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" id="export-products-form">
                            <input type="hidden" name="page" value="content-import-export">
                            <input type="hidden" name="spe_action" value="export_products">

                            <button type="button" class="button spe-button-ghost" data-toggle-target="product-filter-panel"
                                data-expand-label="展开筛选选项" data-collapse-label="收起筛选选项">
                                展开筛选选项
                            </button>

                            <div id="product-filter-panel" class="spe-filter-panel">
                                <div class="spe-form-row">
                                    <label>分类筛选</label>
                                    <select name="spe_categories[]" multiple size="5">
                                        <?php
                                        $product_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                                        if (is_array($product_cats)) {
                                            foreach ($product_cats as $cat) {
                                                echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . ' (ID: ' . intval($cat->term_id) . ')</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                    <div class="spe-hint">按住 Ctrl/Cmd 可多选</div>
                                </div>

                                <div class="spe-form-row">
                                    <label>关键词搜索</label>
                                    <input type="text" name="spe_keyword" placeholder="搜索标题或内容">
                                </div>

                                <div class="spe-form-row">
                                    <select name="spe_keyword_scope">
                                        <option value="all">搜索范围：全部（标题+内容）</option>
                                        <option value="title">搜索范围：仅标题</option>
                                        <option value="content">搜索范围：仅内容</option>
                                    </select>
                                </div>

                                <button type="button" class="button spe-button-ghost" data-filter-reset="#export-products-form">
                                    重置筛选
                                </button>
                            </div>

                            <div class="spe-button-row">
                                <button type="submit" class="button button-primary">下载产品 CSV</button>
                            </div>
                        </form>
                    </div>

                    <div class="spe-card">
                        <h3>产品导入</h3>
                        <p>上传 UTF-8 编码 CSV 文件，按 ID 更新产品。</p>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('spe_import', 'spe_import_products'); ?>
                            <input type="file" name="spe_import_file" accept=".csv" required>
                            <div class="spe-button-row">
                                <button type="submit" class="button button-secondary">上传产品 CSV</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <section class="spe-section">
                <h2>页面</h2>
                <div class="spe-grid">
                    <div class="spe-card">
                        <h3>页面导出</h3>
                        <p>导出页面 CSV，包含基础字段、内容、SEO 与自定义字段。</p>
                        <div class="spe-button-row">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=content-import-export&spe_action=export_pages')); ?>"
                                class="button button-primary">
                                下载页面 CSV
                            </a>
                        </div>
                    </div>

                    <div class="spe-card">
                        <h3>页面导入</h3>
                        <p>上传 CSV 并按 ID 更新页面内容。</p>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('spe_import_pages', 'spe_import_pages'); ?>
                            <input type="file" name="spe_import_pages_file" accept=".csv" required>
                            <div class="spe-button-row">
                                <button type="submit" class="button button-secondary">上传页面 CSV</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <section class="spe-section">
                <h2>文章</h2>
                <div class="spe-grid">
                    <div class="spe-card">
                        <h3>文章导出</h3>
                        <p>导出文章 CSV，支持分类和关键词筛选。</p>
                        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" id="export-posts-form">
                            <input type="hidden" name="page" value="content-import-export">
                            <input type="hidden" name="spe_action" value="export_posts">

                            <button type="button" class="button spe-button-ghost" data-toggle-target="posts-filter-panel"
                                data-expand-label="展开筛选选项" data-collapse-label="收起筛选选项">
                                展开筛选选项
                            </button>

                            <div id="posts-filter-panel" class="spe-filter-panel">
                                <div class="spe-form-row">
                                    <label>分类筛选</label>
                                    <select name="spe_categories[]" multiple size="5">
                                        <?php
                                        $post_cats = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
                                        if (is_array($post_cats)) {
                                            foreach ($post_cats as $cat) {
                                                echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . ' (ID: ' . intval($cat->term_id) . ')</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                    <div class="spe-hint">按住 Ctrl/Cmd 可多选</div>
                                </div>

                                <div class="spe-form-row">
                                    <label>关键词搜索</label>
                                    <input type="text" name="spe_keyword" placeholder="搜索标题或内容">
                                </div>

                                <div class="spe-form-row">
                                    <select name="spe_keyword_scope">
                                        <option value="all">搜索范围：全部（标题+内容）</option>
                                        <option value="title">搜索范围：仅标题</option>
                                        <option value="content">搜索范围：仅内容</option>
                                    </select>
                                </div>

                                <button type="button" class="button spe-button-ghost" data-filter-reset="#export-posts-form">
                                    重置筛选
                                </button>
                            </div>

                            <div class="spe-button-row">
                                <button type="submit" class="button button-primary">下载文章 CSV</button>
                            </div>
                        </form>
                    </div>

                    <div class="spe-card">
                        <h3>文章导入</h3>
                        <p>上传 CSV 并按 ID 更新文章内容。</p>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('spe_import_posts', 'spe_import_posts'); ?>
                            <input type="file" name="spe_import_posts_file" accept=".csv" required>
                            <div class="spe-button-row">
                                <button type="submit" class="button button-secondary">上传文章 CSV</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <section class="spe-section">
                <h2>分类与自定义分类法</h2>
                <div class="spe-grid">
                    <div class="spe-card">
                        <h3>分类导出（支持字段选择）</h3>
                        <p>可按分类法选择字段后导出，适合最小化修改再回导。</p>
                        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" id="spe-tax-export-form">
                            <input type="hidden" name="page" value="content-import-export">
                            <input type="hidden" name="spe_action" value="export_taxonomies">

                            <div class="spe-form-row">
                                <label for="spe-taxonomy-select">选择分类法</label>
                                <select id="spe-taxonomy-select" name="spe_taxonomy">
                                    <?php
                                    foreach ($taxonomies as $tax) {
                                        $selected_attr = selected($tax->name, $export_taxonomy_ui, false);
                                        echo '<option value="' . esc_attr($tax->name) . '" ' . $selected_attr . '>' . esc_html($tax->label) . ' (' . esc_html($tax->name) . ')</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="spe-field-picker">
                                <div class="spe-field-toolbar">
                                    <label for="spe-tax-field-search" style="margin:0;">导出字段</label>
                                    <button type="button" class="button spe-button-ghost" id="spe-tax-select-all">全选</button>
                                    <button type="button" class="button spe-button-ghost" id="spe-tax-clear">清空</button>
                                </div>
                                <input type="text" id="spe-tax-field-search" placeholder="搜索字段名...">
                                <div class="spe-field-list" id="spe-tax-field-list"></div>
                                <p class="spe-hint">ID 为必选字段；默认全选。建议仅保留需要修改的字段做最小化导入。</p>
                            </div>

                            <div class="spe-button-row">
                                <button type="submit" class="button button-primary">下载分类 CSV</button>
                            </div>
                        </form>
                    </div>

                    <div class="spe-card">
                        <h3>分类导入</h3>
                        <p>上传 CSV 并按 ID/term_id 更新分类字段。</p>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('spe_import_tax', 'spe_import_taxonomies'); ?>

                            <div class="spe-form-row">
                                <label>选择目标分类法</label>
                                <select name="spe_taxonomy">
                                    <?php
                                    foreach ($taxonomies as $tax) {
                                        $selected_attr = selected($tax->name, $default_taxonomy, false);
                                        echo '<option value="' . esc_attr($tax->name) . '" ' . $selected_attr . '>' . esc_html($tax->label) . ' (' . esc_html($tax->name) . ')</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <input type="file" name="spe_import_taxonomy_file" accept=".csv" required>
                            <div class="spe-button-row">
                                <button type="submit" class="button button-secondary">上传分类 CSV</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <section class="spe-section spe-guide">
                <div class="spe-card">
                    <h3>使用说明</h3>
                    <ul>
                        <li>导入通过 ID 列匹配并更新目标内容。</li>
                        <li>批量导出可一次下载 taxonomy/product/page/post 多类 CSV（ZIP）。</li>
                        <li>分类导出支持字段级选择，适合最小化回导流程。</li>
                        <li>附件字段会自动补充对应的 <code>_url</code> 辅助列。</li>
                        <li>建议始终从目标站点先导出一份最新 CSV 作为模板。</li>
                    </ul>
                </div>
            </section>
        </div>

        <script>
            (function () {
                const root = document.querySelector('.spe-admin');
                if (!root) return;

                root.querySelectorAll('[data-toggle-target]').forEach((button) => {
                    const targetId = button.getAttribute('data-toggle-target');
                    const panel = document.getElementById(targetId);
                    if (!panel) return;

                    button.addEventListener('click', () => {
                        const isOpen = panel.classList.toggle('is-open');
                        const expandLabel = button.getAttribute('data-expand-label') || '展开';
                        const collapseLabel = button.getAttribute('data-collapse-label') || '收起';
                        button.textContent = isOpen ? collapseLabel : expandLabel;
                    });
                });

                root.querySelectorAll('[data-filter-reset]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const selector = button.getAttribute('data-filter-reset');
                        const form = selector ? document.querySelector(selector) : null;
                        if (!form) return;

                        form.querySelectorAll('select[multiple]').forEach((el) => {
                            Array.from(el.options).forEach((opt) => {
                                opt.selected = false;
                            });
                        });
                        form.querySelectorAll('input[type="text"]').forEach((el) => {
                            el.value = '';
                        });
                        form.querySelectorAll('select:not([multiple])').forEach((el) => {
                            if (el.options.length > 0) {
                                el.selectedIndex = 0;
                            }
                        });
                    });
                });

                const bundleForm = document.getElementById('spe-bundle-export-form');
                if (bundleForm) {
                    bundleForm.addEventListener('submit', (event) => {
                        const checked = bundleForm.querySelectorAll('input[name="spe_bundle_types[]"]:checked');
                        if (checked.length === 0) {
                            event.preventDefault();
                            window.alert('请至少选择一种导出类型');
                        }
                    });
                }

                const initialTaxonomy = <?php echo wp_json_encode($export_taxonomy_ui); ?>;
                const fieldMap = {};
                fieldMap[initialTaxonomy] = <?php echo wp_json_encode($taxonomy_field_options); ?>;
                const pendingFieldLoads = {};
                const selectedByTax = <?php echo wp_json_encode([$export_taxonomy_ui => $selected_tax_fields_ui]); ?>;
                const ajaxEndpoint = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                const taxFieldNonce = <?php echo wp_json_encode($tax_field_nonce); ?>;
                const taxonomySelect = document.getElementById('spe-taxonomy-select');
                const fieldList = document.getElementById('spe-tax-field-list');
                const searchInput = document.getElementById('spe-tax-field-search');
                const selectAllButton = document.getElementById('spe-tax-select-all');
                const clearButton = document.getElementById('spe-tax-clear');

                if (!taxonomySelect || !fieldList) return;

                function getCurrentSelection() {
                    return Array.from(fieldList.querySelectorAll('input[name="spe_tax_fields[]"]:checked')).map((input) => input.value);
                }

                function ensureIdSelection(values) {
                    const normalized = Array.isArray(values) ? values.slice() : [];
                    if (!normalized.includes('id')) {
                        normalized.unshift('id');
                    }
                    return normalized;
                }

                function renderFieldState(message, isError) {
                    fieldList.innerHTML = '';
                    const emptyNode = document.createElement('div');
                    emptyNode.className = 'spe-field-empty' + (isError ? ' is-error' : '');
                    emptyNode.textContent = message;
                    fieldList.appendChild(emptyNode);
                }

                function loadFieldOptions(taxonomy) {
                    if (Array.isArray(fieldMap[taxonomy])) {
                        return Promise.resolve(fieldMap[taxonomy]);
                    }

                    if (pendingFieldLoads[taxonomy]) {
                        return pendingFieldLoads[taxonomy];
                    }

                    const body = new URLSearchParams({
                        action: 'spe_get_taxonomy_export_fields',
                        taxonomy: taxonomy,
                        nonce: taxFieldNonce
                    });

                    pendingFieldLoads[taxonomy] = fetch(ajaxEndpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: body.toString()
                    }).then(async (response) => {
                        let payload = null;
                        try {
                            payload = await response.json();
                        } catch (error) {
                            payload = null;
                        }

                        if (!response.ok || !payload || !payload.success || !payload.data || !Array.isArray(payload.data.options)) {
                            throw new Error('failed to load taxonomy fields');
                        }

                        fieldMap[taxonomy] = payload.data.options;
                        return fieldMap[taxonomy];
                    }).finally(() => {
                        delete pendingFieldLoads[taxonomy];
                    });

                    return pendingFieldLoads[taxonomy];
                }

                let renderToken = 0;

                async function renderFieldList(resetToDefault) {
                    const taxonomy = taxonomySelect.value;
                    const token = ++renderToken;

                    renderFieldState('字段加载中...', false);

                    let options = [];
                    try {
                        options = await loadFieldOptions(taxonomy);
                    } catch (error) {
                        if (token !== renderToken) {
                            return;
                        }
                        renderFieldState('字段加载失败，请刷新后重试。', true);
                        return;
                    }

                    if (token !== renderToken) {
                        return;
                    }

                    if (!Array.isArray(options) || options.length === 0) {
                        selectedByTax[taxonomy] = ['id'];
                        renderFieldState('当前分类法暂无可导出字段。', false);
                        return;
                    }

                    const previous = resetToDefault ? [] : (selectedByTax[taxonomy] || getCurrentSelection());
                    const selectedSet = new Set(ensureIdSelection(previous.length ? previous : options.map((o) => o.value)));

                    fieldList.innerHTML = '';

                    options.forEach((option) => {
                        const item = document.createElement('label');
                        item.className = 'spe-field-item';
                        item.dataset.label = String(option.label || '').toLowerCase();

                        const input = document.createElement('input');
                        input.type = 'checkbox';
                        input.name = 'spe_tax_fields[]';
                        input.value = option.value;
                        input.checked = selectedSet.has(option.value);
                        if (option.value === 'id') {
                            input.dataset.required = '1';
                        }

                        const text = document.createElement('span');
                        text.textContent = option.label;

                        item.appendChild(input);
                        item.appendChild(text);

                        if (option.value === 'id') {
                            const pill = document.createElement('span');
                            pill.className = 'spe-pill';
                            pill.textContent = '必选';
                            item.appendChild(pill);
                        }

                        fieldList.appendChild(item);
                    });

                    selectedByTax[taxonomy] = ensureIdSelection(Array.from(selectedSet));
                    applySearchFilter();
                }

                function applySearchFilter() {
                    const keyword = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
                    fieldList.querySelectorAll('.spe-field-item').forEach((item) => {
                        if (!keyword || item.dataset.label.includes(keyword)) {
                            item.classList.remove('is-hidden');
                        } else {
                            item.classList.add('is-hidden');
                        }
                    });
                }

                fieldList.addEventListener('change', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLInputElement) || target.name !== 'spe_tax_fields[]') return;
                    if (target.dataset.required === '1' && !target.checked) {
                        target.checked = true;
                    }
                    selectedByTax[taxonomySelect.value] = ensureIdSelection(getCurrentSelection());
                });

                taxonomySelect.addEventListener('change', () => {
                    renderFieldList(true);
                });

                if (searchInput) {
                    searchInput.addEventListener('input', applySearchFilter);
                }

                if (selectAllButton) {
                    selectAllButton.addEventListener('click', () => {
                        fieldList.querySelectorAll('input[name="spe_tax_fields[]"]').forEach((input) => {
                            input.checked = true;
                        });
                        selectedByTax[taxonomySelect.value] = ensureIdSelection(getCurrentSelection());
                    });
                }

                if (clearButton) {
                    clearButton.addEventListener('click', () => {
                        fieldList.querySelectorAll('input[name="spe_tax_fields[]"]').forEach((input) => {
                            if (input.dataset.required === '1') {
                                input.checked = true;
                            } else {
                                input.checked = false;
                            }
                        });
                        selectedByTax[taxonomySelect.value] = ensureIdSelection(getCurrentSelection());
                    });
                }

                renderFieldList(false);
            })();
        </script>
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

    // 扫描当前导出范围内全部产品，避免遗漏字段
    $all_meta_keys = $wpdb->get_col("
        SELECT DISTINCT pm.meta_key
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE {$where_clause}
        AND pm.meta_key NOT LIKE '\_%'
        ORDER BY pm.meta_key
    ");

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

    $custom_fields = spe_merge_export_custom_fields(
        $all_meta_keys,
        $exclude_keys,
        spe_get_post_type_acf_field_names('post')
    );

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
    $attachment_field_map = array_fill_keys($attachment_fields, true);

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

        $custom_values = [];
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
            $custom_values[$field] = $value;
        }

        $segments = spe_build_custom_export_row_segments($custom_fields, $attachment_field_map, $custom_values);
        $row = array_merge($row, $segments['values'], $segments['urls']);
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/**
 * 导出分类（支持自定义分类法、可选字段）
 */
function spe_export_taxonomies($taxonomy = 'product_cat', $selected_fields = [])
{
    if (!current_user_can('manage_options')) {
        wp_die('没有权限');
    }

    while (ob_get_level()) {
        ob_end_clean();
    }
    set_time_limit(0);

    $selected = spe_resolve_taxonomy_export_fields($taxonomy, $selected_fields);
    $selected_lookup = array_fill_keys($selected, true);
    $base_fields = spe_get_taxonomy_base_export_fields();
    $custom_fields = spe_get_taxonomy_custom_fields($taxonomy);

    $selected_custom_fields = [];
    foreach ($custom_fields as $field) {
        if (!empty($selected_lookup['field:' . $field])) {
            $selected_custom_fields[] = $field;
        }
    }

    $header = [];
    foreach ($base_fields as $base_key => $base_meta) {
        if (!empty($selected_lookup[$base_key])) {
            $header[] = $base_meta['header'];
        }
    }
    foreach ($selected_custom_fields as $field) {
        $header[] = $field;
    }

    global $wpdb;
    $categories = $wpdb->get_results($wpdb->prepare(
        "SELECT t.term_id, t.name, t.slug, tt.description, tt.parent
        FROM {$wpdb->terms} t
        INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
        WHERE tt.taxonomy = %s
        ORDER BY t.term_id",
        $taxonomy
    ));

    // 检测当前选中字段中哪些是附件字段，补充 *_url 辅助列
    $attachment_fields = [];
    foreach ($categories as $cat) {
        foreach ($selected_custom_fields as $field) {
            if (in_array($field, $attachment_fields, true)) {
                continue;
            }
            $value = get_term_meta($cat->term_id, $field, true);
            if (is_numeric($value) && $value > 0 && wp_get_attachment_url($value)) {
                $attachment_fields[] = $field;
            }
        }
    }
    foreach ($selected_custom_fields as $field) {
        if (in_array($field, $attachment_fields, true)) {
            $header[] = $field . '_url';
        }
    }
    $attachment_field_map = array_fill_keys($attachment_fields, true);

    if (empty($header)) {
        $header = ['ID'];
    }

    $filename = $taxonomy . '-export-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, $header);

    foreach ($categories as $cat) {
        $id = intval($cat->term_id);
        $row = [];

        if (!empty($selected_lookup['id'])) {
            $row[] = $id;
        }
        if (!empty($selected_lookup['name'])) {
            $row[] = $cat->name;
        }
        if (!empty($selected_lookup['slug'])) {
            $row[] = $cat->slug;
        }
        if (!empty($selected_lookup['description'])) {
            $row[] = str_replace(["\r\n", "\n", "\r"], ' ', (string) $cat->description);
        }
        if (!empty($selected_lookup['parent'])) {
            $row[] = $cat->parent ? intval($cat->parent) : '';
        }

        $need_meta_title = !empty($selected_lookup['meta_title']);
        $need_meta_desc = !empty($selected_lookup['meta_description']);
        if ($need_meta_title || $need_meta_desc) {
            $seo_meta = spe_get_term_seo_meta($id, $taxonomy);
            if ($need_meta_title) {
                $row[] = $seo_meta['title'];
            }
            if ($need_meta_desc) {
                $row[] = $seo_meta['description'];
            }
        }

        $custom_values = [];
        foreach ($selected_custom_fields as $field) {
            $value = get_term_meta($id, $field, true);
            if (is_array($value)) {
                if (isset($value['url'])) {
                    $value = $value['url'];
                } elseif (isset($value['ID'])) {
                    $value = $value['ID'];
                } else {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }
            $custom_values[$field] = str_replace(["\r\n", "\n", "\r"], ' ', (string) $value);
        }

        $segments = spe_build_custom_export_row_segments($selected_custom_fields, $attachment_field_map, $custom_values);
        $row = array_merge($row, $segments['values'], $segments['urls']);

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
    if (spe_is_zip_signature_file($file)) {
        return ['error' => true, 'message' => '导入失败：你上传的不是纯 CSV 文件（检测到 PK 文件头，通常是 ZIP/XLSX）。请直接上传 .csv 文本文件。'];
    }
    list($handle, $delimiter) = spe_open_csv_file($file);
    if (!$handle)
        return ['error' => true, 'message' => '无法读取文件'];

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        fclose($handle);
        return ['error' => true, 'message' => 'CSV 文件为空或格式错误'];
    }
    $header = spe_normalize_csv_header($header);

    $id_col = spe_find_header_col($header, ['ID']);
    $title_col = spe_find_header_col($header, ['标题', 'Title']);
    $slug_col = spe_find_header_col($header, ['Slug']);
    $short_desc_col = spe_find_header_col($header, ['短描述', 'Short Description']);
    $long_desc_col = spe_find_header_col($header, ['长描述', 'Long Description']);
    $meta_title_col = spe_find_header_col($header, ['Meta Title']);
    $meta_desc_col = spe_find_header_col($header, ['Meta Description']);

    if ($id_col === false) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：缺少 ID 列（支持 ID，大小写不敏感）。'];
    }

    $custom_cols = [];
    foreach ($header as $idx => $col_name) {
        $col_lower = strtolower((string) $col_name);
        // 排除所有已知的标准列（中英文）
        if (!in_array($col_lower, ['id', '标题', 'title', 'slug', '短描述', 'short description', '长描述', 'long description', 'meta title', 'meta description'], true)) {
            if (spe_should_skip_helper_url_column($col_name, $header)) {
                continue;
            }
            $custom_cols[$idx] = $col_name;
        }
    }

    $updated = 0;
    $not_found = 0;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $product_id = ($id_col !== false && isset($row[$id_col])) ? intval(preg_replace('/[^0-9]/', '', (string) $row[$id_col])) : 0;
        if (!$product_id)
            continue;

        $product = wc_get_product($product_id);
        if (!$product) {
            $not_found++;
            continue;
        }

        // 更新基础字段
        $update_data = [];
        if ($title_col !== false && isset($row[$title_col]) && $row[$title_col] !== '') {
            $update_data['post_title'] = $row[$title_col];
        }
        if ($slug_col !== false && isset($row[$slug_col]) && $row[$slug_col] !== '') {
            $update_data['post_name'] = sanitize_title($row[$slug_col]);
        }
        if ($short_desc_col !== false && isset($row[$short_desc_col]) && $row[$short_desc_col] !== '') {
            $update_data['post_excerpt'] = $row[$short_desc_col];
        }
        if ($long_desc_col !== false && isset($row[$long_desc_col]) && $row[$long_desc_col] !== '') {
            $update_data['post_content'] = $row[$long_desc_col];
        }

        if (!empty($update_data)) {
            $update_data['ID'] = $product_id;
            wp_update_post($update_data);
        }

        $meta_title_value = ($meta_title_col !== false && isset($row[$meta_title_col]) && $row[$meta_title_col] !== '')
            ? $row[$meta_title_col]
            : null;
        $meta_desc_value = ($meta_desc_col !== false && isset($row[$meta_desc_col]) && $row[$meta_desc_col] !== '')
            ? $row[$meta_desc_col]
            : null;
        if ($meta_title_value !== null || $meta_desc_value !== null) {
            spe_sync_post_seo_meta_by_active_provider($product_id, $meta_title_value, $meta_desc_value);
        }

        // 自定义字段
        foreach ($custom_cols as $idx => $field_name) {
            $value = $row[$idx] ?? '';
            if ($value !== '') {
                spe_update_post_custom_field($product_id, $field_name, $value);
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
    if (spe_is_zip_signature_file($file)) {
        return ['error' => true, 'message' => '导入失败：你上传的不是纯 CSV 文件（检测到 PK 文件头，通常是 ZIP/XLSX）。请直接上传 .csv 文本文件。'];
    }
    list($handle, $delimiter) = spe_open_csv_file($file);
    if (!$handle)
        return ['error' => true, 'message' => '无法读取文件'];

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        fclose($handle);
        return ['error' => true, 'message' => 'CSV 文件为空或格式错误'];
    }
    $header = spe_normalize_csv_header($header);

    $id_col = spe_find_header_col($header, ['ID', 'term_id']);
    $name_col = spe_find_header_col($header, ['标题', '名称', 'name']);
    $slug_col = spe_find_header_col($header, ['Slug', 'slug']);

    // 匹配描述列（AIOSEO用description作为SEO描述，不是分类描述）
    $desc_col = spe_find_header_col($header, ['描述']);

    // 匹配父分类ID列
    $parent_col = spe_find_header_col($header, ['父分类 ID', 'parent']);

    // 匹配SEO标题列（AIOSEO用title列）
    $meta_title_col = spe_find_header_col($header, ['Meta Title', 'title']);

    // 匹配SEO描述列（AIOSEO用description列）
    $meta_desc_col = spe_find_header_col($header, ['Meta Description', 'description']);

    if ($id_col === false) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：缺少 ID 或 term_id 列。'];
    }

    $custom_cols = [];
    foreach ($header as $idx => $col_name) {
        $col_lower = strtolower((string) $col_name);
        // 排除已知的标准列
        if (!in_array($col_lower, ['id', 'term_id', '标题', '名称', 'name', 'slug', '描述', 'parent', '父分类 id', 'meta title', 'meta description', 'title', 'description'], true)) {
            if (spe_should_skip_helper_url_column($col_name, $header)) {
                continue;
            }
            $custom_cols[$idx] = $col_name;
        }
    }

    $active_seo_provider = spe_get_active_seo_provider();
    $updated = 0;
    $not_found = 0;
    $no_changes = 0;
    $processed = 0;
    $row_count = 0;
    $invalid_id = 0;
    $errors = [];
    $slug_changed = false;

    $debug_lines = [];
    $max_debug_lines = 200;
    $append_debug = function ($line) use (&$debug_lines, $max_debug_lines) {
        if (count($debug_lines) < $max_debug_lines) {
            $debug_lines[] = $line;
        }
    };

    $append_debug("分类导入开始 - " . date('Y-m-d H:i:s'));
    $append_debug("SEO同步策略: " . ($active_seo_provider !== '' ? $active_seo_provider : '未检测到激活插件（跳过SEO写入）'));

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row_count++;
        $cat_id_raw = ($id_col !== false && isset($row[$id_col])) ? trim((string) $row[$id_col]) : '';
        $cat_id = intval(preg_replace('/[^0-9]/', '', $cat_id_raw));

        if ($cat_id <= 0) {
            $invalid_id++;
            $append_debug("第 {$row_count} 行: ID 为空或无效，已跳过");
            continue;
        }

        $cat = get_term($cat_id, $taxonomy);
        if (!$cat || is_wp_error($cat)) {
            $not_found++;
            $append_debug("第 {$row_count} 行: ID {$cat_id} 未找到，已跳过");
            continue;
        }

        $processed++;
        $row_modified = false;
        $update_data = [];
        $row_slug_changed = false;

        if ($name_col !== false && isset($row[$name_col]) && $row[$name_col] !== '') {
            $new_name = $row[$name_col];
            if ((string) $cat->name !== (string) $new_name) {
                $update_data['name'] = $new_name;
            }
        }

        if ($slug_col !== false && isset($row[$slug_col]) && trim((string) $row[$slug_col]) !== '') {
            $old_slug = (string) $cat->slug;
            $new_slug_raw = (string) $row[$slug_col];

            if (strpos($new_slug_raw, '/') !== false) {
                $slug_parts = explode('/', $new_slug_raw);
                $new_slug_raw = (string) end($slug_parts);
            }

            $new_slug = sanitize_title($new_slug_raw);
            if ($old_slug !== $new_slug) {
                $update_data['slug'] = $new_slug;
                $row_slug_changed = true;
            }
        }

        if ($desc_col !== false && isset($row[$desc_col]) && $row[$desc_col] !== '') {
            $new_desc = $row[$desc_col];
            if ((string) $cat->description !== (string) $new_desc) {
                $update_data['description'] = $new_desc;
            }
        }

        if (!empty($update_data)) {
            $result = wp_update_term($cat_id, $taxonomy, $update_data);
            if (is_wp_error($result)) {
                $error_msg = "第 {$row_count} 行: ID {$cat_id} 更新失败 - " . $result->get_error_message();
                $errors[] = $error_msg;
                $append_debug($error_msg);
                continue;
            }
            $row_modified = true;
            if ($row_slug_changed) {
                $slug_changed = true;
            }
        }

        if ($parent_col !== false && isset($row[$parent_col])) {
            $parent_raw = trim((string) $row[$parent_col]);
            if ($parent_raw !== '') {
                $parent_id = intval(preg_replace('/[^0-9]/', '', $parent_raw));
                $old_parent = intval($cat->parent);
                if ($old_parent !== $parent_id) {
                    $parent_result = wp_update_term($cat_id, $taxonomy, ['parent' => $parent_id]);
                    if (is_wp_error($parent_result)) {
                        $error_msg = "第 {$row_count} 行: ID {$cat_id} 父分类更新失败 - " . $parent_result->get_error_message();
                        $errors[] = $error_msg;
                        $append_debug($error_msg);
                    } else {
                        $row_modified = true;
                    }
                }
            }
        }

        $meta_title_value = ($meta_title_col !== false && isset($row[$meta_title_col]) && $row[$meta_title_col] !== '')
            ? $row[$meta_title_col]
            : null;
        $meta_desc_value = ($meta_desc_col !== false && isset($row[$meta_desc_col]) && $row[$meta_desc_col] !== '')
            ? $row[$meta_desc_col]
            : null;

        if ($meta_title_value !== null || $meta_desc_value !== null) {
            $sync_result = spe_sync_term_seo_meta_by_active_provider($cat_id, $taxonomy, $meta_title_value, $meta_desc_value);
            if ($sync_result['provider'] === '') {
                $append_debug("第 {$row_count} 行: ID {$cat_id} 未检测到激活 SEO 插件，SEO 字段已跳过");
            } else {
                $row_modified = true;
            }
        }

        foreach ($custom_cols as $idx => $field_name) {
            $value = $row[$idx] ?? '';
            if ($value === '') {
                continue;
            }
            spe_update_term_custom_field($cat_id, $field_name, $value);
            $row_modified = true;
        }

        if ($row_modified) {
            $updated++;
        } else {
            $no_changes++;
        }
    }

    fclose($handle);

    if ($slug_changed) {
        flush_rewrite_rules();
        $append_debug("检测到 Slug 变化，已刷新 permalink 结构");
    }

    if (count($debug_lines) >= $max_debug_lines) {
        $debug_lines[] = "调试输出已截断（最多 {$max_debug_lines} 行）";
    }

    $debug_lines[] = "汇总: 数据行 {$row_count}，有效ID {$processed}，更新 {$updated}，无变化 {$no_changes}，无效ID {$invalid_id}，未找到 {$not_found}，错误 " . count($errors);
    $debug_log = implode("\n", $debug_lines);

    $msg = "分类导入完成！共处理 {$processed} 个分类";
    if ($updated > 0) {
        $msg .= "（{$updated} 个有更新）";
    }
    if ($no_changes > 0) {
        $msg .= "，{$no_changes} 个无变化";
    }
    if ($invalid_id > 0) {
        $msg .= "，{$invalid_id} 行 ID 无效";
    }
    if ($not_found > 0) {
        $msg .= "，{$not_found} 个 ID 未找到";
    }
    if (!empty($errors)) {
        $msg .= "。错误: " . implode('; ', array_slice($errors, 0, 3));
        if (count($errors) > 3) {
            $msg .= " 等";
        }
    }
    if ($slug_changed) {
        $msg .= "。已检测到 Slug 更新并刷新 permalink。";
    }

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

    // 扫描全部页面，避免遗漏字段
    $all_meta_keys = $wpdb->get_col("
        SELECT DISTINCT pm.meta_key
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'page'
        AND p.post_status IN ('publish', 'draft', 'private')
        AND pm.meta_key NOT LIKE '\_%'
        ORDER BY pm.meta_key
    ");

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

    $custom_fields = spe_merge_export_custom_fields(
        $all_meta_keys,
        $exclude_keys,
        spe_get_post_type_acf_field_names('page')
    );

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
    $attachment_field_map = array_fill_keys($attachment_fields, true);

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

        $custom_values = [];
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
            $custom_values[$field] = $value;
        }

        $segments = spe_build_custom_export_row_segments($custom_fields, $attachment_field_map, $custom_values);
        $row = array_merge($row, $segments['values'], $segments['urls']);
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

    // 扫描当前导出范围内全部文章，避免遗漏字段
    $all_meta_keys = $wpdb->get_col("
        SELECT DISTINCT pm.meta_key
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE {$where_clause}
        AND pm.meta_key NOT LIKE '\_%'
        ORDER BY pm.meta_key
    ");

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

    $custom_fields = spe_merge_export_custom_fields(
        $all_meta_keys,
        $exclude_keys,
        spe_get_post_type_acf_field_names('post')
    );

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
    $attachment_field_map = array_fill_keys($attachment_fields, true);

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

        $custom_values = [];
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
            $custom_values[$field] = $value;
        }

        $segments = spe_build_custom_export_row_segments($custom_fields, $attachment_field_map, $custom_values);
        $row = array_merge($row, $segments['values'], $segments['urls']);
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
    if (spe_is_zip_signature_file($file)) {
        return ['error' => true, 'message' => '导入失败：你上传的不是纯 CSV 文件（检测到 PK 文件头，通常是 ZIP/XLSX）。请直接上传 .csv 文本文件。'];
    }
    list($handle, $delimiter) = spe_open_csv_file($file);
    if (!$handle)
        return ['error' => true, 'message' => '无法读取文件'];

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        fclose($handle);
        return ['error' => true, 'message' => 'CSV 文件为空或格式错误'];
    }
    $header = spe_normalize_csv_header($header);

    $id_col = spe_find_header_col($header, ['ID']);
    $title_col = spe_find_header_col($header, ['标题', 'Title']);
    $slug_col = spe_find_header_col($header, ['Slug']);
    $excerpt_col = spe_find_header_col($header, ['摘要', 'Excerpt']);
    $content_col = spe_find_header_col($header, ['内容', 'Content']);
    $meta_title_col = spe_find_header_col($header, ['Meta Title']);
    $meta_desc_col = spe_find_header_col($header, ['Meta Description']);

    if ($id_col === false) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：缺少 ID 列（支持 ID，大小写不敏感）。'];
    }

    $custom_cols = [];
    foreach ($header as $idx => $col_name) {
        $col_lower = strtolower((string) $col_name);
        // 排除所有已知的标准列（中英文）
        if (!in_array($col_lower, ['id', '标题', 'title', 'slug', '摘要', 'excerpt', '内容', 'content', 'meta title', 'meta description'], true)) {
            if (spe_should_skip_helper_url_column($col_name, $header)) {
                continue;
            }
            $custom_cols[$idx] = $col_name;
        }
    }

    $updated = 0;
    $not_found = 0;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $page_id = ($id_col !== false && isset($row[$id_col])) ? intval(preg_replace('/[^0-9]/', '', (string) $row[$id_col])) : 0;
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
        if ($title_col !== false && isset($row[$title_col]) && $row[$title_col] !== '') {
            $update_data['post_title'] = $row[$title_col];
        }
        if ($slug_col !== false && isset($row[$slug_col]) && $row[$slug_col] !== '') {
            $update_data['post_name'] = sanitize_title($row[$slug_col]);
        }
        if ($excerpt_col !== false && isset($row[$excerpt_col]) && $row[$excerpt_col] !== '') {
            $update_data['post_excerpt'] = $row[$excerpt_col];
        }
        if ($content_col !== false && isset($row[$content_col]) && $row[$content_col] !== '') {
            $update_data['post_content'] = $row[$content_col];
        }

        if (!empty($update_data)) {
            $update_data['ID'] = $page_id;
            wp_update_post($update_data);
        }

        $meta_title_value = ($meta_title_col !== false && isset($row[$meta_title_col]) && $row[$meta_title_col] !== '')
            ? $row[$meta_title_col]
            : null;
        $meta_desc_value = ($meta_desc_col !== false && isset($row[$meta_desc_col]) && $row[$meta_desc_col] !== '')
            ? $row[$meta_desc_col]
            : null;
        if ($meta_title_value !== null || $meta_desc_value !== null) {
            spe_sync_post_seo_meta_by_active_provider($page_id, $meta_title_value, $meta_desc_value);
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
    if (spe_is_zip_signature_file($file)) {
        return ['error' => true, 'message' => '导入失败：你上传的不是纯 CSV 文件（检测到 PK 文件头，通常是 ZIP/XLSX）。请直接上传 .csv 文本文件。'];
    }
    list($handle, $delimiter) = spe_open_csv_file($file);
    if (!$handle)
        return ['error' => true, 'message' => '无法读取文件'];

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        fclose($handle);
        return ['error' => true, 'message' => 'CSV 文件为空或格式错误'];
    }
    $header = spe_normalize_csv_header($header);

    $id_col = spe_find_header_col($header, ['ID']);
    $title_col = spe_find_header_col($header, ['标题', 'Title']);
    $slug_col = spe_find_header_col($header, ['Slug']);
    $excerpt_col = spe_find_header_col($header, ['摘要', 'Excerpt']);
    $content_col = spe_find_header_col($header, ['内容', 'Content']);
    $meta_title_col = spe_find_header_col($header, ['Meta Title']);
    $meta_desc_col = spe_find_header_col($header, ['Meta Description']);

    if ($id_col === false) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：缺少 ID 列（支持 ID，大小写不敏感）。'];
    }

    $custom_cols = [];
    foreach ($header as $idx => $col_name) {
        $col_lower = strtolower((string) $col_name);
        // 排除所有已知的标准列（中英文）
        if (!in_array($col_lower, ['id', '标题', 'title', 'slug', '摘要', 'excerpt', '内容', 'content', 'meta title', 'meta description'], true)) {
            if (spe_should_skip_helper_url_column($col_name, $header)) {
                continue;
            }
            $custom_cols[$idx] = $col_name;
        }
    }

    $updated = 0;
    $not_found = 0;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $post_id = ($id_col !== false && isset($row[$id_col])) ? intval(preg_replace('/[^0-9]/', '', (string) $row[$id_col])) : 0;
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
        if ($title_col !== false && isset($row[$title_col]) && $row[$title_col] !== '') {
            $update_data['post_title'] = $row[$title_col];
        }
        if ($slug_col !== false && isset($row[$slug_col]) && $row[$slug_col] !== '') {
            $update_data['post_name'] = sanitize_title($row[$slug_col]);
        }
        if ($excerpt_col !== false && isset($row[$excerpt_col]) && $row[$excerpt_col] !== '') {
            $update_data['post_excerpt'] = $row[$excerpt_col];
        }
        if ($content_col !== false && isset($row[$content_col]) && $row[$content_col] !== '') {
            $update_data['post_content'] = $row[$content_col];
        }

        if (!empty($update_data)) {
            $update_data['ID'] = $post_id;
            wp_update_post($update_data);
        }

        $meta_title_value = ($meta_title_col !== false && isset($row[$meta_title_col]) && $row[$meta_title_col] !== '')
            ? $row[$meta_title_col]
            : null;
        $meta_desc_value = ($meta_desc_col !== false && isset($row[$meta_desc_col]) && $row[$meta_desc_col] !== '')
            ? $row[$meta_desc_col]
            : null;
        if ($meta_title_value !== null || $meta_desc_value !== null) {
            spe_sync_post_seo_meta_by_active_provider($post_id, $meta_title_value, $meta_desc_value);
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
