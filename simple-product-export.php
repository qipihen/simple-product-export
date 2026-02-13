<?php
/*
Plugin Name: 产品导入导出工具
Plugin URI: https://github.com/yourusername/simple-product-export
Description: 导出/导入 产品、页面、文章 和分类 CSV，包含所有自定义字段，支持筛选导出
Version: 4.7.8
Author: zhangkun
License: GPL v2 or later
*/

if (!defined('ABSPATH')) {
    exit;
}

$spe_include_files = [
    __DIR__ . '/includes/class-spe-entity-registry.php',
    __DIR__ . '/includes/class-spe-field-discovery.php',
    __DIR__ . '/includes/class-spe-mapping-engine.php',
    __DIR__ . '/includes/class-spe-match-engine.php',
];
foreach ($spe_include_files as $spe_file) {
    if (is_readable($spe_file)) {
        require_once $spe_file;
    }
}
unset($spe_file, $spe_include_files);

add_action('admin_menu', 'spe_add_admin_menu');
add_action('wp_ajax_spe_get_taxonomy_export_fields', 'spe_ajax_get_taxonomy_export_fields');
add_action('wp_ajax_spe_get_post_type_export_fields', 'spe_ajax_get_post_type_export_fields');

/**
 * 获取实体注册中心
 */
function spe_get_entity_registry()
{
    static $registry = null;
    if ($registry === null && class_exists('SPE_Entity_Registry')) {
        $registry = new SPE_Entity_Registry();
    }
    return $registry;
}

/**
 * 获取字段发现器
 */
function spe_get_field_discovery()
{
    static $discovery = null;
    if ($discovery === null && class_exists('SPE_Field_Discovery')) {
        $discovery = new SPE_Field_Discovery();
    }
    return $discovery;
}

/**
 * 获取映射引擎
 */
function spe_get_mapping_engine()
{
    static $engine = null;
    if ($engine === null && class_exists('SPE_Mapping_Engine')) {
        $engine = new SPE_Mapping_Engine();
    }
    return $engine;
}

/**
 * 获取匹配引擎
 */
function spe_get_match_engine()
{
    static $engine = null;
    if ($engine === null && class_exists('SPE_Match_Engine')) {
        $engine = new SPE_Match_Engine();
    }
    return $engine;
}

/**
 * 获取公开 taxonomy 对象
 */
function spe_get_public_taxonomy_objects()
{
    $registry = spe_get_entity_registry();
    if ($registry && method_exists($registry, 'get_taxonomies')) {
        $items = $registry->get_taxonomies();
        if (is_array($items)) {
            return $items;
        }
    }

    if (function_exists('get_taxonomies')) {
        $fallback = get_taxonomies(['public' => true], 'objects');
        return is_array($fallback) ? $fallback : [];
    }

    return [];
}

/**
 * 获取公开 post type 对象
 */
function spe_get_public_post_type_objects()
{
    $registry = spe_get_entity_registry();
    if ($registry && method_exists($registry, 'get_post_types')) {
        $items = $registry->get_post_types();
        if (is_array($items)) {
            return $items;
        }
    }

    if (function_exists('get_post_types')) {
        $fallback = get_post_types(['public' => true], 'objects');
        return is_array($fallback) ? $fallback : [];
    }

    return [];
}

/**
 * 解析 post type 导出自定义字段（meta + ACF）
 */
function spe_resolve_post_export_custom_fields($post_type, $meta_keys, $exclude_keys)
{
    $discovery = spe_get_field_discovery();
    if ($discovery && method_exists($discovery, 'resolve_post_custom_fields')) {
        return $discovery->resolve_post_custom_fields($post_type, $meta_keys, $exclude_keys);
    }

    return spe_merge_export_custom_fields(
        $meta_keys,
        $exclude_keys,
        spe_get_post_type_acf_field_names($post_type)
    );
}

/**
 * 读取映射后的列索引
 */
function spe_get_map_index($map, $field_key)
{
    if (!is_array($map) || !array_key_exists($field_key, $map)) {
        return false;
    }

    $idx = $map[$field_key];
    if ($idx === false || $idx === null || $idx === '') {
        return false;
    }

    return intval($idx);
}

/**
 * 根据列索引读取行值
 */
function spe_get_row_value_by_index($row, $idx)
{
    if (!is_array($row) || $idx === false || $idx === null) {
        return '';
    }
    return isset($row[$idx]) ? $row[$idx] : '';
}

/**
 * 获取 post 导入匹配配置
 */
function spe_get_post_import_match_profile($post_type)
{
    $profile = [
        'strategies' => ['id', 'slug', 'unique_meta'],
        'allow_insert' => false,
        'unique_meta_key' => '',
        'unique_meta_field' => '',
    ];

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('spe_post_import_match_profile', $profile, $post_type);
        if (is_array($filtered)) {
            $profile = array_merge($profile, $filtered);
        }
    }

    if (!isset($profile['strategies']) || !is_array($profile['strategies'])) {
        $profile['strategies'] = ['id', 'slug', 'unique_meta'];
    }

    $profile['strategies'] = array_values(array_unique(array_filter(array_map(function ($item) {
        return trim((string) $item);
    }, $profile['strategies']))));

    $profile['allow_insert'] = !empty($profile['allow_insert']);
    $profile['unique_meta_key'] = trim((string) ($profile['unique_meta_key'] ?? ''));
    $profile['unique_meta_field'] = trim((string) ($profile['unique_meta_field'] ?? ''));

    if ($profile['unique_meta_field'] !== '' && $profile['unique_meta_key'] === '') {
        $profile['unique_meta_key'] = $profile['unique_meta_field'];
    }

    return $profile;
}

/**
 * 构建 post 导入上下文（列映射 + 自定义字段）
 */
function spe_build_post_import_context($header, $post_type)
{
    $header = spe_normalize_csv_header($header);

    $standard_cols = [
        'id',
        '标题',
        'title',
        'name',
        '名称',
        'slug',
        '摘要',
        'excerpt',
        '短描述',
        'short description',
        'short_description',
        '内容',
        'content',
        '长描述',
        'long description',
        'long_description',
        'meta title',
        'meta description',
    ];

    $custom_fields = [];
    $custom_cols_fallback = [];
    foreach ($header as $idx => $col_name) {
        $col_name = (string) $col_name;
        $col_lower = strtolower($col_name);
        if (in_array($col_lower, $standard_cols, true)) {
            continue;
        }
        if (spe_should_skip_helper_url_column($col_name, $header)) {
            continue;
        }
        $custom_fields[] = $col_name;
        $custom_cols_fallback[intval($idx)] = $col_name;
    }

    $map = [];
    $discovery = spe_get_field_discovery();
    $mapping_engine = spe_get_mapping_engine();
    if ($discovery && $mapping_engine && method_exists($discovery, 'build_post_candidate_fields') && method_exists($mapping_engine, 'auto_map_headers')) {
        $candidates = $discovery->build_post_candidate_fields($post_type, array_values(array_unique($custom_fields)), []);
        $mapping_result = $mapping_engine->auto_map_headers($header, $candidates);
        if (is_array($mapping_result) && isset($mapping_result['map']) && is_array($mapping_result['map'])) {
            $map = $mapping_result['map'];
        }
    }

    $fallback_aliases = [
        'id' => ['ID', 'id'],
        'title' => ['标题', 'Title', 'title', '名称', 'name'],
        'slug' => ['Slug', 'slug'],
        'excerpt' => ['短描述', 'Short Description', 'short description', 'short_description', '摘要', 'Excerpt', 'excerpt'],
        'content' => ['长描述', 'Long Description', 'long description', 'long_description', '内容', 'Content', 'content'],
        'meta_title' => ['Meta Title', 'meta title'],
        'meta_description' => ['Meta Description', 'meta description'],
    ];

    $used_indexes = [];
    foreach ($map as $mapped_idx) {
        $used_indexes[intval($mapped_idx)] = true;
    }

    foreach ($fallback_aliases as $field_key => $aliases) {
        if (!array_key_exists($field_key, $map)) {
            $idx = spe_find_header_col($header, $aliases);
            if ($idx !== false) {
                $idx = intval($idx);
                if (!isset($used_indexes[$idx])) {
                    $map[$field_key] = $idx;
                    $used_indexes[$idx] = true;
                }
            }
        }
    }

    $custom_cols = [];
    foreach ($map as $field_key => $idx) {
        $field_key = (string) $field_key;
        if (strpos($field_key, 'field:') !== 0) {
            continue;
        }
        $field_name = substr($field_key, 6);
        if ($field_name === '') {
            continue;
        }
        $idx = intval($idx);
        if (!isset($custom_cols[$idx])) {
            $custom_cols[$idx] = $field_name;
        }
    }

    if (empty($custom_cols)) {
        $custom_cols = $custom_cols_fallback;
    }
    ksort($custom_cols);

    return [
        'header' => $header,
        'map' => $map,
        'indexes' => [
            'id' => spe_get_map_index($map, 'id'),
            'title' => spe_get_map_index($map, 'title'),
            'slug' => spe_get_map_index($map, 'slug'),
            'excerpt' => spe_get_map_index($map, 'excerpt'),
            'content' => spe_get_map_index($map, 'content'),
            'meta_title' => spe_get_map_index($map, 'meta_title'),
            'meta_description' => spe_get_map_index($map, 'meta_description'),
        ],
        'custom_cols' => $custom_cols,
    ];
}

/**
 * 按 post_type + slug 匹配 post ID
 */
function spe_find_post_id_by_slug_for_post_type($post_type, $slug)
{
    $slug = trim((string) $slug);
    if ($slug === '') {
        return null;
    }

    if (function_exists('sanitize_title')) {
        $slug = sanitize_title($slug);
    }

    if (!function_exists('get_posts')) {
        return null;
    }

    $ids = get_posts([
        'post_type' => $post_type,
        'name' => $slug,
        'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => false,
    ]);

    if (is_array($ids) && !empty($ids)) {
        $id = intval($ids[0]);
        return $id > 0 ? $id : null;
    }

    return null;
}

/**
 * 按 post_type + meta 唯一键匹配 post ID 列表
 *
 * @return int[]
 */
function spe_find_post_ids_by_meta_for_post_type($post_type, $meta_key, $meta_value, $limit = 2)
{
    $meta_key = trim((string) $meta_key);
    $meta_value = (string) $meta_value;
    $limit = max(1, intval($limit));

    if ($meta_key === '' || $meta_value === '' || !function_exists('get_posts')) {
        return [];
    }

    $ids = get_posts([
        'post_type' => $post_type,
        'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
        'posts_per_page' => $limit,
        'fields' => 'ids',
        'no_found_rows' => true,
        'suppress_filters' => false,
        'meta_key' => $meta_key,
        'meta_value' => $meta_value,
    ]);

    if (!is_array($ids)) {
        return [];
    }

    $normalized = array_values(array_unique(array_filter(array_map(function ($item) {
        $id = intval($item);
        return $id > 0 ? $id : 0;
    }, $ids))));

    return $normalized;
}

/**
 * 对单行 post 导入数据执行匹配（ID > slug > unique_meta）
 */
function spe_resolve_post_import_match($row, $map, $post_type, $match_profile = [], $callbacks_override = [])
{
    $row = is_array($row) ? $row : [];
    $map = is_array($map) ? $map : [];
    $callbacks_override = is_array($callbacks_override) ? $callbacks_override : [];

    $profile = array_merge(spe_get_post_import_match_profile($post_type), is_array($match_profile) ? $match_profile : []);
    $profile['strategies'] = isset($profile['strategies']) && is_array($profile['strategies'])
        ? $profile['strategies']
        : ['id', 'slug', 'unique_meta'];

    $id_idx = spe_get_map_index($map, 'id');
    $slug_idx = spe_get_map_index($map, 'slug');
    $row_data = [];
    if ($id_idx !== false) {
        $row_data['id'] = spe_get_row_value_by_index($row, $id_idx);
    }
    if ($slug_idx !== false) {
        $row_data['slug'] = spe_get_row_value_by_index($row, $slug_idx);
    }

    $unique_meta_field = trim((string) ($profile['unique_meta_field'] ?? ''));
    $unique_meta_key = trim((string) ($profile['unique_meta_key'] ?? ''));
    $unique_map_key = '';
    if ($unique_meta_field !== '') {
        if (array_key_exists($unique_meta_field, $map)) {
            $unique_map_key = $unique_meta_field;
        } elseif (array_key_exists('field:' . $unique_meta_field, $map)) {
            $unique_map_key = 'field:' . $unique_meta_field;
        }
    }
    if ($unique_meta_field !== '' && $unique_map_key !== '') {
        $row_data[$unique_meta_field] = spe_get_row_value_by_index($row, intval($map[$unique_map_key]));
    } else {
        $unique_meta_field = '';
        $unique_meta_key = '';
    }

    $callbacks = [
        'find_by_id' => function ($id) use ($post_type) {
            $id = intval($id);
            if ($id <= 0 || !function_exists('get_post')) {
                return null;
            }
            $post = get_post($id);
            if (!$post || is_wp_error($post) || (string) $post->post_type !== (string) $post_type) {
                return null;
            }
            return $id;
        },
        'find_by_slug' => function ($slug) use ($post_type) {
            return spe_find_post_id_by_slug_for_post_type($post_type, $slug);
        },
        'find_by_meta' => function ($meta_key, $meta_value) use ($post_type) {
            $ids = spe_find_post_ids_by_meta_for_post_type($post_type, $meta_key, $meta_value, 2);
            if (count($ids) === 1) {
                return $ids[0];
            }
            if (count($ids) > 1) {
                return $ids;
            }
            return null;
        },
    ];
    $callbacks = array_merge($callbacks, $callbacks_override);

    $engine = spe_get_match_engine();
    if (!$engine || !method_exists($engine, 'resolve')) {
        $fallback_id = isset($row_data['id']) ? intval(preg_replace('/[^0-9]/', '', (string) $row_data['id'])) : 0;
        if ($fallback_id > 0 && isset($callbacks['find_by_id']) && is_callable($callbacks['find_by_id'])) {
            $matched = call_user_func($callbacks['find_by_id'], $fallback_id);
            $matched_id = intval($matched);
            if ($matched_id > 0) {
                return ['matched_id' => $matched_id, 'action' => 'update', 'error' => '', 'strategy' => 'id'];
            }
        }
        return ['matched_id' => null, 'action' => 'skip', 'error' => 'record not found by configured strategies', 'strategy' => 'none'];
    }

    $result = $engine->resolve($row_data, $callbacks, [
        'id_field' => 'id',
        'slug_field' => 'slug',
        'unique_meta_field' => $unique_meta_field,
        'unique_meta_key' => $unique_meta_key,
        'allow_insert' => !empty($profile['allow_insert']),
        'strategies' => $profile['strategies'],
    ]);

    if (!is_array($result)) {
        return ['matched_id' => null, 'action' => 'skip', 'error' => 'invalid match result', 'strategy' => 'none'];
    }

    return [
        'matched_id' => isset($result['matched_id']) && $result['matched_id'] !== null ? intval($result['matched_id']) : null,
        'action' => (string) ($result['action'] ?? 'skip'),
        'error' => (string) ($result['error'] ?? ''),
        'strategy' => (string) ($result['strategy'] ?? 'none'),
    ];
}

/**
 * 获取 taxonomy 导入匹配配置
 */
