<?php
/**
 * M1 - Field discovery and mapping engine tests.
 *
 * Run:
 *   php tests/test_mapping_engine.php
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
            [
                'key' => 'group_post_product',
                'location' => [
                    [
                        [
                            'param' => 'post_type',
                            'operator' => '==',
                            'value' => 'product',
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
                ['name' => 'acf_tax_banner'],
            ];
        }

        if ($group_key === 'group_post_product') {
            return [
                ['name' => 'acf_product_badge'],
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

assert_true(function_exists('spe_get_field_discovery'), 'spe_get_field_discovery should exist.');
assert_true(function_exists('spe_get_mapping_engine'), 'spe_get_mapping_engine should exist.');

$field_discovery = spe_get_field_discovery();
$mapping_engine = spe_get_mapping_engine();

$taxonomy_fields = $field_discovery->build_taxonomy_candidate_fields('product_cat', ['legacy_code']);
assert_true(isset($taxonomy_fields['id']), 'Taxonomy field discovery should include id base field.');
assert_true(isset($taxonomy_fields['meta_title']), 'Taxonomy field discovery should include SEO meta title field.');
assert_true(isset($taxonomy_fields['field:acf_tax_banner']), 'Taxonomy field discovery should include ACF taxonomy field.');
assert_true(isset($taxonomy_fields['field:legacy_code']), 'Taxonomy field discovery should include dynamic meta field.');

$post_fields = $field_discovery->build_post_candidate_fields('product', ['supplier_ref'], []);
assert_true(isset($post_fields['id']), 'Post field discovery should include id base field.');
assert_true(isset($post_fields['content']), 'Post field discovery should include content base field.');
assert_true(isset($post_fields['field:acf_product_badge']), 'Post field discovery should include ACF post field.');
assert_true(isset($post_fields['field:supplier_ref']), 'Post field discovery should include dynamic post meta field.');

$headers = ['ID', 'Title', 'Slug', 'Meta Description', 'acf_product_badge'];
$mapping = $mapping_engine->auto_map_headers($headers, $post_fields);
assert_true(($mapping['map']['id'] ?? null) === 0, 'Mapping engine should match ID column.');
assert_true(($mapping['map']['title'] ?? null) === 1, 'Mapping engine should match Title alias.');
assert_true(($mapping['map']['slug'] ?? null) === 2, 'Mapping engine should match Slug alias.');
assert_true(($mapping['map']['meta_description'] ?? null) === 3, 'Mapping engine should match Meta Description alias.');
assert_true(($mapping['map']['field:acf_product_badge'] ?? null) === 4, 'Mapping engine should match custom field by exact header.');
assert_true(!isset($mapping['map']['meta_title']), 'Mapping engine should not duplicate map Title column to meta_title.');

$headers_manual = ['Post ID', 'Product Name', 'SEO Desc'];
$manual_mapping = $mapping_engine->auto_map_headers(
    $headers_manual,
    [
        'id' => ['aliases' => ['ID'], 'label' => 'ID'],
        'title' => ['aliases' => ['Title', '标题'], 'label' => '标题'],
        'meta_description' => ['aliases' => ['Meta Description'], 'label' => 'Meta Description'],
    ],
    [
        'id' => 'Post ID',
        'title' => 'Product Name',
        'meta_description' => 'SEO Desc',
    ]
);

assert_true(($manual_mapping['map']['id'] ?? null) === 0, 'Manual override should map id to custom header.');
assert_true(($manual_mapping['map']['title'] ?? null) === 1, 'Manual override should map title to custom header.');
assert_true(($manual_mapping['map']['meta_description'] ?? null) === 2, 'Manual override should map meta_description to custom header.');

fwrite(STDOUT, "PASS: test_mapping_engine\n");
