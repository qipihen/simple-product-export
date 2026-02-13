<?php
/**
 * M1 - Entity Registry tests.
 *
 * Run:
 *   php tests/test_entity_registry.php
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

$GLOBALS['spe_test_filters'] = [];
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value)
    {
        if (isset($GLOBALS['spe_test_filters'][$tag]) && is_callable($GLOBALS['spe_test_filters'][$tag])) {
            return call_user_func($GLOBALS['spe_test_filters'][$tag], $value);
        }
        return $value;
    }
}

if (!function_exists('get_post_types')) {
    function get_post_types($args = [], $output = 'names')
    {
        return [
            'post' => (object) ['name' => 'post', 'label' => 'Posts', 'public' => true],
            'page' => (object) ['name' => 'page', 'label' => 'Pages', 'public' => true],
            'product' => (object) ['name' => 'product', 'label' => 'Products', 'public' => true],
        ];
    }
}

if (!function_exists('get_taxonomies')) {
    function get_taxonomies($args = [], $output = 'names')
    {
        return [
            'category' => (object) ['name' => 'category', 'label' => 'Categories', 'public' => true],
            'product_cat' => (object) ['name' => 'product_cat', 'label' => 'Product Categories', 'public' => true],
            'custom_tax' => (object) ['name' => 'custom_tax', 'label' => 'Custom Taxonomy', 'public' => true],
        ];
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

assert_true(function_exists('spe_get_entity_registry'), 'spe_get_entity_registry should exist.');

$registry = spe_get_entity_registry();
assert_true(is_object($registry), 'Entity registry instance should be object.');

$post_types = $registry->get_post_types();
assert_true(isset($post_types['product']), 'Entity registry should include public post type product.');

$taxonomies = $registry->get_taxonomies();
assert_true(isset($taxonomies['custom_tax']), 'Entity registry should include custom public taxonomy.');

$entities = $registry->get_entities();
$entity_ids = array_map(function ($item) {
    return $item['id'];
}, $entities);
assert_true(in_array('post:product', $entity_ids, true), 'Entity list should include post:product.');
assert_true(in_array('tax:product_cat', $entity_ids, true), 'Entity list should include tax:product_cat.');

$GLOBALS['spe_test_filters']['spe_entity_registry_exclude'] = function ($exclude) {
    $exclude['post_types'][] = 'post';
    $exclude['taxonomies'][] = 'category';
    return $exclude;
};

$filtered_post_types = $registry->get_post_types();
assert_true(!isset($filtered_post_types['post']), 'Excluded post type should be removed by filter.');

$filtered_taxonomies = $registry->get_taxonomies();
assert_true(!isset($filtered_taxonomies['category']), 'Excluded taxonomy should be removed by filter.');

fwrite(STDOUT, "PASS: test_entity_registry\n");