function spe_get_taxonomy_import_match_profile($taxonomy)
{
    $profile = [
        'strategies' => ['id', 'slug', 'unique_meta'],
        'allow_insert' => false,
        'unique_meta_key' => '',
        'unique_meta_field' => '',
    ];

    if (function_exists('apply_filters')) {
        $filtered = apply_filters('spe_taxonomy_import_match_profile', $profile, $taxonomy);
        if (is_array($filtered)) {
            $profile = array_merge($profile, $filtered);
        }
    }

    if (!isset($profile['strategies']) || !is_array($profile['strategies'])) {
        $profile['strategies'] = ['id', 'slug', 'unique_meta'];
    }

    $profile['strategies'] = array_values(array_unique(array_filter(array_map(function ($item) {
        return trim((string) $item);
    }, $profile['strategies']))));

    $profile['allow_insert'] = !empty($profile['allow_insert']);
    $profile['unique_meta_key'] = trim((string) ($profile['unique_meta_key'] ?? ''));
    $profile['unique_meta_field'] = trim((string) ($profile['unique_meta_field'] ?? ''));

    if ($profile['unique_meta_field'] !== '' && $profile['unique_meta_key'] === '') {
        $profile['unique_meta_key'] = $profile['unique_meta_field'];
    }

    return $profile;
}

/**
 * 构建 taxonomy 导入上下文（列映射 + 自定义字段）
 */
function spe_build_taxonomy_import_context($header, $taxonomy)
{
    $header = spe_normalize_csv_header($header);

    $standard_cols = [
        'id',
        'term_id',
        '标题',
        '名称',
        'name',
        'title',
        'slug',
        '描述',
        'description',
        'parent',
        '父分类 id',
        'meta title',
        'meta description',
    ];

    $custom_fields = [];
    $custom_cols_fallback = [];
    foreach ($header as $idx => $col_name) {
        $col_name = (string) $col_name;
        $col_lower = strtolower($col_name);
        if (in_array($col_lower, $standard_cols, true)) {
            continue;
        }
        if (spe_should_skip_helper_url_column($col_name, $header)) {
            continue;
        }
        $custom_fields[] = $col_name;
        $custom_cols_fallback[intval($idx)] = $col_name;
    }

    $map = [];
    $discovery = spe_get_field_discovery();
    $mapping_engine = spe_get_mapping_engine();
    if ($discovery && $mapping_engine && method_exists($discovery, 'build_taxonomy_candidate_fields') && method_exists($mapping_engine, 'auto_map_headers')) {
        $candidates = $discovery->build_taxonomy_candidate_fields($taxonomy, array_values(array_unique($custom_fields)));
        $mapping_result = $mapping_engine->auto_map_headers($header, $candidates);
        if (is_array($mapping_result) && isset($mapping_result['map']) && is_array($mapping_result['map'])) {
            $map = $mapping_result['map'];
        }
    }

    $fallback_aliases = [
        'id' => ['ID', 'id', 'term_id'],
        'name' => ['标题', '名称', 'name', 'Name', 'Title', 'title'],
        'slug' => ['Slug', 'slug'],
        'description' => ['描述', 'Description', 'description'],
        'parent' => ['父分类 ID', 'parent', 'Parent'],
        'meta_title' => ['Meta Title', 'meta title', 'title'],
        'meta_description' => ['Meta Description', 'meta description', 'description'],
    ];

    $used_indexes = [];
    foreach ($map as $mapped_idx) {
        $used_indexes[intval($mapped_idx)] = true;
    }

    foreach ($fallback_aliases as $field_key => $aliases) {
        if (!array_key_exists($field_key, $map)) {
            $idx = spe_find_header_col($header, $aliases);
            if ($idx !== false) {
                $idx = intval($idx);
                if (!isset($used_indexes[$idx])) {
                    $map[$field_key] = $idx;
                    $used_indexes[$idx] = true;
                }
            }
        }
    }

    $custom_cols = [];
    foreach ($map as $field_key => $idx) {
        $field_key = (string) $field_key;
        if (strpos($field_key, 'field:') !== 0) {
            continue;
        }
        $field_name = substr($field_key, 6);
        if ($field_name === '') {
            continue;
        }
        $idx = intval($idx);
        if (!isset($custom_cols[$idx])) {
            $custom_cols[$idx] = $field_name;
        }
    }

    if (empty($custom_cols)) {
        $custom_cols = $custom_cols_fallback;
    }
    ksort($custom_cols);

    return [
        'header' => $header,
        'map' => $map,
        'indexes' => [
            'id' => spe_get_map_index($map, 'id'),
            'name' => spe_get_map_index($map, 'name'),
            'slug' => spe_get_map_index($map, 'slug'),
            'description' => spe_get_map_index($map, 'description'),
            'parent' => spe_get_map_index($map, 'parent'),
            'meta_title' => spe_get_map_index($map, 'meta_title'),
            'meta_description' => spe_get_map_index($map, 'meta_description'),
        ],
        'custom_cols' => $custom_cols,
    ];
}

/**
 * 按 taxonomy + slug 匹配 term ID
 */
function spe_find_taxonomy_term_id_by_slug($taxonomy, $slug)
{
    $slug = trim((string) $slug);
    if ($slug === '') {
        return null;
    }

    if (strpos($slug, '/') !== false) {
        $parts = explode('/', $slug);
        $slug = (string) end($parts);
    }

    if (function_exists('sanitize_title')) {
        $slug = sanitize_title($slug);
    }

    if (!function_exists('get_term_by')) {
        return null;
    }

    $term = get_term_by('slug', $slug, $taxonomy);
    if ($term && !is_wp_error($term)) {
        $term_id = intval($term->term_id ?? 0);
        return $term_id > 0 ? $term_id : null;
    }

    return null;
}

/**
 * 按 taxonomy + meta 唯一键匹配 term ID 列表
 *
 * @return int[]
 */
function spe_find_taxonomy_term_ids_by_meta($taxonomy, $meta_key, $meta_value, $limit = 2)
{
    $meta_key = trim((string) $meta_key);
    $meta_value = (string) $meta_value;
    $limit = max(1, intval($limit));

    if ($meta_key === '' || $meta_value === '' || !function_exists('get_terms')) {
        return [];
    }

    $ids = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
        'fields' => 'ids',
        'number' => $limit,
        'meta_key' => $meta_key,
        'meta_value' => $meta_value,
    ]);

    if (!is_array($ids)) {
        return [];
    }

    $normalized = array_values(array_unique(array_filter(array_map(function ($item) {
        $id = intval($item);
        return $id > 0 ? $id : 0;
    }, $ids))));

    return $normalized;
}

/**
 * 对单行 taxonomy 导入数据执行匹配（ID > slug > unique_meta）
 */
function spe_resolve_taxonomy_import_match($row, $map, $taxonomy, $match_profile = [], $callbacks_override = [])
{
    $row = is_array($row) ? $row : [];
    $map = is_array($map) ? $map : [];
    $callbacks_override = is_array($callbacks_override) ? $callbacks_override : [];

    $profile = array_merge(spe_get_taxonomy_import_match_profile($taxonomy), is_array($match_profile) ? $match_profile : []);
    $profile['strategies'] = isset($profile['strategies']) && is_array($profile['strategies'])
        ? $profile['strategies']
        : ['id', 'slug', 'unique_meta'];

    $id_idx = spe_get_map_index($map, 'id');
    $slug_idx = spe_get_map_index($map, 'slug');
    $row_data = [];
    if ($id_idx !== false) {
        $row_data['id'] = spe_get_row_value_by_index($row, $id_idx);
    }
    if ($slug_idx !== false) {
        $row_data['slug'] = spe_get_row_value_by_index($row, $slug_idx);
    }

    $unique_meta_field = trim((string) ($profile['unique_meta_field'] ?? ''));
    $unique_meta_key = trim((string) ($profile['unique_meta_key'] ?? ''));
    $unique_map_key = '';
    if ($unique_meta_field !== '') {
        if (array_key_exists($unique_meta_field, $map)) {
            $unique_map_key = $unique_meta_field;
        } elseif (array_key_exists('field:' . $unique_meta_field, $map)) {
            $unique_map_key = 'field:' . $unique_meta_field;
        }
    }
    if ($unique_meta_field !== '' && $unique_map_key !== '') {
        $row_data[$unique_meta_field] = spe_get_row_value_by_index($row, intval($map[$unique_map_key]));
    } else {
        $unique_meta_field = '';
        $unique_meta_key = '';
    }

    $callbacks = [
        'find_by_id' => function ($id) use ($taxonomy) {
            $id = intval($id);
            if ($id <= 0 || !function_exists('get_term')) {
                return null;
            }
            $term = get_term($id, $taxonomy);
            if (!$term || is_wp_error($term)) {
                return null;
            }
            return $id;
        },
        'find_by_slug' => function ($slug) use ($taxonomy) {
            return spe_find_taxonomy_term_id_by_slug($taxonomy, $slug);
        },
        'find_by_meta' => function ($meta_key, $meta_value) use ($taxonomy) {
            $ids = spe_find_taxonomy_term_ids_by_meta($taxonomy, $meta_key, $meta_value, 2);
            if (count($ids) === 1) {
                return $ids[0];
            }
            if (count($ids) > 1) {
                return $ids;
            }
            return null;
        },
    ];
    $callbacks = array_merge($callbacks, $callbacks_override);

    $engine = spe_get_match_engine();
    if (!$engine || !method_exists($engine, 'resolve')) {
        $fallback_id = isset($row_data['id']) ? intval(preg_replace('/[^0-9]/', '', (string) $row_data['id'])) : 0;
        if ($fallback_id > 0 && isset($callbacks['find_by_id']) && is_callable($callbacks['find_by_id'])) {
            $matched = call_user_func($callbacks['find_by_id'], $fallback_id);
            $matched_id = intval($matched);
            if ($matched_id > 0) {
                return ['matched_id' => $matched_id, 'action' => 'update', 'error' => '', 'strategy' => 'id'];
            }
        }
        return ['matched_id' => null, 'action' => 'skip', 'error' => 'record not found by configured strategies', 'strategy' => 'none'];
    }

    $result = $engine->resolve($row_data, $callbacks, [
        'id_field' => 'id',
        'slug_field' => 'slug',
        'unique_meta_field' => $unique_meta_field,
        'unique_meta_key' => $unique_meta_key,
        'allow_insert' => !empty($profile['allow_insert']),
        'strategies' => $profile['strategies'],
    ]);

    if (!is_array($result)) {
        return ['matched_id' => null, 'action' => 'skip', 'error' => 'invalid match result', 'strategy' => 'none'];
    }

    return [
        'matched_id' => isset($result['matched_id']) && $result['matched_id'] !== null ? intval($result['matched_id']) : null,
        'action' => (string) ($result['action'] ?? 'skip'),
        'error' => (string) ($result['error'] ?? ''),
        'strategy' => (string) ($result['strategy'] ?? 'none'),
    ];
}

/**
 * 根据请求参数覆盖 allow_insert
 */
function spe_resolve_allow_insert_override($profile, $request_keys = [])
{
    $profile = is_array($profile) ? $profile : [];
    $request_keys = is_array($request_keys) ? $request_keys : [];
    array_unshift($request_keys, 'spe_allow_insert');

    foreach ($request_keys as $key) {
        $key = trim((string) $key);
        if ($key === '' || !isset($_POST[$key])) {
            continue;
        }

        $raw = strtolower(trim((string) $_POST[$key]));
        if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
            $profile['allow_insert'] = true;
            return $profile;
        }
        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
            $profile['allow_insert'] = false;
            return $profile;
        }
    }

    return $profile;
}

/**
 * 根据请求参数覆盖 unique meta 匹配配置
 */
function spe_resolve_unique_meta_override($profile, $field_keys = [], $key_keys = [])
{
    $profile = is_array($profile) ? $profile : [];
    $field_keys = is_array($field_keys) ? $field_keys : [];
    $key_keys = is_array($key_keys) ? $key_keys : [];

    array_unshift($field_keys, 'spe_unique_meta_field');
    array_unshift($key_keys, 'spe_unique_meta_key');

    $unique_field = null;
    foreach ($field_keys as $key) {
        $key = trim((string) $key);
        if ($key === '' || !isset($_POST[$key])) {
            continue;
        }
        $value = trim((string) $_POST[$key]);
        if ($value !== '') {
            $unique_field = $value;
        } else {
            $unique_field = '';
        }
        break;
    }

    $unique_key = null;
    foreach ($key_keys as $key) {
        $key = trim((string) $key);
        if ($key === '' || !isset($_POST[$key])) {
            continue;
        }
        $value = trim((string) $_POST[$key]);
        if ($value !== '') {
            $unique_key = $value;
        } else {
            $unique_key = '';
        }
        break;
    }

    if ($unique_field !== null) {
        $profile['unique_meta_field'] = $unique_field;
    }
    if ($unique_key !== null) {
        $profile['unique_meta_key'] = $unique_key;
    }

    if (!empty($profile['unique_meta_field']) && empty($profile['unique_meta_key'])) {
        $profile['unique_meta_key'] = (string) $profile['unique_meta_field'];
    }

    return $profile;
}

/**
 * 获取导入 UI 默认配置（记忆上次输入）
 */
function spe_get_import_ui_defaults()
{
    $defaults = [
        'product' => ['allow_insert' => false, 'unique_meta_field' => ''],
        'page' => ['allow_insert' => false, 'unique_meta_field' => ''],
        'post' => ['allow_insert' => false, 'unique_meta_field' => ''],
        'taxonomy' => ['allow_insert' => false, 'unique_meta_field' => ''],
    ];

    if (function_exists('get_option')) {
        $stored = get_option('spe_import_ui_defaults', []);
        if (is_array($stored)) {
            foreach ($defaults as $type => $meta) {
                if (!isset($stored[$type]) || !is_array($stored[$type])) {
                    continue;
                }
                $defaults[$type]['allow_insert'] = !empty($stored[$type]['allow_insert']);
                $defaults[$type]['unique_meta_field'] = trim((string) ($stored[$type]['unique_meta_field'] ?? ''));
            }
        }
    }

    return $defaults;
}

/**
 * 更新导入 UI 默认配置（记忆上次输入）
 */
function spe_update_import_ui_defaults($type, $allow_insert, $unique_meta_field)
{
    $type = trim((string) $type);
    if (!in_array($type, ['product', 'page', 'post', 'taxonomy'], true)) {
        return;
    }

    $defaults = spe_get_import_ui_defaults();
    $defaults[$type] = [
        'allow_insert' => !empty($allow_insert),
        'unique_meta_field' => trim((string) $unique_meta_field),
    ];

    if (function_exists('update_option')) {
        update_option('spe_import_ui_defaults', $defaults, false);
    }
}

/**
 * 将请求值转换为布尔
 */
