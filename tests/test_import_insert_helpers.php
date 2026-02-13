<?php
/**
 * M1 - Insert helper tests for post/taxonomy creation.
 *
 * Run:
 *   php tests/test_import_insert_helpers.php
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

if (!isset($GLOBALS['spe_test_options'])) {
    $GLOBALS['spe_test_options'] = [];
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['spe_test_options']) ? $GLOBALS['spe_test_options'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['spe_test_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return false;
    }
}

$GLOBALS['spe_last_insert_post'] = null;
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($data, $wp_error = false)
    {
        $GLOBALS['spe_last_insert_post'] = $data;
        return 777;
    }
}

$GLOBALS['spe_last_insert_term'] = null;
if (!function_exists('wp_insert_term')) {
    function wp_insert_term($name, $taxonomy, $args = [])
    {
        $GLOBALS['spe_last_insert_term'] = [
            'name' => $name,
            'taxonomy' => $taxonomy,
            'args' => $args,
        ];
        return ['term_id' => 888];
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

$post_context = [
    'indexes' => [
        'title' => 0,
        'slug' => 1,
        'excerpt' => 2,
        'content' => 3,
    ],
];
$post_row = ['New Product', 'parent/new-product', 'short text', 'long text'];
$post_created = spe_create_post_from_import_row('product', $post_row, $post_context);

assert_true(($post_created['id'] ?? 0) === 777, 'Post insert helper should return inserted post ID.');
assert_true(($post_created['error'] ?? 'x') === '', 'Post insert helper should not return error on success.');
assert_true(($GLOBALS['spe_last_insert_post']['post_type'] ?? '') === 'product', 'Post insert helper should set post_type.');
assert_true(($GLOBALS['spe_last_insert_post']['post_status'] ?? '') === 'draft', 'Post insert helper should default to draft status.');
assert_true(($GLOBALS['spe_last_insert_post']['post_title'] ?? '') === 'New Product', 'Post insert helper should map title from row.');
assert_true(($GLOBALS['spe_last_insert_post']['post_name'] ?? '') === 'new-product', 'Post insert helper should normalize slug tail segment.');
assert_true(($GLOBALS['spe_last_insert_post']['post_excerpt'] ?? '') === 'short text', 'Post insert helper should map excerpt from row.');
assert_true(($GLOBALS['spe_last_insert_post']['post_content'] ?? '') === 'long text', 'Post insert helper should map content from row.');

$term_context = [
    'indexes' => [
        'name' => 0,
        'slug' => 1,
        'description' => 2,
        'parent' => 3,
    ],
];
$term_row = ['Portable Chargers', 'catalog/portable-chargers', 'term description', '12'];
$term_created = spe_create_taxonomy_term_from_import_row('product_cat', $term_row, $term_context);

assert_true(($term_created['id'] ?? 0) === 888, 'Taxonomy insert helper should return inserted term ID.');
assert_true(($term_created['error'] ?? 'x') === '', 'Taxonomy insert helper should not return error on success.');
assert_true(($GLOBALS['spe_last_insert_term']['name'] ?? '') === 'Portable Chargers', 'Taxonomy insert helper should map name from row.');
assert_true(($GLOBALS['spe_last_insert_term']['taxonomy'] ?? '') === 'product_cat', 'Taxonomy insert helper should pass taxonomy argument.');
assert_true((($GLOBALS['spe_last_insert_term']['args']['slug'] ?? '') === 'portable-chargers'), 'Taxonomy insert helper should normalize slug tail segment.');
assert_true((($GLOBALS['spe_last_insert_term']['args']['description'] ?? '') === 'term description'), 'Taxonomy insert helper should map description from row.');
assert_true((intval($GLOBALS['spe_last_insert_term']['args']['parent'] ?? 0) === 12), 'Taxonomy insert helper should map numeric parent ID.');

$defaults_initial = spe_get_import_ui_defaults();
assert_true(empty($defaults_initial['product']['allow_insert']), 'Initial UI defaults should be disabled.');
assert_true(($defaults_initial['product']['unique_meta_field'] ?? '') === '', 'Initial UI defaults should have empty unique field.');

spe_update_import_ui_defaults('product', true, 'supplier_sku');
$defaults_after = spe_get_import_ui_defaults();
assert_true(!empty($defaults_after['product']['allow_insert']), 'UI defaults update should persist allow_insert.');
assert_true(($defaults_after['product']['unique_meta_field'] ?? '') === 'supplier_sku', 'UI defaults update should persist unique field.');

assert_true(spe_request_to_bool('1') === true, 'Request bool helper should parse truthy values.');
assert_true(spe_request_to_bool('0') === false, 'Request bool helper should parse falsy values.');

$_POST['spe_allow_insert'] = '1';
$profile_insert_on = spe_resolve_allow_insert_override(['allow_insert' => false], []);
assert_true(!empty($profile_insert_on['allow_insert']), 'Allow-insert override should enable insert when request is truthy.');

$_POST['spe_allow_insert'] = '0';
$profile_insert_off = spe_resolve_allow_insert_override(['allow_insert' => true], []);
assert_true(empty($profile_insert_off['allow_insert']), 'Allow-insert override should disable insert when request is falsy.');
unset($_POST['spe_allow_insert']);

$_POST['spe_import_products_allow_insert'] = '1';
$profile_product_insert = spe_resolve_allow_insert_override(['allow_insert' => false], ['spe_import_products_allow_insert']);
assert_true(!empty($profile_product_insert['allow_insert']), 'Type-specific allow-insert flag should enable insert.');
unset($_POST['spe_import_products_allow_insert']);

$_POST['spe_import_products_unique_meta_field'] = 'supplier_sku';
$profile_unique_meta = spe_resolve_unique_meta_override(
    ['unique_meta_field' => '', 'unique_meta_key' => ''],
    ['spe_import_products_unique_meta_field'],
    ['spe_import_products_unique_meta_key']
);
assert_true(($profile_unique_meta['unique_meta_field'] ?? '') === 'supplier_sku', 'Unique-meta override should read field from request.');
assert_true(($profile_unique_meta['unique_meta_key'] ?? '') === 'supplier_sku', 'Unique-meta override should default key to field when key is empty.');
unset($_POST['spe_import_products_unique_meta_field']);

$saved_profile = spe_save_import_match_profile('product', 'SKU Match Template', [
    'allow_insert' => true,
    'unique_meta_field' => 'supplier_sku',
]);
assert_true(!empty($saved_profile['ok']), 'Import match profile should be saved.');
assert_true(!empty($saved_profile['id']), 'Saved profile should return profile id.');

$_POST['spe_import_products_profile_id'] = (string) $saved_profile['id'];
$selected_profile = spe_resolve_import_match_profile_selection('product', ['spe_import_products_profile_id']);
assert_true(!empty($selected_profile['allow_insert']), 'Selected profile should provide allow_insert configuration.');
assert_true(($selected_profile['unique_meta_field'] ?? '') === 'supplier_sku', 'Selected profile should provide unique_meta_field.');
assert_true(($selected_profile['unique_meta_key'] ?? '') === 'supplier_sku', 'Selected profile should default unique_meta_key from unique_meta_field.');

$profile_with_selection = array_merge(spe_get_post_import_match_profile('product'), $selected_profile);
$profile_with_selection = spe_resolve_allow_insert_override($profile_with_selection, ['spe_import_products_allow_insert']);
$profile_with_selection = spe_resolve_unique_meta_override(
    $profile_with_selection,
    ['spe_import_products_unique_meta_field'],
    ['spe_import_products_unique_meta_key']
);
assert_true(!empty($profile_with_selection['allow_insert']), 'When no explicit request override is provided, selected profile allow_insert should remain enabled.');
assert_true(($profile_with_selection['unique_meta_field'] ?? '') === 'supplier_sku', 'When no explicit request override is provided, selected profile unique_meta_field should remain applied.');
unset($_POST['spe_import_products_profile_id']);

$invalid_profile_selection = spe_resolve_import_match_profile_selection('invalid_type', ['spe_import_products_profile_id']);
assert_true($invalid_profile_selection === [], 'Invalid type should not resolve any import profile selection.');

$header = ['ID', '标题', 'Meta Title', 'supplier_sku'];
$filter_disabled = spe_parse_import_column_filter($header, '');
assert_true(empty($filter_disabled['enabled']), 'Empty import column filter should be disabled.');

$filter_selected = spe_parse_import_column_filter($header, 'ID, Meta Title, 不存在列');
assert_true(!empty($filter_selected['enabled']), 'Non-empty import column filter should be enabled.');
assert_true(in_array(0, $filter_selected['indexes'], true), 'Import column filter should match ID index.');
assert_true(in_array(2, $filter_selected['indexes'], true), 'Import column filter should match Meta Title index.');
assert_true(in_array('不存在列', $filter_selected['missing'], true), 'Import column filter should report missing headers.');

$_POST['spe_import_products_columns'] = "ID,标题";
$filter_from_request = spe_resolve_import_column_filter_from_request($header, ['spe_import_products_columns']);
assert_true(!empty($filter_from_request['enabled']), 'Import column filter should be resolved from request.');
assert_true(spe_import_column_allowed(0, $filter_from_request) === true, 'Allowed import column should pass filter.');
assert_true(spe_import_column_allowed(1, $filter_from_request) === true, 'Allowed import column should pass filter.');
assert_true(spe_import_column_allowed(3, $filter_from_request) === false, 'Non-selected import column should be blocked.');
unset($_POST['spe_import_products_columns']);

fwrite(STDOUT, "PASS: test_import_insert_helpers\n");
