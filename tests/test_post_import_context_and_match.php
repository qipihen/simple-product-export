<?php
/**
 * M1 - Post import context + match integration tests.
 *
 * Run:
 *   php tests/test_post_import_context_and_match.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

if (!function_exists('add_action')) {
    function add_action(...$args) {}
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(...$args) {}
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value)
    {
        return trim((string) $value);
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($value)
    {
        $value = strtolower(trim((string) $value));
        return preg_replace('/[^a-z0-9\-]+/', '-', $value);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value)
    {
        return $value;
    }
}

if (!function_exists('acf_get_field_groups')) {
    function acf_get_field_groups()
    {
        return [
            [
                'key' => 'group_post_page',
                'location' => [
                    [
                        [
                            'param' => 'post_type',
                            'operator' => '==',
                            'value' => 'page',
                        ],
                    ],
                ],
            ],
        ];
    }
}

if (!function_exists('acf_get_fields')) {
    function acf_get_fields($group_key)
    {
        if ($group_key === 'group_post_page') {
            return [
                ['name' => 'acf_page_banner'],
            ];
        }
        return [];
    }
}

require_once __DIR__ . '/../simple-product-export.php';

function assert_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$header = ['ID', 'Title', 'Slug', 'Meta Description', 'banner_image', 'banner_image_url'];
$context = spe_build_post_import_context($header, 'page');

assert_true(($context['indexes']['id'] ?? null) === 0, 'Import context should map ID column.');
assert_true(($context['indexes']['title'] ?? null) === 1, 'Import context should map title column.');
assert_true(($context['indexes']['slug'] ?? null) === 2, 'Import context should map slug column.');
assert_true(($context['indexes']['meta_description'] ?? null) === 3, 'Import context should map meta description column.');
assert_true(($context['indexes']['meta_title'] ?? false) === false, 'Import context should not force-map missing meta title column.');
assert_true(isset($context['custom_cols'][4]) && $context['custom_cols'][4] === 'banner_image', 'Custom column should keep non-helper field.');
assert_true(!isset($context['custom_cols'][5]), 'Custom column list should skip helper *_url field.');

$map = [
    'id' => 0,
    'slug' => 1,
    'field:sku_code' => 2,
];

$row_by_id = ['100', 'ignored-slug', 'SKU-1'];
$result_by_id = spe_resolve_post_import_match(
    $row_by_id,
    $map,
    'post',
    ['unique_meta_field' => 'sku_code', 'unique_meta_key' => 'sku_code'],
    [
        'find_by_id' => function ($id) {
            return intval($id) === 100 ? 100 : null;
        },
        'find_by_slug' => function ($slug) {
            return null;
        },
        'find_by_meta' => function ($meta_key, $value) {
            return null;
        },
    ]
);
assert_true(($result_by_id['action'] ?? '') === 'update', 'Match should update when ID is resolved.');
assert_true(($result_by_id['strategy'] ?? '') === 'id', 'Match strategy should prioritize id.');
assert_true(($result_by_id['matched_id'] ?? null) === 100, 'Matched ID should be returned for ID strategy.');

$row_by_slug = ['', 'portable-ev', 'SKU-2'];
$result_by_slug = spe_resolve_post_import_match(
    $row_by_slug,
    $map,
    'post',
    ['unique_meta_field' => 'sku_code', 'unique_meta_key' => 'sku_code'],
    [
        'find_by_id' => function ($id) {
            return null;
        },
        'find_by_slug' => function ($slug) {
            return $slug === 'portable-ev' ? 201 : null;
        },
        'find_by_meta' => function ($meta_key, $value) {
            return null;
        },
    ]
);
assert_true(($result_by_slug['action'] ?? '') === 'update', 'Match should update when slug is resolved.');
assert_true(($result_by_slug['strategy'] ?? '') === 'slug', 'Match strategy should fall back to slug.');
assert_true(($result_by_slug['matched_id'] ?? null) === 201, 'Matched ID should be returned for slug strategy.');

$row_by_meta = ['', '', 'SKU-3'];
$result_by_meta = spe_resolve_post_import_match(
    $row_by_meta,
    $map,
    'post',
    ['unique_meta_field' => 'sku_code', 'unique_meta_key' => 'sku_code'],
    [
        'find_by_id' => function ($id) {
            return null;
        },
        'find_by_slug' => function ($slug) {
            return null;
        },
        'find_by_meta' => function ($meta_key, $value) {
            if ($meta_key === 'sku_code' && $value === 'SKU-3') {
                return 301;
            }
            return null;
        },
    ]
);
assert_true(($result_by_meta['action'] ?? '') === 'update', 'Match should update when unique meta is resolved.');
assert_true(($result_by_meta['strategy'] ?? '') === 'unique_meta', 'Match strategy should use unique_meta fallback.');
assert_true(($result_by_meta['matched_id'] ?? null) === 301, 'Matched ID should be returned for unique_meta strategy.');

$row_missing = ['', '', ''];
$result_missing = spe_resolve_post_import_match(
    $row_missing,
    $map,
    'post',
    ['unique_meta_field' => 'sku_code', 'unique_meta_key' => 'sku_code', 'allow_insert' => false],
    [
        'find_by_id' => function ($id) {
            return null;
        },
        'find_by_slug' => function ($slug) {
            return null;
        },
        'find_by_meta' => function ($meta_key, $value) {
            return null;
        },
    ]
);
assert_true(($result_missing['action'] ?? '') === 'skip', 'Unmatched row should be skip when insert is disabled.');

fwrite(STDOUT, "PASS: test_post_import_context_and_match\n");