function spe_request_to_bool($value)
{
    $raw = strtolower(trim((string) $value));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

/**
 * 解析“仅导入指定列名”配置
 *
 * @return array{enabled:bool,indexes:int[],missing:string[]}
 */
function spe_parse_import_column_filter($header, $raw_value)
{
    $header = is_array($header) ? array_values($header) : [];
    $raw_value = trim((string) $raw_value);
    if ($raw_value === '') {
        return ['enabled' => false, 'indexes' => [], 'missing' => []];
    }

    $tokens = preg_split('/[\r\n,，;；]+/u', $raw_value);
    if (!is_array($tokens)) {
        $tokens = [];
    }
    $tokens = array_values(array_unique(array_filter(array_map(function ($item) {
        return trim((string) $item);
    }, $tokens))));
    if (empty($tokens)) {
        return ['enabled' => false, 'indexes' => [], 'missing' => []];
    }

    $header_index = [];
    foreach ($header as $idx => $name) {
        $normalized = strtolower(trim((string) $name));
        if ($normalized === '') {
            continue;
        }
        if (!array_key_exists($normalized, $header_index)) {
            $header_index[$normalized] = intval($idx);
        }
    }

    $indexes = [];
    $missing = [];
    foreach ($tokens as $token) {
        $normalized = strtolower(trim((string) $token));
        if ($normalized === '') {
            continue;
        }
        if (!array_key_exists($normalized, $header_index)) {
            $missing[] = $token;
            continue;
        }
        $indexes[] = intval($header_index[$normalized]);
    }

    return [
        'enabled' => true,
        'indexes' => array_values(array_unique($indexes)),
        'missing' => array_values(array_unique($missing)),
    ];
}

/**
 * 从请求参数中读取导入列白名单
 *
 * @return array{enabled:bool,indexes:int[],missing:string[]}
 */
function spe_resolve_import_column_filter_from_request($header, $request_keys = [])
{
    $request_keys = is_array($request_keys) ? $request_keys : [];
    array_unshift($request_keys, 'spe_import_columns');

    $raw_value = '';
    foreach ($request_keys as $key) {
        $key = trim((string) $key);
        if ($key === '' || !isset($_POST[$key])) {
            continue;
        }
        $raw_value = (string) $_POST[$key];
        break;
    }

    return spe_parse_import_column_filter($header, $raw_value);
}

/**
 * 判断列索引是否允许导入
 */
function spe_import_column_allowed($column_index, $column_filter)
{
    if ($column_index === false || $column_index === null) {
        return false;
    }
    if (!is_array($column_filter) || empty($column_filter['enabled'])) {
        return true;
    }

    $indexes = isset($column_filter['indexes']) && is_array($column_filter['indexes']) ? $column_filter['indexes'] : [];
    return in_array(intval($column_index), array_map('intval', $indexes), true);
}

/**
 * 获取导入匹配配置模板
 */
function spe_get_import_match_profiles()
{
    $types = ['product', 'page', 'post', 'taxonomy'];
    $profiles = [];
    foreach ($types as $type) {
        $profiles[$type] = [];
    }

    if (!function_exists('get_option')) {
        return $profiles;
    }

    $stored = get_option('spe_import_match_profiles', []);
    if (!is_array($stored)) {
        return $profiles;
    }

    foreach ($types as $type) {
        if (!isset($stored[$type]) || !is_array($stored[$type])) {
            continue;
        }

        $normalized = [];
        foreach ($stored[$type] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }

            $unique_meta_field = trim((string) ($item['unique_meta_field'] ?? ''));
            $unique_meta_key = trim((string) ($item['unique_meta_key'] ?? ''));
            if ($unique_meta_field !== '' && $unique_meta_key === '') {
                $unique_meta_key = $unique_meta_field;
            }

            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'allow_insert' => !empty($item['allow_insert']),
                'unique_meta_field' => $unique_meta_field,
                'unique_meta_key' => $unique_meta_key,
                'updated_at' => trim((string) ($item['updated_at'] ?? '')),
            ];
        }

        $profiles[$type] = $normalized;
    }

    return $profiles;
}

/**
 * 保存导入匹配配置模板
 *
 * @return array{ok:bool,message:string,id:string}
 */
function spe_save_import_match_profile($type, $name, $config = [])
{
    $type = trim((string) $type);
    if (!in_array($type, ['product', 'page', 'post', 'taxonomy'], true)) {
        return ['ok' => false, 'message' => '无效的模板类型', 'id' => ''];
    }

    $name = trim((string) $name);
    if ($name === '') {
        return ['ok' => false, 'message' => '请输入模板名称', 'id' => ''];
    }

    $config = is_array($config) ? $config : [];
    $allow_insert = !empty($config['allow_insert']);
    $unique_meta_field = trim((string) ($config['unique_meta_field'] ?? ''));
    $unique_meta_key = trim((string) ($config['unique_meta_key'] ?? ''));
    if ($unique_meta_field !== '' && $unique_meta_key === '') {
        $unique_meta_key = $unique_meta_field;
    }

    $slug_source = function_exists('sanitize_title') ? sanitize_title($name) : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
    $id = trim((string) $slug_source);
    if ($id === '') {
        $id = 'profile-' . substr(md5($name), 0, 8);
    }

    $all_profiles = spe_get_import_match_profiles();
    $target = isset($all_profiles[$type]) && is_array($all_profiles[$type]) ? $all_profiles[$type] : [];

    $found = false;
    foreach ($target as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string) ($item['id'] ?? '') !== $id) {
            continue;
        }
        $target[$idx] = [
            'id' => $id,
            'name' => $name,
            'allow_insert' => $allow_insert,
            'unique_meta_field' => $unique_meta_field,
            'unique_meta_key' => $unique_meta_key,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $found = true;
        break;
    }

    if (!$found) {
        $target[] = [
            'id' => $id,
            'name' => $name,
            'allow_insert' => $allow_insert,
            'unique_meta_field' => $unique_meta_field,
            'unique_meta_key' => $unique_meta_key,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    if (count($target) > 30) {
        $target = array_slice($target, -30);
    }

    $all_profiles[$type] = array_values($target);
    if (function_exists('update_option')) {
        update_option('spe_import_match_profiles', $all_profiles, false);
    }

    return ['ok' => true, 'message' => '模板已保存', 'id' => $id];
}

/**
 * 读取指定导入匹配模板
 *
 * @return array<string,mixed>|null
 */
function spe_get_import_match_profile_by_id($type, $id)
{
    $type = trim((string) $type);
    $id = trim((string) $id);
    if ($id === '') {
        return null;
    }

    $all_profiles = spe_get_import_match_profiles();
    if (!isset($all_profiles[$type]) || !is_array($all_profiles[$type])) {
        return null;
    }

    foreach ($all_profiles[$type] as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ((string) ($item['id'] ?? '') === $id) {
            return $item;
        }
    }

    return null;
}

/**
 * 从请求中读取已选导入模板并返回配置
 *
 * @return array<string,mixed>
 */
function spe_resolve_import_match_profile_selection($type, $request_keys = [])
{
    $type = trim((string) $type);
    if (!in_array($type, ['product', 'page', 'post', 'taxonomy'], true)) {
        return [];
    }

    $request_keys = is_array($request_keys) ? $request_keys : [];
    array_unshift($request_keys, 'spe_import_profile_id');

    $profile_id = '';
    foreach ($request_keys as $key) {
        $key = trim((string) $key);
        if ($key === '' || !isset($_POST[$key])) {
            continue;
        }
        $profile_id = trim((string) $_POST[$key]);
        if ($profile_id !== '') {
            break;
        }
    }

    if ($profile_id === '') {
        return [];
    }

    $profile = spe_get_import_match_profile_by_id($type, $profile_id);
    if (!is_array($profile)) {
        return [];
    }

    return [
        'allow_insert' => !empty($profile['allow_insert']),
        'unique_meta_field' => trim((string) ($profile['unique_meta_field'] ?? '')),
        'unique_meta_key' => trim((string) ($profile['unique_meta_key'] ?? '')),
    ];
}

/**
 * 根据导入行创建 post
 */
function spe_create_post_from_import_row($post_type, $row, $context)
{
    if (!function_exists('wp_insert_post')) {
        return ['id' => 0, 'error' => 'wp_insert_post unavailable'];
    }

    $indexes = [];
    if (is_array($context) && isset($context['indexes']) && is_array($context['indexes'])) {
        $indexes = $context['indexes'];
    }

    $title = trim((string) spe_get_row_value_by_index($row, $indexes['title'] ?? false));
    $slug_raw = trim((string) spe_get_row_value_by_index($row, $indexes['slug'] ?? false));
    $excerpt = (string) spe_get_row_value_by_index($row, $indexes['excerpt'] ?? false);
    $content = (string) spe_get_row_value_by_index($row, $indexes['content'] ?? false);

    if (strpos($slug_raw, '/') !== false) {
        $slug_parts = explode('/', $slug_raw);
        $slug_raw = (string) end($slug_parts);
    }

    $insert_data = [
        'post_type' => (string) $post_type,
        'post_status' => 'draft',
        'post_title' => $title !== '' ? $title : ('Imported ' . (string) $post_type . ' ' . date('Y-m-d H:i:s')),
    ];

    if ($slug_raw !== '') {
        $insert_data['post_name'] = function_exists('sanitize_title') ? sanitize_title($slug_raw) : $slug_raw;
    }
    if ($excerpt !== '') {
        $insert_data['post_excerpt'] = $excerpt;
    }
    if ($content !== '') {
        $insert_data['post_content'] = $content;
    }

    $inserted = wp_insert_post($insert_data, true);
    if (function_exists('is_wp_error') && is_wp_error($inserted)) {
        return ['id' => 0, 'error' => (string) $inserted->get_error_message()];
    }

    $id = intval($inserted);
    if ($id <= 0) {
        return ['id' => 0, 'error' => 'wp_insert_post did not return valid ID'];
    }

    return ['id' => $id, 'error' => ''];
}

/**
 * 根据导入行创建 taxonomy term
 */
function spe_create_taxonomy_term_from_import_row($taxonomy, $row, $context)
{
    if (!function_exists('wp_insert_term')) {
        return ['id' => 0, 'error' => 'wp_insert_term unavailable'];
    }

    $indexes = [];
    if (is_array($context) && isset($context['indexes']) && is_array($context['indexes'])) {
        $indexes = $context['indexes'];
    }

    $name = trim((string) spe_get_row_value_by_index($row, $indexes['name'] ?? false));
    $slug_raw = trim((string) spe_get_row_value_by_index($row, $indexes['slug'] ?? false));
    $description = (string) spe_get_row_value_by_index($row, $indexes['description'] ?? false);
    $parent_raw = trim((string) spe_get_row_value_by_index($row, $indexes['parent'] ?? false));

    if (strpos($slug_raw, '/') !== false) {
        $slug_parts = explode('/', $slug_raw);
        $slug_raw = (string) end($slug_parts);
    }

    if ($name === '') {
        $name = $slug_raw !== '' ? $slug_raw : ('Imported term ' . date('Y-m-d H:i:s'));
    }

    $args = [];
    if ($slug_raw !== '') {
        $args['slug'] = function_exists('sanitize_title') ? sanitize_title($slug_raw) : $slug_raw;
    }
    if ($description !== '') {
        $args['description'] = $description;
    }
    if ($parent_raw !== '') {
        $parent_id = intval(preg_replace('/[^0-9]/', '', $parent_raw));
        if ($parent_id > 0) {
            $args['parent'] = $parent_id;
        }
    }

    $result = wp_insert_term($name, $taxonomy, $args);
    if (function_exists('is_wp_error') && is_wp_error($result)) {
        return ['id' => 0, 'error' => (string) $result->get_error_message()];
    }

    $term_id = 0;
    if (is_array($result) && isset($result['term_id'])) {
        $term_id = intval($result['term_id']);
    } elseif (is_numeric($result)) {
        $term_id = intval($result);
    }

    if ($term_id <= 0) {
        return ['id' => 0, 'error' => 'wp_insert_term did not return valid term_id'];
    }

    return ['id' => $term_id, 'error' => ''];
}

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
 * AJAX: 按需读取指定 post type 的可导出字段列表
 */
function spe_ajax_get_post_type_export_fields()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }

    check_ajax_referer('spe_tax_fields_nonce', 'nonce');

    $post_type = isset($_POST['post_type']) ? sanitize_text_field((string) $_POST['post_type']) : '';
    if ($post_type === '' || !post_type_exists($post_type)) {
        wp_send_json_error(['message' => 'invalid post_type'], 400);
    }

    $object = get_post_type_object($post_type);
    if (!$object || empty($object->public)) {
        wp_send_json_error(['message' => 'unsupported post_type'], 400);
    }

    $options = spe_get_post_type_export_field_options($post_type);
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
    static $options_cache = [];
    $taxonomy_key = trim((string) $taxonomy);
    if ($taxonomy_key !== '' && isset($options_cache[$taxonomy_key])) {
        return $options_cache[$taxonomy_key];
    }

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

    if ($taxonomy_key !== '') {
        $options_cache[$taxonomy_key] = $options;
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
 * post type 导出的基础字段定义（固定顺序）
 */
function spe_get_post_type_base_export_fields($post_type = 'post')
{
    $post_type = trim((string) $post_type);
    $is_product = ($post_type === 'product');

    return [
        'id' => ['header' => 'ID', 'label' => 'ID（必选）'],
        'title' => ['header' => '标题', 'label' => '标题'],
        'slug' => ['header' => 'Slug', 'label' => 'Slug'],
        'excerpt' => ['header' => $is_product ? '短描述' : '摘要', 'label' => $is_product ? '短描述' : '摘要'],
        'content' => ['header' => $is_product ? '长描述' : '内容', 'label' => $is_product ? '长描述' : '内容'],
        'meta_title' => ['header' => 'Meta Title', 'label' => 'Meta Title'],
        'meta_description' => ['header' => 'Meta Description', 'label' => 'Meta Description'],
    ];
}

/**
 * post type 导出时排除的 meta key
 *
 * @return string[]
 */
function spe_get_post_type_export_exclude_keys($post_type = 'post')
{
    $post_type = trim((string) $post_type);
    $exclude_keys = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_thumbnail_id',
        '_wp_page_template',
    ];

    if ($post_type === 'product') {
        $exclude_keys = array_merge($exclude_keys, [
            '_product_image_gallery',
            '_product_version',
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
            'total_sales',
        ]);
    }

    return array_values(array_unique($exclude_keys));
}

/**
 * 获取某个 post type 可导出的自定义字段
 *
 * @return string[]
 */
function spe_get_post_type_custom_fields($post_type)
{
    $post_type = trim((string) $post_type);
    if ($post_type === '') {
        return [];
    }

    global $wpdb;
    $all_meta_keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pm.meta_key
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = %s
        AND p.post_status IN ('publish', 'draft', 'private')
        AND pm.meta_key NOT LIKE '\_%'
        ORDER BY pm.meta_key",
        $post_type
    ));

    if (!is_array($all_meta_keys)) {
        $all_meta_keys = [];
    }

    return spe_resolve_post_export_custom_fields(
        $post_type,
        $all_meta_keys,
        spe_get_post_type_export_exclude_keys($post_type)
    );
}

/**
 * 获取某个 post type 的字段选项（用于 UI 选择器）
 */
function spe_get_post_type_export_field_options($post_type, $custom_fields_override = null)
{
    static $options_cache = [];
    $post_type = trim((string) $post_type);
    if ($post_type === '') {
        return [];
    }

    if ($custom_fields_override === null && isset($options_cache[$post_type])) {
        return $options_cache[$post_type];
    }

    $options = [];
    $base_fields = spe_get_post_type_base_export_fields($post_type);
    foreach ($base_fields as $key => $meta) {
        $options[] = [
            'value' => $key,
            'label' => $meta['label'],
            'group' => 'base',
        ];
    }

    $custom_fields = is_array($custom_fields_override)
        ? array_values(array_unique(array_filter(array_map(function ($item) {
            return trim((string) $item);
        }, $custom_fields_override))))
        : spe_get_post_type_custom_fields($post_type);

    foreach ($custom_fields as $field) {
        $options[] = [
            'value' => 'field:' . $field,
            'label' => $field,
            'group' => 'custom',
        ];
    }

    if ($custom_fields_override === null) {
        $options_cache[$post_type] = $options;
    }

    return $options;
}

