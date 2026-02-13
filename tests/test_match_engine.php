<?php
/**
 * M1 - Match engine tests.
 *
 * Run:
 *   php tests/test_match_engine.php
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

require_once __DIR__ . '/../simple-product-export.php';

function assert_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assert_true(function_exists('spe_get_match_engine'), 'spe_get_match_engine should exist.');

$engine = spe_get_match_engine();

$callbacks = [
    'find_by_id' => function ($id) {
        return intval($id) === 101 ? 101 : null;
    },
    'find_by_slug' => function ($slug) {
        return $slug === 'portable-charger' ? 202 : null;
    },
    'find_by_meta' => function ($meta_key, $value) {
        if ($meta_key === 'sku' && $value === 'SKU-001') {
            return 303;
        }
        if ($meta_key === 'sku' && $value === 'DUPLICATED') {
            return [10, 11];
        }
        return null;
    },
];

$matched_by_id = $engine->resolve(
    ['id' => '101', 'slug' => 'ignored'],
    $callbacks,
    ['unique_meta_key' => 'sku', 'unique_meta_field' => 'sku', 'allow_insert' => false]
);
assert_true(($matched_by_id['matched_id'] ?? null) === 101, 'Match engine should prioritize ID match.');
assert_true(($matched_by_id['strategy'] ?? '') === 'id', 'Match strategy should be id.');
assert_true(($matched_by_id['action'] ?? '') === 'update', 'Matched existing row should be update action.');

$matched_by_slug = $engine->resolve(
    ['slug' => 'portable-charger'],
    $callbacks,
    ['unique_meta_key' => 'sku', 'unique_meta_field' => 'sku', 'allow_insert' => false]
);
assert_true(($matched_by_slug['matched_id'] ?? null) === 202, 'Match engine should fall back to slug when ID missing.');
assert_true(($matched_by_slug['strategy'] ?? '') === 'slug', 'Match strategy should be slug.');

$matched_by_meta = $engine->resolve(
    ['sku' => 'SKU-001'],
    $callbacks,
    ['unique_meta_key' => 'sku', 'unique_meta_field' => 'sku', 'allow_insert' => false]
);
assert_true(($matched_by_meta['matched_id'] ?? null) === 303, 'Match engine should fall back to unique meta key.');
assert_true(($matched_by_meta['strategy'] ?? '') === 'unique_meta', 'Match strategy should be unique_meta.');

$conflict = $engine->resolve(
    ['sku' => 'DUPLICATED'],
    $callbacks,
    ['unique_meta_key' => 'sku', 'unique_meta_field' => 'sku', 'allow_insert' => false]
);
assert_true(($conflict['action'] ?? '') === 'error', 'Duplicate unique key should return error action.');
assert_true(($conflict['error'] ?? '') !== '', 'Duplicate unique key should expose error message.');

$insert = $engine->resolve(
    ['slug' => 'brand-new-record'],
    $callbacks,
    ['unique_meta_key' => 'sku', 'unique_meta_field' => 'sku', 'allow_insert' => true]
);
assert_true(($insert['action'] ?? '') === 'insert', 'Unmatched row should become insert when allow_insert is true.');
assert_true(($insert['matched_id'] ?? null) === null, 'Insert action should not have matched_id.');

fwrite(STDOUT, "PASS: test_match_engine\n");
