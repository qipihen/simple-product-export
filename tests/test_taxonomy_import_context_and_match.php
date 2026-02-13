<?php
/**
 * M1 - Taxonomy import context + match integration tests.
 *
 * Run:
 *   php tests/test_taxonomy_import_context_and_match.php
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
                'key' => 'group_tax_product_cat',
                'location' => [
                    [
                        [
                            'param' => 'taxonomy',
                            'operator' => '==',
                            'value' => 'product_cat',
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
        if ($group_key === 'group_tax_product_cat') {
            return [
                ['name' => 'acf_tax_badge'],
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

$header = ['term_id', 'Name', 'Slug', 'Meta Description', 'legacy_code', 'legacy_code_url'];
$context = spe_build_taxonomy_import_context($header, 'product_cat');

assert_true(($context['indexes']['id'] ?? null) === 0, 'Taxonomy import context should map term_id to id.');
assert_true(($context['indexes']['name'] ?? null) === 1, 'Taxonomy import context should map Name column.');
assert_true(($context['indexes']['slug'] ?? null) === 2, 'Taxonomy import context should map slug column.');
assert_true(($context['indexes']['meta_description'] ?? null) === 3, 'Taxonomy import context should map meta description column.');
assert_true(($context['indexes']['meta_title'] ?? false) === false, 'Taxonomy import context should not force-map missing meta title column.');
assert_true(isset($context['custom_cols'][4]) && $context['custom_cols'][4] === 'legacy_code', 'Taxonomy custom columns should keep non-helper field.');
assert_true(!isset($context['custom_cols'][5]), 'Taxonomy custom columns should skip helper *_url field.');

$map = [
    'id' => 0,
    'slug' => 1,
    'field:legacy_code' => 2,
];

$row_by_id = ['200', 'portable', 'LC-1'];
$result_by_id = spe_resolve_taxonomy_import_match(
    $row_by_id,
    $map,
    'product_cat',
    ['unique_meta_field' => 'legacy_code', 'unique_meta_key' => 'legacy_code'],
    [
        'find_by_id' => function ($id) {
            return intval($id) === 200 ? 200 : null;
        },
        'find_by_slug' => function ($slug) {
            return null;
        },
        'find_by_meta' => function ($meta_key, $value) {
            return null;
        },
    ]
);
assert_true(($result_by_id['action'] ?? '') === 'update', 'Taxonomy match should update when ID resolves.');
assert_true(($result_by_id['strategy'] ?? '') === 'id', 'Taxonomy match strategy should prioritize id.');
assert_true(($result_by_id['matched_id'] ?? null) === 200, 'Taxonomy matched ID should be returned for ID strategy.');

$row_by_slug = ['', 'portable', 'LC-2'];
$result_by_slug = spe_resolve_taxonomy_import_match(
    $row_by_slug,
    $map,
    'product_cat',
    ['unique_meta_field' => 'legacy_code', 'unique_meta_key' => 'legacy_code'],
    [
        'find_by_id' => function ($id) {
            return null;
        },
        'find_by_slug' => function ($slug) {
            return $slug === 'portable' ? 321 : null;
        },
        'find_by_meta' => function ($meta_key, $value) {
            return null;
        },
    ]
);
assert_true(($result_by_slug['action'] ?? '') === 'update', 'Taxonomy match should update when slug resolves.');
assert_true(($result_by_slug['strategy'] ?? '') === 'slug', 'Taxonomy match strategy should fall back to slug.');
assert_true(($result_by_slug['matched_id'] ?? null) === 321, 'Taxonomy matched ID should be returned for slug strategy.');

$row_by_meta = ['', '', 'LC-3'];
$result_by_meta = spe_resolve_taxonomy_import_match(
    $row_by_meta,
    $map,
    'product_cat',
    ['unique_meta_field' => 'legacy_code', 'unique_meta_key' => 'legacy_code'],
    [
        'find_by_id' => function ($id) {
            return null;
        },
        'find_by_slug' => function ($slug) {
            return null;
        },
        'find_by_meta' => function ($meta_key, $value) {
            if ($meta_key === 'legacy_code' && $value === 'LC-3') {
                return 456;
            }
            return null;
        },
    ]
);
assert_true(($result_by_meta['action'] ?? '') === 'update', 'Taxonomy match should update when unique meta resolves.');
assert_true(($result_by_meta['strategy'] ?? '') === 'unique_meta', 'Taxonomy match strategy should use unique_meta fallback.');
assert_true(($result_by_meta['matched_id'] ?? null) === 456, 'Taxonomy matched ID should be returned for unique_meta strategy.');

$row_missing = ['', '', ''];
$result_missing = spe_resolve_taxonomy_import_match(
    $row_missing,
    $map,
    'product_cat',
    ['unique_meta_field' => 'legacy_code', 'unique_meta_key' => 'legacy_code', 'allow_insert' => false],
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
assert_true(($result_missing['action'] ?? '') === 'skip', 'Unmatched taxonomy row should be skip when insert disabled.');

fwrite(STDOUT, "PASS: test_taxonomy_import_context_and_match\n");
