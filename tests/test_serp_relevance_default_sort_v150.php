<?php
/**
 * SERP default sort must keep Seekmodo relevance (ORDER BY FIELD).
 *
 * Zen Cart injects PRODUCT_LISTING_DEFAULT_SORT_ORDER into $_GET['sort']
 * (often `2a` = products_name) even when the shopper never chose a sort.
 * The connector must ignore that injection unless `sort=` is present on
 * the inbound query string.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/zc_plugins/Seekmodo/v1.3.50/catalog/includes/functions/numinix_seekmodo_search_lib.php';

$ids = [42, 17, 88];
$failures = 0;

function assert_contains(string $haystack, string $needle, string $label) : void
{
    global $failures;
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: $label\n  expected to contain: $needle\n  got: $haystack\n");
        $failures++;
        return;
    }
    echo "OK: $label\n";
}

function assert_not_contains(string $haystack, string $needle, string $label) : void
{
    global $failures;
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL: $label\n  expected NOT to contain: $needle\n  got: $haystack\n");
        $failures++;
        return;
    }
    echo "OK: $label\n";
}

// Simulate Zen Cart default injection without sort= in the query string.
$_SERVER['QUERY_STRING'] = 'main_page=advanced_search_result&keyword=auto';
$_GET = [
    'main_page' => 'advanced_search_result',
    'keyword' => 'auto',
    'sort' => '2a', // injected default
];
$sql = _numinix_seekmodo_listing_order_sql($ids);
assert_contains($sql, 'ORDER BY FIELD(p.products_id, 42,17,88)', 'default-injected 2a keeps FIELD relevance');
assert_not_contains($sql, 'pd.products_name', 'default-injected 2a does not sort by name');

// Explicit shopper name sort in the query string.
$_SERVER['QUERY_STRING'] = 'main_page=advanced_search_result&keyword=auto&sort=2a';
$_GET['sort'] = '2a';
$sql = _numinix_seekmodo_listing_order_sql($ids);
assert_contains($sql, 'ORDER BY pd.products_name ASC', 'explicit sort=2a sorts by name');

// Explicit relevance sentinel.
$_SERVER['QUERY_STRING'] = 'main_page=advanced_search_result&keyword=auto&sort=relevance';
$_GET['sort'] = 'relevance';
$sql = _numinix_seekmodo_listing_order_sql($ids);
assert_contains($sql, 'ORDER BY FIELD(p.products_id, 42,17,88)', 'sort=relevance uses FIELD');

// Mark helper rewrites $_GET for theme links when relevance is active.
$_SERVER['QUERY_STRING'] = 'main_page=advanced_search_result&keyword=auto';
$_GET['sort'] = '2a';
_numinix_seekmodo_mark_serp_relevance_sort();
if (($_GET['sort'] ?? '') !== 'relevance') {
    fwrite(STDERR, "FAIL: mark helper should set \$_GET[sort]=relevance\n");
    $failures++;
} else {
    echo "OK: mark helper sets sort=relevance for link building\n";
}

// Explicit non-default sort must not be rewritten.
$_SERVER['QUERY_STRING'] = 'main_page=advanced_search_result&keyword=auto&sort=3d';
$_GET['sort'] = '3d';
_numinix_seekmodo_mark_serp_relevance_sort();
if (($_GET['sort'] ?? '') !== '3d') {
    fwrite(STDERR, "FAIL: mark helper must leave explicit sort=3d alone\n");
    $failures++;
} else {
    echo "OK: mark helper leaves explicit sort alone\n";
}

if ($failures > 0) {
    exit(1);
}
echo "All assertions passed.\n";
