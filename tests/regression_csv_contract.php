<?php
/**
 * Lightweight regression checks for CSV import/export contract.
 *
 * Run:
 *   php tests/regression_csv_contract.php
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

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($id)
    {
        if (intval($id) > 0) {
            return 'https://example.com/uploads/' . intval($id) . '.jpg';
        }
        return false;
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

class DummyWpdb
{
    public $termmeta = 'wp_termmeta';
    public $term_taxonomy = 'wp_term_taxonomy';

    public function prepare($query, ...$args)
    {
        return $query;
    }

    public function get_col($query)
    {
        return ['youtube_url', 'cat_image'];
    }
}

$GLOBALS['wpdb'] = new DummyWpdb();

$header = ['id', 'Meta Title', 'foo_url'];
assert_true(
    spe_find_header_col($header, ['ID']) === 0,
    'Header matching should be case-insensitive for ID.'
);

$line = "ID;Title;Slug\n";
assert_true(
    spe_detect_csv_delimiter_from_line($line) === ';',
    'Delimiter detection should identify semicolon CSV.'
);

$custom_fields = ['cat_image', 'youtube_url'];
$attachment_field_map = ['cat_image' => true];
$custom_values = [
    'cat_image' => '123',
    'youtube_url' => 'https://youtu.be/demo'
];
$result = spe_build_custom_export_row_segments($custom_fields, $attachment_field_map, $custom_values);

assert_true(
    $result['values'] === ['123', 'https://youtu.be/demo'],
    'Custom value segment should preserve custom_fields order.'
);
assert_true(
    count($result['urls']) === 1 && $result['urls'][0] === 'https://example.com/uploads/123.jpg',
    'URL segment should append helper URLs in header order.'
);

$resolved = spe_resolve_taxonomy_export_fields('product_cat', ['slug', 'field:youtube_url']);
assert_true(
    $resolved === ['id', 'slug', 'field:youtube_url'],
    'Resolved export fields should keep valid order and force include ID.'
);

$resolved_default = spe_resolve_taxonomy_export_fields('product_cat', []);
assert_true(
    in_array('id', $resolved_default, true) && in_array('field:youtube_url', $resolved_default, true),
    'Default taxonomy export fields should include ID and available custom fields.'
);

$required_custom_fields = spe_get_taxonomy_required_custom_fields();
assert_true(
    empty($required_custom_fields),
    'Required taxonomy custom fields should be empty by default for universal usage.'
);

assert_true(
    function_exists('spe_get_active_seo_provider'),
    'SEO provider detector should exist.'
);

assert_true(
    spe_get_active_seo_provider() === '',
    'Without active SEO plugins, provider detector should return empty string.'
);

fwrite(STDOUT, "PASS: regression_csv_contract\n");