/**
 * 按 options 解析并规范化导出字段（默认全选，且始终保留 required 值）
 *
 * @param array<int,array{value:string}> $options
 * @param array<int,string> $selected_fields
 * @param array<int,string> $required_values
 * @return string[]
 */
function spe_resolve_export_selected_fields_from_options($options, $selected_fields, $required_values = ['id'])
{
    $options = is_array($options) ? $options : [];
    $valid_values = array_values(array_unique(array_filter(array_map(function ($item) {
        if (!is_array($item)) {
            return '';
        }
        return trim((string) ($item['value'] ?? ''));
    }, $options))));

    $selected = array_values(array_unique(array_filter(array_map(
        function ($item) {
            return sanitize_text_field((string) $item);
        },
        is_array($selected_fields) ? $selected_fields : []
    ))));

    if (empty($selected)) {
        $selected = $valid_values;
    }

    $required_values = is_array($required_values) ? $required_values : ['id'];
    foreach ($required_values as $required_value) {
        $required_value = trim((string) $required_value);
        if ($required_value !== '' && !in_array($required_value, $selected, true)) {
            $selected[] = $required_value;
        }
    }

    $selected_lookup = array_fill_keys($selected, true);
    $ordered_selected = [];
    foreach ($valid_values as $value) {
        if (isset($selected_lookup[$value])) {
            $ordered_selected[] = $value;
        }
    }

    foreach ($required_values as $required_value) {
        $required_value = trim((string) $required_value);
        if ($required_value !== '' && !in_array($required_value, $ordered_selected, true)) {
            array_unshift($ordered_selected, $required_value);
        }
    }

    return $ordered_selected;
}

/**
 * 解析并规范化 post type 导出字段（默认全选，且始终保留 ID）
 *
 * @return string[]
 */
function spe_resolve_post_type_export_fields($post_type, $selected_fields, $custom_fields_override = null)
{
    $options = spe_get_post_type_export_field_options($post_type, $custom_fields_override);
    return spe_resolve_export_selected_fields_from_options($options, $selected_fields, ['id']);
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

    $custom_fields = spe_resolve_post_export_custom_fields('product', $all_meta_keys, $exclude_keys);

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

    $custom_fields = spe_resolve_post_export_custom_fields('page', $all_meta_keys, $exclude_keys);

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

    $custom_fields = spe_resolve_post_export_custom_fields('post', $all_meta_keys, $exclude_keys);

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
        $selected_fields = isset($_GET['spe_product_fields']) ? (array) $_GET['spe_product_fields'] : [];
        spe_export_products($selected_fields);
    }
    if (isset($_GET['spe_action']) && $_GET['spe_action'] === 'export_taxonomies') {
        $taxonomy = isset($_GET['spe_taxonomy']) ? sanitize_text_field($_GET['spe_taxonomy']) : 'product_cat';
        $selected_fields = isset($_GET['spe_tax_fields']) ? (array) $_GET['spe_tax_fields'] : [];
        spe_export_taxonomies($taxonomy, $selected_fields);
    }
    if (isset($_GET['spe_action']) && $_GET['spe_action'] === 'export_pages') {
        $selected_fields = isset($_GET['spe_page_fields']) ? (array) $_GET['spe_page_fields'] : [];
        spe_export_pages($selected_fields);
    }
    if (isset($_GET['spe_action']) && $_GET['spe_action'] === 'export_posts') {
        $selected_fields = isset($_GET['spe_post_fields']) ? (array) $_GET['spe_post_fields'] : [];
        spe_export_posts($selected_fields);
    }

    if (isset($_POST['spe_save_profile_products']) && isset($_POST['spe_import_products']) && wp_verify_nonce($_POST['spe_import_products'], 'spe_import')) {
        $saved = spe_save_import_match_profile('product', sanitize_text_field((string) ($_POST['spe_import_products_profile_name'] ?? '')), [
            'allow_insert' => spe_request_to_bool($_POST['spe_import_products_allow_insert'] ?? ''),
            'unique_meta_field' => sanitize_text_field((string) ($_POST['spe_import_products_unique_meta_field'] ?? '')),
            'unique_meta_key' => sanitize_text_field((string) ($_POST['spe_import_products_unique_meta_key'] ?? '')),
        ]);
        if (!empty($saved['ok']) && !empty($saved['id'])) {
            $_POST['spe_import_products_profile_id'] = (string) $saved['id'];
        }
        $result = ['error' => !$saved['ok'], 'message' => (string) $saved['message'], 'debug' => ''];
        spe_update_import_ui_defaults(
            'product',
            spe_request_to_bool($_POST['spe_import_products_allow_insert'] ?? ''),
            sanitize_text_field((string) ($_POST['spe_import_products_unique_meta_field'] ?? ''))
        );
    } elseif (isset($_POST['spe_import_products']) && wp_verify_nonce($_POST['spe_import_products'], 'spe_import')) {
        $result = spe_import_products();
        spe_update_import_ui_defaults(
            'product',
            spe_request_to_bool($_POST['spe_import_products_allow_insert'] ?? ''),
            sanitize_text_field((string) ($_POST['spe_import_products_unique_meta_field'] ?? ''))
        );
    }

    if (isset($_POST['spe_save_profile_taxonomies']) && isset($_POST['spe_import_taxonomies']) && wp_verify_nonce($_POST['spe_import_taxonomies'], 'spe_import_tax')) {
        $saved = spe_save_import_match_profile('taxonomy', sanitize_text_field((string) ($_POST['spe_import_taxonomies_profile_name'] ?? '')), [
            'allow_insert' => spe_request_to_bool($_POST['spe_import_taxonomies_allow_insert'] ?? ''),
            'unique_meta_field' => sanitize_text_field((string) ($_POST['spe_import_taxonomies_unique_meta_field'] ?? '')),
            'unique_meta_key' => sanitize_text_field((string) ($_POST['spe_import_taxonomies_unique_meta_key'] ?? '')),
        ]);
        if (!empty($saved['ok']) && !empty($saved['id'])) {
            $_POST['spe_import_taxonomies_profile_id'] = (string) $saved['id'];
        }
        $result = ['error' => !$saved['ok'], 'message' => (string) $saved['message'], 'debug' => ''];
        spe_update_import_ui_defaults(
            'taxonomy',
            spe_request_to_bool($_POST['spe_import_taxonomies_allow_insert'] ?? ''),
            sanitize_text_field((string) ($_POST['spe_import_taxonomies_unique_meta_field'] ?? ''))
        );
    } elseif (isset($_POST['spe_import_taxonomies']) && wp_verify_nonce($_POST['spe_import_taxonomies'], 'spe_import_tax')) {
        $taxonomy = isset($_POST['spe_taxonomy']) ? sanitize_text_field($_POST['spe_taxonomy']) : 'product_cat';
        $result = spe_import_taxonomies($taxonomy);
        spe_update_import_ui_defaults(
            'taxonomy',
            spe_request_to_bool($_POST['spe_import_taxonomies_allow_insert'] ?? ''),
            sanitize_text_field((string) ($_POST['spe_import_taxonomies_unique_meta_field'] ?? ''))
        );
    }

    if (isset($_POST['spe_save_profile_pages']) && isset($_POST['spe_import_pages']) && wp_verify_nonce($_POST['spe_import_pages'], 'spe_import_pages')) {
        $saved = spe_save_import_match_profile('page', sanitize_text_field((string) ($_POST['spe_import_pages_profile_name'] ?? '')), [
            'allow_insert' => spe_request_to_bool($_POST['spe_import_pages_allow_insert'] ?? ''),
            'unique_meta_field' => sanitize_text_field((string) ($_POST['spe_import_pages_unique_meta_field'] ?? '')),
            'unique_meta_key' => sanitize_text_field((string) ($_POST['spe_import_pages_unique_meta_key'] ?? '')),
        ]);
        if (!empty($saved['ok']) && !empty($saved['id'])) {
            $_POST['spe_import_pages_profile_id'] = (string) $saved['id'];
        }
        $result = ['error' => !$saved['ok'], 'message' => (string) $saved['message'], 'debug' => ''];
        spe_update_import_ui_defaults(
            'page',
            spe_request_to_bool($_POST['spe_import_pages_allow_insert'] ?? ''),
            sanitize_text_field((string) ($_POST['spe_import_pages_unique_meta_field'] ?? ''))
        );
    } elseif (isset($_POST['spe_import_pages']) && wp_verify_nonce($_POST['spe_import_pages'], 'spe_import_pages')) {
        $result = spe_import_pages();
        spe_update_import_ui_defaults(
            'page',
            spe_request_to_bool($_POST['spe_import_pages_allow_insert'] ?? ''),
            sanitize_text_field((string) ($_POST['spe_import_pages_unique_meta_field'] ?? ''))
        );
    }

    if (isset($_POST['spe_save_profile_posts']) && isset($_POST['spe_import_posts']) && wp_verify_nonce($_POST['spe_import_posts'], 'spe_import_posts')) {
        $saved = spe_save_import_match_profile('post', sanitize_text_field((string) ($_POST['spe_import_posts_profile_name'] ?? '')), [
            'allow_insert' => spe_request_to_bool($_POST['spe_import_posts_allow_insert'] ?? ''),
            'unique_meta_field' => sanitize_text_field((string) ($_POST['spe_import_posts_unique_meta_field'] ?? '')),
            'unique_meta_key' => sanitize_text_field((string) ($_POST['spe_import_posts_unique_meta_key'] ?? '')),
        ]);
        if (!empty($saved['ok']) && !empty($saved['id'])) {
            $_POST['spe_import_posts_profile_id'] = (string) $saved['id'];
        }
        $result = ['error' => !$saved['ok'], 'message' => (string) $saved['message'], 'debug' => ''];
        spe_update_import_ui_defaults(
            'post',
            spe_request_to_bool($_POST['spe_import_posts_allow_insert'] ?? ''),
            sanitize_text_field((string) ($_POST['spe_import_posts_unique_meta_field'] ?? ''))
        );
    } elseif (isset($_POST['spe_import_posts']) && wp_verify_nonce($_POST['spe_import_posts'], 'spe_import_posts')) {
        $result = spe_import_posts();
        spe_update_import_ui_defaults(
            'post',
            spe_request_to_bool($_POST['spe_import_posts_allow_insert'] ?? ''),
            sanitize_text_field((string) ($_POST['spe_import_posts_unique_meta_field'] ?? ''))
        );
    }

    $taxonomies = spe_get_public_taxonomy_objects();
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
    $import_taxonomy_ui = isset($_POST['spe_taxonomy']) ? sanitize_text_field((string) $_POST['spe_taxonomy']) : $default_taxonomy;
    if (!isset($taxonomies[$import_taxonomy_ui])) {
        $import_taxonomy_ui = $default_taxonomy;
    }

    $selected_tax_fields_ui = array_values(array_unique(array_filter(array_map(
        function ($item) {
            return sanitize_text_field((string) $item);
        },
        isset($_GET['spe_tax_fields']) ? (array) $_GET['spe_tax_fields'] : []
    ))));
    if (!empty($selected_tax_fields_ui) && !in_array('id', $selected_tax_fields_ui, true)) {
        array_unshift($selected_tax_fields_ui, 'id');
    }

    $product_field_options = spe_get_post_type_export_field_options('product');
    $page_field_options = spe_get_post_type_export_field_options('page');
    $post_field_options = spe_get_post_type_export_field_options('post');

    $selected_product_fields_ui = spe_resolve_export_selected_fields_from_options(
        $product_field_options,
        isset($_GET['spe_product_fields']) ? (array) $_GET['spe_product_fields'] : [],
        ['id']
    );
    $selected_page_fields_ui = spe_resolve_export_selected_fields_from_options(
        $page_field_options,
        isset($_GET['spe_page_fields']) ? (array) $_GET['spe_page_fields'] : [],
        ['id']
    );
    $selected_post_fields_ui = spe_resolve_export_selected_fields_from_options(
        $post_field_options,
        isset($_GET['spe_post_fields']) ? (array) $_GET['spe_post_fields'] : [],
        ['id']
    );

    $tax_field_nonce = wp_create_nonce('spe_tax_fields_nonce');
    $import_ui_defaults = spe_get_import_ui_defaults();
    $product_import_defaults = isset($import_ui_defaults['product']) && is_array($import_ui_defaults['product']) ? $import_ui_defaults['product'] : ['allow_insert' => false, 'unique_meta_field' => ''];
    $page_import_defaults = isset($import_ui_defaults['page']) && is_array($import_ui_defaults['page']) ? $import_ui_defaults['page'] : ['allow_insert' => false, 'unique_meta_field' => ''];
    $post_import_defaults = isset($import_ui_defaults['post']) && is_array($import_ui_defaults['post']) ? $import_ui_defaults['post'] : ['allow_insert' => false, 'unique_meta_field' => ''];
    $taxonomy_import_defaults = isset($import_ui_defaults['taxonomy']) && is_array($import_ui_defaults['taxonomy']) ? $import_ui_defaults['taxonomy'] : ['allow_insert' => false, 'unique_meta_field' => ''];
    $import_match_profiles = spe_get_import_match_profiles();
    $product_import_profiles = isset($import_match_profiles['product']) && is_array($import_match_profiles['product']) ? $import_match_profiles['product'] : [];
    $page_import_profiles = isset($import_match_profiles['page']) && is_array($import_match_profiles['page']) ? $import_match_profiles['page'] : [];
    $post_import_profiles = isset($import_match_profiles['post']) && is_array($import_match_profiles['post']) ? $import_match_profiles['post'] : [];
    $taxonomy_import_profiles = isset($import_match_profiles['taxonomy']) && is_array($import_match_profiles['taxonomy']) ? $import_match_profiles['taxonomy'] : [];
    $selected_product_profile_id = isset($_POST['spe_import_products_profile_id']) ? sanitize_text_field((string) $_POST['spe_import_products_profile_id']) : '';
    $selected_page_profile_id = isset($_POST['spe_import_pages_profile_id']) ? sanitize_text_field((string) $_POST['spe_import_pages_profile_id']) : '';
    $selected_post_profile_id = isset($_POST['spe_import_posts_profile_id']) ? sanitize_text_field((string) $_POST['spe_import_posts_profile_id']) : '';
    $selected_taxonomy_profile_id = isset($_POST['spe_import_taxonomies_profile_id']) ? sanitize_text_field((string) $_POST['spe_import_taxonomies_profile_id']) : '';
    $product_import_columns_ui = isset($_POST['spe_import_products_columns']) ? sanitize_text_field((string) $_POST['spe_import_products_columns']) : '';
    $page_import_columns_ui = isset($_POST['spe_import_pages_columns']) ? sanitize_text_field((string) $_POST['spe_import_pages_columns']) : '';
    $post_import_columns_ui = isset($_POST['spe_import_posts_columns']) ? sanitize_text_field((string) $_POST['spe_import_posts_columns']) : '';
    $taxonomy_import_columns_ui = isset($_POST['spe_import_taxonomies_columns']) ? sanitize_text_field((string) $_POST['spe_import_taxonomies_columns']) : '';

    $selected_product_profile = spe_get_import_match_profile_by_id('product', $selected_product_profile_id);
    if (is_array($selected_product_profile)) {
        $product_import_defaults['allow_insert'] = !empty($selected_product_profile['allow_insert']);
        $product_import_defaults['unique_meta_field'] = trim((string) ($selected_product_profile['unique_meta_field'] ?? ''));
    }
    $selected_page_profile = spe_get_import_match_profile_by_id('page', $selected_page_profile_id);
    if (is_array($selected_page_profile)) {
        $page_import_defaults['allow_insert'] = !empty($selected_page_profile['allow_insert']);
        $page_import_defaults['unique_meta_field'] = trim((string) ($selected_page_profile['unique_meta_field'] ?? ''));
    }
    $selected_post_profile = spe_get_import_match_profile_by_id('post', $selected_post_profile_id);
    if (is_array($selected_post_profile)) {
        $post_import_defaults['allow_insert'] = !empty($selected_post_profile['allow_insert']);
        $post_import_defaults['unique_meta_field'] = trim((string) ($selected_post_profile['unique_meta_field'] ?? ''));
    }
    $selected_taxonomy_profile = spe_get_import_match_profile_by_id('taxonomy', $selected_taxonomy_profile_id);
    if (is_array($selected_taxonomy_profile)) {
        $taxonomy_import_defaults['allow_insert'] = !empty($selected_taxonomy_profile['allow_insert']);
        $taxonomy_import_defaults['unique_meta_field'] = trim((string) ($selected_taxonomy_profile['unique_meta_field'] ?? ''));
    }

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

                            <div class="spe-field-picker">
                                <div class="spe-field-toolbar">
                                    <label for="spe-product-field-search" style="margin:0;">导出字段</label>
                                    <button type="button" class="button spe-button-ghost" data-field-select-all="#spe-product-field-list">全选</button>
                                    <button type="button" class="button spe-button-ghost" data-field-clear="#spe-product-field-list">清空</button>
                                </div>
                                <input type="text" id="spe-product-field-search" placeholder="搜索字段名..." data-field-search="#spe-product-field-list">
                                <div class="spe-field-list" id="spe-product-field-list">
                                    <?php foreach ($product_field_options as $option): ?>
                                        <?php
                                        $field_value = isset($option['value']) ? (string) $option['value'] : '';
                                        $field_label = isset($option['label']) ? (string) $option['label'] : $field_value;
                                        if ($field_value === '') {
                                            continue;
                                        }
                                        $is_required = ($field_value === 'id');
                                        $checked = in_array($field_value, $selected_product_fields_ui, true);
                                        ?>
                                        <label class="spe-field-item" data-label="<?php echo esc_attr(strtolower($field_label)); ?>">
                                            <input type="checkbox" name="spe_product_fields[]" value="<?php echo esc_attr($field_value); ?>" <?php checked($checked, true); ?> <?php echo $is_required ? 'data-required="1"' : ''; ?>>
                                            <span><?php echo esc_html($field_label); ?></span>
                                            <?php if ($is_required): ?>
                                                <span class="spe-pill">必选</span>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="spe-hint">ID 为必选字段；默认全选。可只勾选需要导出的列名。</p>
                            </div>

                            <div class="spe-button-row">
                                <button type="submit" class="button button-primary">下载产品 CSV</button>
                            </div>
                        </form>
                    </div>

                    <div class="spe-card">
                        <h3>产品导入</h3>
                        <p>上传 UTF-8 编码 CSV 文件，按 ID/Slug/唯一键匹配更新产品。</p>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('spe_import', 'spe_import_products'); ?>
                            <input type="file" name="spe_import_file" accept=".csv" required>
                            <div class="spe-form-row">
                                <input type="hidden" name="spe_import_products_allow_insert" value="0">
                                <label class="spe-check-item">
                                    <input type="checkbox" name="spe_import_products_allow_insert" value="1" data-spe-default="<?php echo !empty($product_import_defaults['allow_insert']) ? '1' : '0'; ?>" <?php checked(!empty($product_import_defaults['allow_insert']), true); ?>>
                                    未匹配时新增产品（草稿）
                                </label>
                                <div class="spe-hint">默认关闭。开启后，未匹配行会新增产品并继续写入字段。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>唯一键字段（可选）</label>
                                <input type="text" name="spe_import_products_unique_meta_field" placeholder="例如：supplier_sku"
                                    data-spe-default="<?php echo esc_attr((string) ($product_import_defaults['unique_meta_field'] ?? '')); ?>"
                                    value="<?php echo esc_attr((string) ($product_import_defaults['unique_meta_field'] ?? '')); ?>">
                                <div class="spe-hint">当 ID/Slug 未命中时，按该 meta 字段值做唯一匹配。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>仅导入这些列名（可选）</label>
                                <input type="text" name="spe_import_products_columns" placeholder="例如：ID, 标题, Meta Title, supplier_sku"
                                    value="<?php echo esc_attr($product_import_columns_ui); ?>">
                                <div class="spe-hint">留空表示导入 CSV 中所有列。多个列名可用英文逗号、中文逗号或换行分隔。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>匹配模板（可选）</label>
                                <select name="spe_import_products_profile_id" data-spe-profile-sync="1" data-spe-allow-target="spe_import_products_allow_insert" data-spe-unique-target="spe_import_products_unique_meta_field">
                                    <option value="">不使用模板</option>
                                    <?php foreach ($product_import_profiles as $profile_item): ?>
                                        <?php
                                        $profile_id = isset($profile_item['id']) ? (string) $profile_item['id'] : '';
                                        $profile_name = isset($profile_item['name']) ? (string) $profile_item['name'] : $profile_id;
                                        $profile_allow_insert = !empty($profile_item['allow_insert']) ? '1' : '0';
                                        $profile_unique_meta_field = (string) ($profile_item['unique_meta_field'] ?? '');
                                        if ($profile_id === '') {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr($profile_id); ?>" data-allow-insert="<?php echo esc_attr($profile_allow_insert); ?>" data-unique-meta-field="<?php echo esc_attr($profile_unique_meta_field); ?>" <?php selected($profile_id, $selected_product_profile_id); ?>>
                                            <?php echo esc_html($profile_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="spe-hint">导入时会先应用模板，再应用当前表单值。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>保存当前设置为模板（可选）</label>
                                <input type="text" name="spe_import_products_profile_name" placeholder="例如：产品-SKU匹配">
                            </div>
                            <div class="spe-button-row">
                                <button type="submit" class="button button-secondary">上传产品 CSV</button>
                                <button type="submit" class="button spe-button-ghost" formnovalidate name="spe_save_profile_products" value="1">
                                    保存产品导入模板
                                </button>
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
                        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" id="export-pages-form">
                            <input type="hidden" name="page" value="content-import-export">
                            <input type="hidden" name="spe_action" value="export_pages">

                            <div class="spe-field-picker">
                                <div class="spe-field-toolbar">
                                    <label for="spe-page-field-search" style="margin:0;">导出字段</label>
                                    <button type="button" class="button spe-button-ghost" data-field-select-all="#spe-page-field-list">全选</button>
                                    <button type="button" class="button spe-button-ghost" data-field-clear="#spe-page-field-list">清空</button>
                                </div>
                                <input type="text" id="spe-page-field-search" placeholder="搜索字段名..." data-field-search="#spe-page-field-list">
                                <div class="spe-field-list" id="spe-page-field-list">
                                    <?php foreach ($page_field_options as $option): ?>
                                        <?php
                                        $field_value = isset($option['value']) ? (string) $option['value'] : '';
                                        $field_label = isset($option['label']) ? (string) $option['label'] : $field_value;
                                        if ($field_value === '') {
                                            continue;
                                        }
                                        $is_required = ($field_value === 'id');
                                        $checked = in_array($field_value, $selected_page_fields_ui, true);
                                        ?>
                                        <label class="spe-field-item" data-label="<?php echo esc_attr(strtolower($field_label)); ?>">
                                            <input type="checkbox" name="spe_page_fields[]" value="<?php echo esc_attr($field_value); ?>" <?php checked($checked, true); ?> <?php echo $is_required ? 'data-required="1"' : ''; ?>>
                                            <span><?php echo esc_html($field_label); ?></span>
                                            <?php if ($is_required): ?>
                                                <span class="spe-pill">必选</span>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="spe-hint">ID 为必选字段；默认全选。可只勾选需要导出的列名。</p>
                            </div>

                            <div class="spe-button-row">
                                <button type="submit" class="button button-primary">下载页面 CSV</button>
                            </div>
                        </form>
                    </div>

                    <div class="spe-card">
                        <h3>页面导入</h3>
                        <p>上传 CSV 并按 ID/Slug/唯一键匹配更新页面。</p>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('spe_import_pages', 'spe_import_pages'); ?>
                            <input type="file" name="spe_import_pages_file" accept=".csv" required>
                            <div class="spe-form-row">
                                <input type="hidden" name="spe_import_pages_allow_insert" value="0">
                                <label class="spe-check-item">
                                    <input type="checkbox" name="spe_import_pages_allow_insert" value="1" data-spe-default="<?php echo !empty($page_import_defaults['allow_insert']) ? '1' : '0'; ?>" <?php checked(!empty($page_import_defaults['allow_insert']), true); ?>>
                                    未匹配时新增页面（草稿）
                                </label>
                                <div class="spe-hint">默认关闭。开启后，未匹配行会新增页面并继续写入字段。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>唯一键字段（可选）</label>
                                <input type="text" name="spe_import_pages_unique_meta_field" placeholder="例如：external_page_id"
                                    data-spe-default="<?php echo esc_attr((string) ($page_import_defaults['unique_meta_field'] ?? '')); ?>"
                                    value="<?php echo esc_attr((string) ($page_import_defaults['unique_meta_field'] ?? '')); ?>">
                                <div class="spe-hint">当 ID/Slug 未命中时，按该 meta 字段值做唯一匹配。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>仅导入这些列名（可选）</label>
                                <input type="text" name="spe_import_pages_columns" placeholder="例如：ID, 标题, Meta Description, external_page_id"
                                    value="<?php echo esc_attr($page_import_columns_ui); ?>">
                                <div class="spe-hint">留空表示导入 CSV 中所有列。多个列名可用英文逗号、中文逗号或换行分隔。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>匹配模板（可选）</label>
                                <select name="spe_import_pages_profile_id" data-spe-profile-sync="1" data-spe-allow-target="spe_import_pages_allow_insert" data-spe-unique-target="spe_import_pages_unique_meta_field">
                                    <option value="">不使用模板</option>
                                    <?php foreach ($page_import_profiles as $profile_item): ?>
                                        <?php
                                        $profile_id = isset($profile_item['id']) ? (string) $profile_item['id'] : '';
                                        $profile_name = isset($profile_item['name']) ? (string) $profile_item['name'] : $profile_id;
                                        $profile_allow_insert = !empty($profile_item['allow_insert']) ? '1' : '0';
                                        $profile_unique_meta_field = (string) ($profile_item['unique_meta_field'] ?? '');
                                        if ($profile_id === '') {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr($profile_id); ?>" data-allow-insert="<?php echo esc_attr($profile_allow_insert); ?>" data-unique-meta-field="<?php echo esc_attr($profile_unique_meta_field); ?>" <?php selected($profile_id, $selected_page_profile_id); ?>>
                                            <?php echo esc_html($profile_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="spe-hint">导入时会先应用模板，再应用当前表单值。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>保存当前设置为模板（可选）</label>
                                <input type="text" name="spe_import_pages_profile_name" placeholder="例如：页面-外部ID匹配">
                            </div>
                            <div class="spe-button-row">
                                <button type="submit" class="button button-secondary">上传页面 CSV</button>
                                <button type="submit" class="button spe-button-ghost" formnovalidate name="spe_save_profile_pages" value="1">
                                    保存页面导入模板
                                </button>
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

                            <div class="spe-field-picker">
                                <div class="spe-field-toolbar">
                                    <label for="spe-post-field-search" style="margin:0;">导出字段</label>
                                    <button type="button" class="button spe-button-ghost" data-field-select-all="#spe-post-field-list">全选</button>
                                    <button type="button" class="button spe-button-ghost" data-field-clear="#spe-post-field-list">清空</button>
                                </div>
                                <input type="text" id="spe-post-field-search" placeholder="搜索字段名..." data-field-search="#spe-post-field-list">
                                <div class="spe-field-list" id="spe-post-field-list">
                                    <?php foreach ($post_field_options as $option): ?>
                                        <?php
                                        $field_value = isset($option['value']) ? (string) $option['value'] : '';
                                        $field_label = isset($option['label']) ? (string) $option['label'] : $field_value;
                                        if ($field_value === '') {
                                            continue;
                                        }
                                        $is_required = ($field_value === 'id');
                                        $checked = in_array($field_value, $selected_post_fields_ui, true);
                                        ?>
                                        <label class="spe-field-item" data-label="<?php echo esc_attr(strtolower($field_label)); ?>">
                                            <input type="checkbox" name="spe_post_fields[]" value="<?php echo esc_attr($field_value); ?>" <?php checked($checked, true); ?> <?php echo $is_required ? 'data-required="1"' : ''; ?>>
                                            <span><?php echo esc_html($field_label); ?></span>
                                            <?php if ($is_required): ?>
                                                <span class="spe-pill">必选</span>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="spe-hint">ID 为必选字段；默认全选。可只勾选需要导出的列名。</p>
                            </div>

                            <div class="spe-button-row">
                                <button type="submit" class="button button-primary">下载文章 CSV</button>
                            </div>
                        </form>
                    </div>

                    <div class="spe-card">
                        <h3>文章导入</h3>
                        <p>上传 CSV 并按 ID/Slug/唯一键匹配更新文章。</p>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('spe_import_posts', 'spe_import_posts'); ?>
                            <input type="file" name="spe_import_posts_file" accept=".csv" required>
                            <div class="spe-form-row">
                                <input type="hidden" name="spe_import_posts_allow_insert" value="0">
                                <label class="spe-check-item">
                                    <input type="checkbox" name="spe_import_posts_allow_insert" value="1" data-spe-default="<?php echo !empty($post_import_defaults['allow_insert']) ? '1' : '0'; ?>" <?php checked(!empty($post_import_defaults['allow_insert']), true); ?>>
                                    未匹配时新增文章（草稿）
                                </label>
                                <div class="spe-hint">默认关闭。开启后，未匹配行会新增文章并继续写入字段。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>唯一键字段（可选）</label>
                                <input type="text" name="spe_import_posts_unique_meta_field" placeholder="例如：external_post_id"
                                    data-spe-default="<?php echo esc_attr((string) ($post_import_defaults['unique_meta_field'] ?? '')); ?>"
                                    value="<?php echo esc_attr((string) ($post_import_defaults['unique_meta_field'] ?? '')); ?>">
                                <div class="spe-hint">当 ID/Slug 未命中时，按该 meta 字段值做唯一匹配。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>仅导入这些列名（可选）</label>
                                <input type="text" name="spe_import_posts_columns" placeholder="例如：ID, 标题, Meta Title, external_post_id"
                                    value="<?php echo esc_attr($post_import_columns_ui); ?>">
                                <div class="spe-hint">留空表示导入 CSV 中所有列。多个列名可用英文逗号、中文逗号或换行分隔。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>匹配模板（可选）</label>
                                <select name="spe_import_posts_profile_id" data-spe-profile-sync="1" data-spe-allow-target="spe_import_posts_allow_insert" data-spe-unique-target="spe_import_posts_unique_meta_field">
                                    <option value="">不使用模板</option>
                                    <?php foreach ($post_import_profiles as $profile_item): ?>
                                        <?php
                                        $profile_id = isset($profile_item['id']) ? (string) $profile_item['id'] : '';
                                        $profile_name = isset($profile_item['name']) ? (string) $profile_item['name'] : $profile_id;
                                        $profile_allow_insert = !empty($profile_item['allow_insert']) ? '1' : '0';
                                        $profile_unique_meta_field = (string) ($profile_item['unique_meta_field'] ?? '');
                                        if ($profile_id === '') {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr($profile_id); ?>" data-allow-insert="<?php echo esc_attr($profile_allow_insert); ?>" data-unique-meta-field="<?php echo esc_attr($profile_unique_meta_field); ?>" <?php selected($profile_id, $selected_post_profile_id); ?>>
                                            <?php echo esc_html($profile_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="spe-hint">导入时会先应用模板，再应用当前表单值。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>保存当前设置为模板（可选）</label>
                                <input type="text" name="spe_import_posts_profile_name" placeholder="例如：文章-外部ID匹配">
                            </div>
                            <div class="spe-button-row">
                                <button type="submit" class="button button-secondary">上传文章 CSV</button>
                                <button type="submit" class="button spe-button-ghost" formnovalidate name="spe_save_profile_posts" value="1">
                                    保存文章导入模板
                                </button>
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
                        <p>上传 CSV 并按 ID/Slug/唯一键匹配更新分类字段。</p>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('spe_import_tax', 'spe_import_taxonomies'); ?>

                            <div class="spe-form-row">
                                <label>选择目标分类法</label>
                                <select name="spe_taxonomy">
                                    <?php
                                    foreach ($taxonomies as $tax) {
                                        $selected_attr = selected($tax->name, $import_taxonomy_ui, false);
                                        echo '<option value="' . esc_attr($tax->name) . '" ' . $selected_attr . '>' . esc_html($tax->label) . ' (' . esc_html($tax->name) . ')</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <input type="file" name="spe_import_taxonomy_file" accept=".csv" required>
                            <div class="spe-form-row">
                                <input type="hidden" name="spe_import_taxonomies_allow_insert" value="0">
                                <label class="spe-check-item">
                                    <input type="checkbox" name="spe_import_taxonomies_allow_insert" value="1" data-spe-default="<?php echo !empty($taxonomy_import_defaults['allow_insert']) ? '1' : '0'; ?>" <?php checked(!empty($taxonomy_import_defaults['allow_insert']), true); ?>>
                                    未匹配时新增分类
                                </label>
                                <div class="spe-hint">默认关闭。开启后，未匹配行会新增 term 并继续写入字段。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>唯一键字段（可选）</label>
                                <input type="text" name="spe_import_taxonomies_unique_meta_field" placeholder="例如：legacy_code"
                                    data-spe-default="<?php echo esc_attr((string) ($taxonomy_import_defaults['unique_meta_field'] ?? '')); ?>"
                                    value="<?php echo esc_attr((string) ($taxonomy_import_defaults['unique_meta_field'] ?? '')); ?>">
                                <div class="spe-hint">当 ID/Slug 未命中时，按该 term_meta 字段值做唯一匹配。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>仅导入这些列名（可选）</label>
                                <input type="text" name="spe_import_taxonomies_columns" placeholder="例如：ID, 标题, Meta Description, legacy_code"
                                    value="<?php echo esc_attr($taxonomy_import_columns_ui); ?>">
                                <div class="spe-hint">留空表示导入 CSV 中所有列。多个列名可用英文逗号、中文逗号或换行分隔。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>匹配模板（可选）</label>
                                <select name="spe_import_taxonomies_profile_id" data-spe-profile-sync="1" data-spe-allow-target="spe_import_taxonomies_allow_insert" data-spe-unique-target="spe_import_taxonomies_unique_meta_field">
                                    <option value="">不使用模板</option>
                                    <?php foreach ($taxonomy_import_profiles as $profile_item): ?>
                                        <?php
                                        $profile_id = isset($profile_item['id']) ? (string) $profile_item['id'] : '';
                                        $profile_name = isset($profile_item['name']) ? (string) $profile_item['name'] : $profile_id;
                                        $profile_allow_insert = !empty($profile_item['allow_insert']) ? '1' : '0';
                                        $profile_unique_meta_field = (string) ($profile_item['unique_meta_field'] ?? '');
                                        if ($profile_id === '') {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr($profile_id); ?>" data-allow-insert="<?php echo esc_attr($profile_allow_insert); ?>" data-unique-meta-field="<?php echo esc_attr($profile_unique_meta_field); ?>" <?php selected($profile_id, $selected_taxonomy_profile_id); ?>>
                                            <?php echo esc_html($profile_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="spe-hint">导入时会先应用模板，再应用当前表单值。</div>
                            </div>
                            <div class="spe-form-row">
                                <label>保存当前设置为模板（可选）</label>
                                <input type="text" name="spe_import_taxonomies_profile_name" placeholder="例如：分类-legacy_code匹配">
                            </div>
                            <div class="spe-button-row">
                                <button type="submit" class="button button-secondary">上传分类 CSV</button>
                                <button type="submit" class="button spe-button-ghost" formnovalidate name="spe_save_profile_taxonomies" value="1">
                                    保存分类导入模板
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <section class="spe-section spe-guide">
                <div class="spe-card">
                    <h3>使用说明</h3>
                    <ul>
                        <li>导入支持 ID / Slug / 唯一键（可配置）匹配策略。</li>
                        <li>可选“未匹配时新增”，默认关闭（建议先在测试站验证）。</li>
                        <li>唯一键字段可在每个导入面板直接填写，无需改代码。</li>
                        <li>产品/页面/文章/分类均支持“仅导入指定列名（白名单）”。</li>
                        <li>批量导出可一次下载 taxonomy/product/page/post 多类 CSV（ZIP）。</li>
                        <li>产品/页面/文章/分类导出均支持字段级选择，适合最小化回导流程。</li>
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

                const resolveFieldList = (selector) => {
                    if (!selector) return null;
                    return document.querySelector(selector);
                };

                root.querySelectorAll('[data-field-search]').forEach((input) => {
                    const targetSelector = input.getAttribute('data-field-search');
                    const list = resolveFieldList(targetSelector);
                    if (!list) return;

                    input.addEventListener('input', () => {
                        const keyword = (input.value || '').trim().toLowerCase();
                        list.querySelectorAll('.spe-field-item').forEach((item) => {
                            const label = String(item.getAttribute('data-label') || '').toLowerCase();
                            if (!keyword || label.includes(keyword)) {
                                item.classList.remove('is-hidden');
                            } else {
                                item.classList.add('is-hidden');
                            }
                        });
                    });
                });

                root.querySelectorAll('[data-field-select-all]').forEach((button) => {
                    const targetSelector = button.getAttribute('data-field-select-all');
                    const list = resolveFieldList(targetSelector);
                    if (!list) return;

                    button.addEventListener('click', () => {
                        list.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                            input.checked = true;
                        });
                    });
                });

                root.querySelectorAll('[data-field-clear]').forEach((button) => {
                    const targetSelector = button.getAttribute('data-field-clear');
                    const list = resolveFieldList(targetSelector);
                    if (!list) return;

                    button.addEventListener('click', () => {
                        list.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                            if (input.dataset.required === '1') {
                                input.checked = true;
                            } else {
                                input.checked = false;
                            }
                        });
                    });
                });

                root.querySelectorAll('.spe-field-list').forEach((list) => {
                    list.addEventListener('change', (event) => {
                        const target = event.target;
                        if (!(target instanceof HTMLInputElement) || target.type !== 'checkbox') return;
                        if (target.dataset.required === '1' && !target.checked) {
                            target.checked = true;
                        }
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

                root.querySelectorAll('select[data-spe-profile-sync="1"]').forEach((select) => {
                    const form = select.closest('form');
                    if (!form) return;

                    const allowTarget = select.getAttribute('data-spe-allow-target') || '';
                    const uniqueTarget = select.getAttribute('data-spe-unique-target') || '';
                    const allowCheckbox = allowTarget ? form.querySelector('input[type="checkbox"][name="' + allowTarget + '"]') : null;
                    const uniqueInput = uniqueTarget ? form.querySelector('input[type="text"][name="' + uniqueTarget + '"]') : null;
                    if (!allowCheckbox || !uniqueInput) {
                        return;
                    }

                    const applyProfile = () => {
                        const selectedOption = select.options[select.selectedIndex];
                        const hasProfile = !!select.value;
                        if (!selectedOption || !hasProfile) {
                            allowCheckbox.checked = (allowCheckbox.getAttribute('data-spe-default') || '0') === '1';
                            uniqueInput.value = uniqueInput.getAttribute('data-spe-default') || '';
                            return;
                        }

                        allowCheckbox.checked = (selectedOption.getAttribute('data-allow-insert') || '0') === '1';
                        uniqueInput.value = selectedOption.getAttribute('data-unique-meta-field') || '';
                    };

                    select.addEventListener('change', applyProfile);

                    if (select.value) {
                        applyProfile();
                    }
                });

                const initialTaxonomy = <?php echo wp_json_encode($export_taxonomy_ui); ?>;
                const fieldMap = {};
                const pendingFieldLoads = {};
                const selectedByTax = {};
                selectedByTax[initialTaxonomy] = <?php echo wp_json_encode($selected_tax_fields_ui); ?>;
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
function spe_export_products($selected_fields = [])
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

    // 扫描当前导出范围内全部产品，避免遗漏字段
    $all_meta_keys = $wpdb->get_col("
        SELECT DISTINCT pm.meta_key
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE {$where_clause}
        AND pm.meta_key NOT LIKE '\_%'
        ORDER BY pm.meta_key
    ");
    if (!is_array($all_meta_keys)) {
        $all_meta_keys = [];
    }

    $custom_fields = spe_resolve_post_export_custom_fields(
        'product',
        $all_meta_keys,
        spe_get_post_type_export_exclude_keys('product')
    );

    $base_fields = spe_get_post_type_base_export_fields('product');
    $field_options = spe_get_post_type_export_field_options('product', $custom_fields);
    $selected = spe_resolve_export_selected_fields_from_options($field_options, $selected_fields, ['id']);
    $selected_lookup = array_fill_keys($selected, true);

    $selected_custom_fields = [];
    foreach ($selected as $selected_key) {
        if (strpos($selected_key, 'field:') !== 0) {
            continue;
        }
        $field_name = substr($selected_key, 6);
        if ($field_name !== '' && in_array($field_name, $custom_fields, true)) {
            $selected_custom_fields[] = $field_name;
        }
    }

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
            $empty_header = [];
            foreach ($selected as $selected_key) {
                if (isset($base_fields[$selected_key])) {
                    $empty_header[] = $base_fields[$selected_key]['header'];
                    continue;
                }
                if (strpos($selected_key, 'field:') === 0) {
                    $empty_header[] = substr($selected_key, 6);
                }
            }
            if (empty($empty_header)) {
                $empty_header = ['ID', '标题', 'Slug', '短描述', '长描述'];
            }
            fputcsv($output, $empty_header);
        }
        fclose($output);
        exit;
    }

    $header = [];
    foreach ($base_fields as $base_key => $meta) {
        if (!empty($selected_lookup[$base_key])) {
            $header[] = $meta['header'];
        }
    }
    foreach ($selected_custom_fields as $field) {
        $header[] = $field;
    }

    // 检测哪些字段是附件类型，添加 URL 辅助列
    $attachment_fields = [];
    foreach ($products as $p) {
        foreach ($selected_custom_fields as $field) {
            if (in_array($field, $attachment_fields, true)) {
                continue;
            }
            $value = get_post_meta($p->ID, $field, true);
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

    fputcsv($output, $header);

    // 数据行
    foreach ($products as $p) {
        $id = $p->ID;

        $short_desc = $p->post_excerpt ?: '';
        $long_desc = $p->post_content ?: '';
        $short_desc = str_replace(["\r\n", "\n", "\r"], ' ', $short_desc);
        $long_desc = str_replace(["\r\n", "\n", "\r"], ' ', $long_desc);

        $meta_title = '';
        $meta_desc = '';
        $aioseo_title = get_post_meta($id, '_aioseo_title', true);
        if (is_array($aioseo_title)) {
            $meta_title = $aioseo_title['title'] ?? '';
        } elseif (is_string($aioseo_title)) {
            $meta_title = $aioseo_title;
        }
        if (empty($meta_title)) {
            $meta_title = get_post_meta($id, '_aioseop_title', true);
        }

        $aioseo_desc = get_post_meta($id, '_aioseo_description', true);
        if (is_array($aioseo_desc)) {
            $meta_desc = $aioseo_desc['description'] ?? '';
        } elseif (is_string($aioseo_desc)) {
            $meta_desc = $aioseo_desc;
        }
        if (empty($meta_desc)) {
            $meta_desc = get_post_meta($id, '_aioseop_description', true);
        }

        $row = [];
        if (!empty($selected_lookup['id'])) {
            $row[] = $id;
        }
        if (!empty($selected_lookup['title'])) {
            $row[] = $p->post_title;
        }
        if (!empty($selected_lookup['slug'])) {
            $row[] = $p->post_name;
        }
        if (!empty($selected_lookup['excerpt'])) {
            $row[] = $short_desc;
        }
        if (!empty($selected_lookup['content'])) {
            $row[] = $long_desc;
        }
        if (!empty($selected_lookup['meta_title'])) {
            $row[] = $meta_title;
        }
        if (!empty($selected_lookup['meta_description'])) {
            $row[] = $meta_desc;
        }

        $custom_values = [];
        foreach ($selected_custom_fields as $field) {
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

        $segments = spe_build_custom_export_row_segments($selected_custom_fields, $attachment_field_map, $custom_values);
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

    $context = spe_build_post_import_context($header, 'product');
    $map = $context['map'];
    $id_col = $context['indexes']['id'];
    $title_col = $context['indexes']['title'];
    $slug_col = $context['indexes']['slug'];
    $short_desc_col = $context['indexes']['excerpt'];
    $long_desc_col = $context['indexes']['content'];
    $meta_title_col = $context['indexes']['meta_title'];
    $meta_desc_col = $context['indexes']['meta_description'];
    $custom_cols = $context['custom_cols'];
    $column_filter = spe_resolve_import_column_filter_from_request($header, ['spe_import_products_columns']);
    if (!empty($column_filter['enabled']) && empty($column_filter['indexes'])) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：你设置了“仅导入这些列名”，但没有任何列名与 CSV 表头匹配。'];
    }

    $match_profile = spe_get_post_import_match_profile('product');
    $selected_profile = spe_resolve_import_match_profile_selection('product', ['spe_import_products_profile_id']);
    if (!empty($selected_profile)) {
        $match_profile = array_merge($match_profile, $selected_profile);
    }
    $match_profile = spe_resolve_allow_insert_override($match_profile, ['spe_import_products_allow_insert']);
    $match_profile = spe_resolve_unique_meta_override(
        $match_profile,
        ['spe_import_products_unique_meta_field'],
        ['spe_import_products_unique_meta_key']
    );
    $unique_meta_field = trim((string) ($match_profile['unique_meta_field'] ?? ''));
    $has_unique_mapping = $unique_meta_field !== ''
        && (array_key_exists($unique_meta_field, $map) || array_key_exists('field:' . $unique_meta_field, $map));

    if ($id_col === false && $slug_col === false && !$has_unique_mapping) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：缺少可匹配列。至少需要 ID/Slug，或配置并映射唯一键字段。'];
    }

    $updated = 0;
    $inserted = 0;
    $not_found = 0;
    $insert_failed = 0;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $match = spe_resolve_post_import_match($row, $map, 'product', $match_profile);
        $match_action = (string) ($match['action'] ?? 'skip');
        $product_id = 0;

        if ($match_action === 'insert') {
            $created = spe_create_post_from_import_row('product', $row, $context);
            if (!empty($created['error']) || empty($created['id'])) {
                $insert_failed++;
                continue;
            }
            $product_id = intval($created['id']);
            $inserted++;
        } elseif ($match_action === 'update' && !empty($match['matched_id'])) {
            $product_id = intval($match['matched_id']);
        } else {
            $not_found++;
            continue;
        }

        if ($match_action === 'update' && function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if (!$product) {
                $not_found++;
                continue;
            }
        } elseif ($match_action === 'update') {
            $product = function_exists('get_post') ? get_post($product_id) : null;
            if (!$product || is_wp_error($product) || (string) $product->post_type !== 'product') {
                $not_found++;
                continue;
            }
        }

        // 更新基础字段
        $update_data = [];
        $title_value = spe_get_row_value_by_index($row, $title_col);
        if (spe_import_column_allowed($title_col, $column_filter) && $title_value !== '') {
            $update_data['post_title'] = $title_value;
        }
        $slug_value = spe_get_row_value_by_index($row, $slug_col);
        if (spe_import_column_allowed($slug_col, $column_filter) && $slug_value !== '') {
            $update_data['post_name'] = sanitize_title($slug_value);
        }
        $short_desc_value = spe_get_row_value_by_index($row, $short_desc_col);
        if (spe_import_column_allowed($short_desc_col, $column_filter) && $short_desc_value !== '') {
            $update_data['post_excerpt'] = $short_desc_value;
        }
        $long_desc_value = spe_get_row_value_by_index($row, $long_desc_col);
        if (spe_import_column_allowed($long_desc_col, $column_filter) && $long_desc_value !== '') {
            $update_data['post_content'] = $long_desc_value;
        }

        if (!empty($update_data)) {
            $update_data['ID'] = $product_id;
            wp_update_post($update_data);
        }

        $meta_title_value = null;
        if (spe_import_column_allowed($meta_title_col, $column_filter)) {
            $meta_title_raw = spe_get_row_value_by_index($row, $meta_title_col);
            if ($meta_title_raw !== '') {
                $meta_title_value = $meta_title_raw;
            }
        }
        $meta_desc_value = null;
        if (spe_import_column_allowed($meta_desc_col, $column_filter)) {
            $meta_desc_raw = spe_get_row_value_by_index($row, $meta_desc_col);
            if ($meta_desc_raw !== '') {
                $meta_desc_value = $meta_desc_raw;
            }
        }
        if ($meta_title_value !== null || $meta_desc_value !== null) {
            spe_sync_post_seo_meta_by_active_provider($product_id, $meta_title_value, $meta_desc_value);
        }

        // 自定义字段
        foreach ($custom_cols as $idx => $field_name) {
            if (!spe_import_column_allowed($idx, $column_filter)) {
                continue;
            }
            $value = spe_get_row_value_by_index($row, $idx);
            if ($value !== '') {
                spe_update_post_custom_field($product_id, $field_name, $value);
            }
        }

        $updated++;
    }

    fclose($handle);

    $msg = "产品导入完成！更新了 {$updated} 个产品";
    if ($inserted > 0) {
        $msg .= "，新增 {$inserted} 个产品";
    }
    if ($not_found > 0) {
        $msg .= "，{$not_found} 行未匹配到产品";
    }
    if ($insert_failed > 0) {
        $msg .= "，{$insert_failed} 行新增失败";
    }
    if (!empty($column_filter['enabled']) && !empty($column_filter['missing'])) {
        $msg .= "（忽略未命中列名: " . implode(', ', array_slice($column_filter['missing'], 0, 3));
        if (count($column_filter['missing']) > 3) {
            $msg .= ' 等';
        }
        $msg .= '）';
    }
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
    $context = spe_build_taxonomy_import_context($header, $taxonomy);
    $map = $context['map'];
    $id_col = $context['indexes']['id'];
    $name_col = $context['indexes']['name'];
    $slug_col = $context['indexes']['slug'];
    $desc_col = $context['indexes']['description'];
    $parent_col = $context['indexes']['parent'];
    $meta_title_col = $context['indexes']['meta_title'];
    $meta_desc_col = $context['indexes']['meta_description'];
    $custom_cols = $context['custom_cols'];
    $column_filter = spe_resolve_import_column_filter_from_request($header, ['spe_import_taxonomies_columns']);
    if (!empty($column_filter['enabled']) && empty($column_filter['indexes'])) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：你设置了“仅导入这些列名”，但没有任何列名与 CSV 表头匹配。'];
    }

    $match_profile = spe_get_taxonomy_import_match_profile($taxonomy);
    $selected_profile = spe_resolve_import_match_profile_selection('taxonomy', ['spe_import_taxonomies_profile_id']);
    if (!empty($selected_profile)) {
        $match_profile = array_merge($match_profile, $selected_profile);
    }
    $match_profile = spe_resolve_allow_insert_override($match_profile, ['spe_import_taxonomies_allow_insert']);
    $match_profile = spe_resolve_unique_meta_override(
        $match_profile,
        ['spe_import_taxonomies_unique_meta_field'],
        ['spe_import_taxonomies_unique_meta_key']
    );
    $unique_meta_field = trim((string) ($match_profile['unique_meta_field'] ?? ''));
    $has_unique_mapping = $unique_meta_field !== ''
        && (array_key_exists($unique_meta_field, $map) || array_key_exists('field:' . $unique_meta_field, $map));

    if ($id_col === false && $slug_col === false && !$has_unique_mapping) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：缺少可匹配列。至少需要 ID/Slug，或配置并映射唯一键字段。'];
    }

    $active_seo_provider = spe_get_active_seo_provider();
    $updated = 0;
    $inserted = 0;
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

        $cat_id_raw = ($id_col !== false) ? trim((string) spe_get_row_value_by_index($row, $id_col)) : '';
        if ($cat_id_raw !== '') {
            $cat_id_numeric = intval(preg_replace('/[^0-9]/', '', $cat_id_raw));
            if ($cat_id_numeric <= 0) {
                $invalid_id++;
                $append_debug("第 {$row_count} 行: ID 格式无效，已尝试其他匹配策略");
            }
        }

        $match = spe_resolve_taxonomy_import_match($row, $map, $taxonomy, $match_profile);
        $match_action = (string) ($match['action'] ?? 'skip');
        $cat_id = isset($match['matched_id']) && $match['matched_id'] !== null ? intval($match['matched_id']) : 0;

        if ($match_action === 'error') {
            $error_msg = "第 {$row_count} 行: 匹配冲突 - " . (string) ($match['error'] ?? '未知错误');
            $errors[] = $error_msg;
            $append_debug($error_msg);
            continue;
        }

        $row_created = false;
        if ($match_action === 'insert') {
            $created = spe_create_taxonomy_term_from_import_row($taxonomy, $row, $context);
            if (!empty($created['error']) || empty($created['id'])) {
                $error_msg = "第 {$row_count} 行: 新增分类失败 - " . (!empty($created['error']) ? (string) $created['error'] : '未知错误');
                $errors[] = $error_msg;
                $append_debug($error_msg);
                continue;
            }
            $cat_id = intval($created['id']);
            $row_created = true;
            $inserted++;
            $append_debug("第 {$row_count} 行: 已新增分类 ID {$cat_id}");
        }

        if (($match_action !== 'update' && !$row_created) || $cat_id <= 0) {
            $not_found++;
            $append_debug("第 {$row_count} 行: 未匹配到分类（策略: " . (string) ($match['strategy'] ?? 'none') . "）");
            continue;
        }

        $cat = get_term($cat_id, $taxonomy);
        if (!$cat || is_wp_error($cat)) {
            $not_found++;
            $append_debug("第 {$row_count} 行: ID {$cat_id} 不属于 taxonomy {$taxonomy}，已跳过");
            continue;
        }

        $processed++;
        $row_modified = $row_created;
        $update_data = [];
        $row_slug_changed = false;

        $name_value = spe_get_row_value_by_index($row, $name_col);
        if (spe_import_column_allowed($name_col, $column_filter) && $name_value !== '') {
            $new_name = $name_value;
            if ((string) $cat->name !== (string) $new_name) {
                $update_data['name'] = $new_name;
            }
        }

        $slug_value = spe_get_row_value_by_index($row, $slug_col);
        if (spe_import_column_allowed($slug_col, $column_filter) && trim((string) $slug_value) !== '') {
            $old_slug = (string) $cat->slug;
            $new_slug_raw = (string) $slug_value;

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

        $desc_value = spe_get_row_value_by_index($row, $desc_col);
        if (spe_import_column_allowed($desc_col, $column_filter) && $desc_value !== '') {
            $new_desc = $desc_value;
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

        $parent_raw = trim((string) spe_get_row_value_by_index($row, $parent_col));
        if (spe_import_column_allowed($parent_col, $column_filter) && $parent_raw !== '') {
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

        $meta_title_value = null;
        if (spe_import_column_allowed($meta_title_col, $column_filter)) {
            $meta_title_raw = spe_get_row_value_by_index($row, $meta_title_col);
            if ($meta_title_raw !== '') {
                $meta_title_value = $meta_title_raw;
            }
        }
        $meta_desc_value = null;
        if (spe_import_column_allowed($meta_desc_col, $column_filter)) {
            $meta_desc_raw = spe_get_row_value_by_index($row, $meta_desc_col);
            if ($meta_desc_raw !== '') {
                $meta_desc_value = $meta_desc_raw;
            }
        }

        if ($meta_title_value !== null || $meta_desc_value !== null) {
            $sync_result = spe_sync_term_seo_meta_by_active_provider($cat_id, $taxonomy, $meta_title_value, $meta_desc_value);
            if (($sync_result['provider'] ?? '') === '') {
                $append_debug("第 {$row_count} 行: ID {$cat_id} 未检测到激活 SEO 插件，SEO 字段已跳过");
            } else {
                $row_modified = true;
            }
        }

        foreach ($custom_cols as $idx => $field_name) {
            if (!spe_import_column_allowed($idx, $column_filter)) {
                continue;
            }
            $value = spe_get_row_value_by_index($row, $idx);
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

    $debug_lines[] = "汇总: 数据行 {$row_count}，匹配成功 {$processed}，更新 {$updated}，新增 {$inserted}，无变化 {$no_changes}，无效ID {$invalid_id}，未匹配 {$not_found}，错误 " . count($errors);
    $debug_log = implode("\n", $debug_lines);

    $msg = "分类导入完成！共处理 {$processed} 个分类";
    if ($updated > 0) {
        $msg .= "（{$updated} 个有更新）";
    }
    if ($inserted > 0) {
        $msg .= "，新增 {$inserted} 个分类";
    }
    if ($no_changes > 0) {
        $msg .= "，{$no_changes} 个无变化";
    }
    if ($invalid_id > 0) {
        $msg .= "，{$invalid_id} 行 ID 无效";
    }
    if ($not_found > 0) {
        $msg .= "，{$not_found} 行未匹配到分类";
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
    if (!empty($column_filter['enabled']) && !empty($column_filter['missing'])) {
        $msg .= "（忽略未命中列名: " . implode(', ', array_slice($column_filter['missing'], 0, 3));
        if (count($column_filter['missing']) > 3) {
            $msg .= ' 等';
        }
        $msg .= '）';
    }

    return ['error' => false, 'message' => $msg, 'debug' => $debug_log];
}

/**
 * 导出页面
 */
function spe_export_pages($selected_fields = [])
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
    if (!is_array($all_meta_keys)) {
        $all_meta_keys = [];
    }

    $custom_fields = spe_resolve_post_export_custom_fields(
        'page',
        $all_meta_keys,
        spe_get_post_type_export_exclude_keys('page')
    );

    $base_fields = spe_get_post_type_base_export_fields('page');
    $field_options = spe_get_post_type_export_field_options('page', $custom_fields);
    $selected = spe_resolve_export_selected_fields_from_options($field_options, $selected_fields, ['id']);
    $selected_lookup = array_fill_keys($selected, true);

    $selected_custom_fields = [];
    foreach ($selected as $selected_key) {
        if (strpos($selected_key, 'field:') !== 0) {
            continue;
        }
        $field_name = substr($selected_key, 6);
        if ($field_name !== '' && in_array($field_name, $custom_fields, true)) {
            $selected_custom_fields[] = $field_name;
        }
    }

    if (empty($pages)) {
        $empty_header = [];
        foreach ($selected as $selected_key) {
            if (isset($base_fields[$selected_key])) {
                $empty_header[] = $base_fields[$selected_key]['header'];
                continue;
            }
            if (strpos($selected_key, 'field:') === 0) {
                $empty_header[] = substr($selected_key, 6);
            }
        }
        if (empty($empty_header)) {
            $empty_header = ['ID', '标题', 'Slug', '摘要', '内容'];
        }
        fputcsv($output, $empty_header);
        fclose($output);
        exit;
    }

    $header = [];
    foreach ($base_fields as $base_key => $meta) {
        if (!empty($selected_lookup[$base_key])) {
            $header[] = $meta['header'];
        }
    }
    foreach ($selected_custom_fields as $field) {
        $header[] = $field;
    }

    $attachment_fields = [];
    foreach ($pages as $p) {
        foreach ($selected_custom_fields as $field) {
            if (in_array($field, $attachment_fields, true)) {
                continue;
            }
            $value = get_post_meta($p->ID, $field, true);
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

    fputcsv($output, $header);

    foreach ($pages as $p) {
        $id = $p->ID;
        $excerpt = str_replace(["\r\n", "\n", "\r"], ' ', (string) ($p->post_excerpt ?: ''));
        $content = str_replace(["\r\n", "\n", "\r"], ' ', (string) ($p->post_content ?: ''));

        $meta_title = '';
        $meta_desc = '';
        $aioseo_title = get_post_meta($id, '_aioseo_title', true);
        if (is_array($aioseo_title)) {
            $meta_title = $aioseo_title['title'] ?? '';
        } elseif (is_string($aioseo_title)) {
            $meta_title = $aioseo_title;
        }
        if (empty($meta_title)) {
            $meta_title = get_post_meta($id, '_aioseop_title', true);
        }

        $aioseo_desc = get_post_meta($id, '_aioseo_description', true);
        if (is_array($aioseo_desc)) {
            $meta_desc = $aioseo_desc['description'] ?? '';
        } elseif (is_string($aioseo_desc)) {
            $meta_desc = $aioseo_desc;
        }
        if (empty($meta_desc)) {
            $meta_desc = get_post_meta($id, '_aioseop_description', true);
        }

        $row = [];
        if (!empty($selected_lookup['id'])) {
            $row[] = $id;
        }
        if (!empty($selected_lookup['title'])) {
            $row[] = $p->post_title;
        }
        if (!empty($selected_lookup['slug'])) {
            $row[] = $p->post_name;
        }
        if (!empty($selected_lookup['excerpt'])) {
            $row[] = $excerpt;
        }
        if (!empty($selected_lookup['content'])) {
            $row[] = $content;
        }
        if (!empty($selected_lookup['meta_title'])) {
            $row[] = $meta_title;
        }
        if (!empty($selected_lookup['meta_description'])) {
            $row[] = $meta_desc;
        }

        $custom_values = [];
        foreach ($selected_custom_fields as $field) {
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

        $segments = spe_build_custom_export_row_segments($selected_custom_fields, $attachment_field_map, $custom_values);
        $row = array_merge($row, $segments['values'], $segments['urls']);
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/**
 * 导出文章
 */
function spe_export_posts($selected_fields = [])
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

    // 扫描当前导出范围内全部文章，避免遗漏字段
    $all_meta_keys = $wpdb->get_col("
        SELECT DISTINCT pm.meta_key
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE {$where_clause}
        AND pm.meta_key NOT LIKE '\_%'
        ORDER BY pm.meta_key
    ");
    if (!is_array($all_meta_keys)) {
        $all_meta_keys = [];
    }

    $custom_fields = spe_resolve_post_export_custom_fields(
        'post',
        $all_meta_keys,
        spe_get_post_type_export_exclude_keys('post')
    );

    $base_fields = spe_get_post_type_base_export_fields('post');
    $field_options = spe_get_post_type_export_field_options('post', $custom_fields);
    $selected = spe_resolve_export_selected_fields_from_options($field_options, $selected_fields, ['id']);
    $selected_lookup = array_fill_keys($selected, true);

    $selected_custom_fields = [];
    foreach ($selected as $selected_key) {
        if (strpos($selected_key, 'field:') !== 0) {
            continue;
        }
        $field_name = substr($selected_key, 6);
        if ($field_name !== '' && in_array($field_name, $custom_fields, true)) {
            $selected_custom_fields[] = $field_name;
        }
    }

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
            $empty_header = [];
            foreach ($selected as $selected_key) {
                if (isset($base_fields[$selected_key])) {
                    $empty_header[] = $base_fields[$selected_key]['header'];
                    continue;
                }
                if (strpos($selected_key, 'field:') === 0) {
                    $empty_header[] = substr($selected_key, 6);
                }
            }
            if (empty($empty_header)) {
                $empty_header = ['ID', '标题', 'Slug', '摘要', '内容'];
            }
            fputcsv($output, $empty_header);
        }
        fclose($output);
        exit;
    }

    $header = [];
    foreach ($base_fields as $base_key => $meta) {
        if (!empty($selected_lookup[$base_key])) {
            $header[] = $meta['header'];
        }
    }
    foreach ($selected_custom_fields as $field) {
        $header[] = $field;
    }

    $attachment_fields = [];
    foreach ($posts as $p) {
        foreach ($selected_custom_fields as $field) {
            if (in_array($field, $attachment_fields, true)) {
                continue;
            }
            $value = get_post_meta($p->ID, $field, true);
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

    fputcsv($output, $header);

    foreach ($posts as $p) {
        $id = $p->ID;
        $excerpt = str_replace(["\r\n", "\n", "\r"], ' ', (string) ($p->post_excerpt ?: ''));
        $content = str_replace(["\r\n", "\n", "\r"], ' ', (string) ($p->post_content ?: ''));

        $meta_title = '';
        $meta_desc = '';
        $aioseo_title = get_post_meta($id, '_aioseo_title', true);
        if (is_array($aioseo_title)) {
            $meta_title = $aioseo_title['title'] ?? '';
        } elseif (is_string($aioseo_title)) {
            $meta_title = $aioseo_title;
        }
        if (empty($meta_title)) {
            $meta_title = get_post_meta($id, '_aioseop_title', true);
        }

        $aioseo_desc = get_post_meta($id, '_aioseo_description', true);
        if (is_array($aioseo_desc)) {
            $meta_desc = $aioseo_desc['description'] ?? '';
        } elseif (is_string($aioseo_desc)) {
            $meta_desc = $aioseo_desc;
        }
        if (empty($meta_desc)) {
            $meta_desc = get_post_meta($id, '_aioseop_description', true);
        }

        $row = [];
        if (!empty($selected_lookup['id'])) {
            $row[] = $id;
        }
        if (!empty($selected_lookup['title'])) {
            $row[] = $p->post_title;
        }
        if (!empty($selected_lookup['slug'])) {
            $row[] = $p->post_name;
        }
        if (!empty($selected_lookup['excerpt'])) {
            $row[] = $excerpt;
        }
        if (!empty($selected_lookup['content'])) {
            $row[] = $content;
        }
        if (!empty($selected_lookup['meta_title'])) {
            $row[] = $meta_title;
        }
        if (!empty($selected_lookup['meta_description'])) {
            $row[] = $meta_desc;
        }

        $custom_values = [];
        foreach ($selected_custom_fields as $field) {
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

        $segments = spe_build_custom_export_row_segments($selected_custom_fields, $attachment_field_map, $custom_values);
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

    $context = spe_build_post_import_context($header, 'page');
    $map = $context['map'];
    $id_col = $context['indexes']['id'];
    $title_col = $context['indexes']['title'];
    $slug_col = $context['indexes']['slug'];
    $excerpt_col = $context['indexes']['excerpt'];
    $content_col = $context['indexes']['content'];
    $meta_title_col = $context['indexes']['meta_title'];
    $meta_desc_col = $context['indexes']['meta_description'];
    $custom_cols = $context['custom_cols'];
    $column_filter = spe_resolve_import_column_filter_from_request($header, ['spe_import_pages_columns']);
    if (!empty($column_filter['enabled']) && empty($column_filter['indexes'])) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：你设置了“仅导入这些列名”，但没有任何列名与 CSV 表头匹配。'];
    }

    $match_profile = spe_get_post_import_match_profile('page');
    $selected_profile = spe_resolve_import_match_profile_selection('page', ['spe_import_pages_profile_id']);
    if (!empty($selected_profile)) {
        $match_profile = array_merge($match_profile, $selected_profile);
    }
    $match_profile = spe_resolve_allow_insert_override($match_profile, ['spe_import_pages_allow_insert']);
    $match_profile = spe_resolve_unique_meta_override(
        $match_profile,
        ['spe_import_pages_unique_meta_field'],
        ['spe_import_pages_unique_meta_key']
    );
    $unique_meta_field = trim((string) ($match_profile['unique_meta_field'] ?? ''));
    $has_unique_mapping = $unique_meta_field !== ''
        && (array_key_exists($unique_meta_field, $map) || array_key_exists('field:' . $unique_meta_field, $map));

    if ($id_col === false && $slug_col === false && !$has_unique_mapping) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：缺少可匹配列。至少需要 ID/Slug，或配置并映射唯一键字段。'];
    }

    $updated = 0;
    $inserted = 0;
    $not_found = 0;
    $insert_failed = 0;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $match = spe_resolve_post_import_match($row, $map, 'page', $match_profile);
        $match_action = (string) ($match['action'] ?? 'skip');
        $page_id = 0;

        if ($match_action === 'insert') {
            $created = spe_create_post_from_import_row('page', $row, $context);
            if (!empty($created['error']) || empty($created['id'])) {
                $insert_failed++;
                continue;
            }
            $page_id = intval($created['id']);
            $inserted++;
        } elseif ($match_action === 'update' && !empty($match['matched_id'])) {
            $page_id = intval($match['matched_id']);
        } else {
            $not_found++;
            continue;
        }

        // 更新基础字段
        $update_data = [];
        $title_value = spe_get_row_value_by_index($row, $title_col);
        if (spe_import_column_allowed($title_col, $column_filter) && $title_value !== '') {
            $update_data['post_title'] = $title_value;
        }
        $slug_value = spe_get_row_value_by_index($row, $slug_col);
        if (spe_import_column_allowed($slug_col, $column_filter) && $slug_value !== '') {
            $update_data['post_name'] = sanitize_title($slug_value);
        }
        $excerpt_value = spe_get_row_value_by_index($row, $excerpt_col);
        if (spe_import_column_allowed($excerpt_col, $column_filter) && $excerpt_value !== '') {
            $update_data['post_excerpt'] = $excerpt_value;
        }
        $content_value = spe_get_row_value_by_index($row, $content_col);
        if (spe_import_column_allowed($content_col, $column_filter) && $content_value !== '') {
            $update_data['post_content'] = $content_value;
        }

        if (!empty($update_data)) {
            $update_data['ID'] = $page_id;
            wp_update_post($update_data);
        }

        $meta_title_value = null;
        if (spe_import_column_allowed($meta_title_col, $column_filter)) {
            $meta_title_raw = spe_get_row_value_by_index($row, $meta_title_col);
            if ($meta_title_raw !== '') {
                $meta_title_value = $meta_title_raw;
            }
        }
        $meta_desc_value = null;
        if (spe_import_column_allowed($meta_desc_col, $column_filter)) {
            $meta_desc_raw = spe_get_row_value_by_index($row, $meta_desc_col);
            if ($meta_desc_raw !== '') {
                $meta_desc_value = $meta_desc_raw;
            }
        }
        if ($meta_title_value !== null || $meta_desc_value !== null) {
            spe_sync_post_seo_meta_by_active_provider($page_id, $meta_title_value, $meta_desc_value);
        }

        // 自定义字段
        foreach ($custom_cols as $idx => $field_name) {
            if (!spe_import_column_allowed($idx, $column_filter)) {
                continue;
            }
            $value = spe_get_row_value_by_index($row, $idx);
            if ($value !== '') {
                spe_update_post_custom_field($page_id, $field_name, $value);
            }
        }

        $updated++;
    }

    fclose($handle);

    $msg = "页面导入完成！更新了 {$updated} 个页面";
    if ($inserted > 0) {
        $msg .= "，新增 {$inserted} 个页面";
    }
    if ($not_found > 0) {
        $msg .= "，{$not_found} 行未匹配到页面";
    }
    if ($insert_failed > 0) {
        $msg .= "，{$insert_failed} 行新增失败";
    }
    if (!empty($column_filter['enabled']) && !empty($column_filter['missing'])) {
        $msg .= "（忽略未命中列名: " . implode(', ', array_slice($column_filter['missing'], 0, 3));
        if (count($column_filter['missing']) > 3) {
            $msg .= ' 等';
        }
        $msg .= '）';
    }
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

    $context = spe_build_post_import_context($header, 'post');
    $map = $context['map'];
    $id_col = $context['indexes']['id'];
    $title_col = $context['indexes']['title'];
    $slug_col = $context['indexes']['slug'];
    $excerpt_col = $context['indexes']['excerpt'];
    $content_col = $context['indexes']['content'];
    $meta_title_col = $context['indexes']['meta_title'];
    $meta_desc_col = $context['indexes']['meta_description'];
    $custom_cols = $context['custom_cols'];
    $column_filter = spe_resolve_import_column_filter_from_request($header, ['spe_import_posts_columns']);
    if (!empty($column_filter['enabled']) && empty($column_filter['indexes'])) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：你设置了“仅导入这些列名”，但没有任何列名与 CSV 表头匹配。'];
    }

    $match_profile = spe_get_post_import_match_profile('post');
    $selected_profile = spe_resolve_import_match_profile_selection('post', ['spe_import_posts_profile_id']);
    if (!empty($selected_profile)) {
        $match_profile = array_merge($match_profile, $selected_profile);
    }
    $match_profile = spe_resolve_allow_insert_override($match_profile, ['spe_import_posts_allow_insert']);
    $match_profile = spe_resolve_unique_meta_override(
        $match_profile,
        ['spe_import_posts_unique_meta_field'],
        ['spe_import_posts_unique_meta_key']
    );
    $unique_meta_field = trim((string) ($match_profile['unique_meta_field'] ?? ''));
    $has_unique_mapping = $unique_meta_field !== ''
        && (array_key_exists($unique_meta_field, $map) || array_key_exists('field:' . $unique_meta_field, $map));

    if ($id_col === false && $slug_col === false && !$has_unique_mapping) {
        fclose($handle);
        return ['error' => true, 'message' => '导入失败：缺少可匹配列。至少需要 ID/Slug，或配置并映射唯一键字段。'];
    }

    $updated = 0;
    $inserted = 0;
    $not_found = 0;
    $insert_failed = 0;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $match = spe_resolve_post_import_match($row, $map, 'post', $match_profile);
        $match_action = (string) ($match['action'] ?? 'skip');
        $post_id = 0;

        if ($match_action === 'insert') {
            $created = spe_create_post_from_import_row('post', $row, $context);
            if (!empty($created['error']) || empty($created['id'])) {
                $insert_failed++;
                continue;
            }
            $post_id = intval($created['id']);
            $inserted++;
        } elseif ($match_action === 'update' && !empty($match['matched_id'])) {
            $post_id = intval($match['matched_id']);
        } else {
            $not_found++;
            continue;
        }

        // 更新基础字段
        $update_data = [];
        $title_value = spe_get_row_value_by_index($row, $title_col);
        if (spe_import_column_allowed($title_col, $column_filter) && $title_value !== '') {
            $update_data['post_title'] = $title_value;
        }
        $slug_value = spe_get_row_value_by_index($row, $slug_col);
        if (spe_import_column_allowed($slug_col, $column_filter) && $slug_value !== '') {
            $update_data['post_name'] = sanitize_title($slug_value);
        }
        $excerpt_value = spe_get_row_value_by_index($row, $excerpt_col);
        if (spe_import_column_allowed($excerpt_col, $column_filter) && $excerpt_value !== '') {
            $update_data['post_excerpt'] = $excerpt_value;
        }
        $content_value = spe_get_row_value_by_index($row, $content_col);
        if (spe_import_column_allowed($content_col, $column_filter) && $content_value !== '') {
            $update_data['post_content'] = $content_value;
        }

        if (!empty($update_data)) {
            $update_data['ID'] = $post_id;
            wp_update_post($update_data);
        }

        $meta_title_value = null;
        if (spe_import_column_allowed($meta_title_col, $column_filter)) {
            $meta_title_raw = spe_get_row_value_by_index($row, $meta_title_col);
            if ($meta_title_raw !== '') {
                $meta_title_value = $meta_title_raw;
            }
        }
        $meta_desc_value = null;
        if (spe_import_column_allowed($meta_desc_col, $column_filter)) {
            $meta_desc_raw = spe_get_row_value_by_index($row, $meta_desc_col);
            if ($meta_desc_raw !== '') {
                $meta_desc_value = $meta_desc_raw;
            }
        }
        if ($meta_title_value !== null || $meta_desc_value !== null) {
            spe_sync_post_seo_meta_by_active_provider($post_id, $meta_title_value, $meta_desc_value);
        }

        // 自定义字段
        foreach ($custom_cols as $idx => $field_name) {
            if (!spe_import_column_allowed($idx, $column_filter)) {
                continue;
            }
            $value = spe_get_row_value_by_index($row, $idx);
            if ($value !== '') {
                spe_update_post_custom_field($post_id, $field_name, $value);
            }
        }

        $updated++;
    }

    fclose($handle);

    $msg = "文章导入完成！更新了 {$updated} 个文章";
    if ($inserted > 0) {
        $msg .= "，新增 {$inserted} 个文章";
    }
    if ($not_found > 0) {
        $msg .= "，{$not_found} 行未匹配到文章";
    }
    if ($insert_failed > 0) {
        $msg .= "，{$insert_failed} 行新增失败";
    }
    if (!empty($column_filter['enabled']) && !empty($column_filter['missing'])) {
        $msg .= "（忽略未命中列名: " . implode(', ', array_slice($column_filter['missing'], 0, 3));
        if (count($column_filter['missing']) > 3) {
            $msg .= ' 等';
        }
        $msg .= '）';
    }
    return ['error' => false, 'message' => $msg, 'debug' => ''];
}
