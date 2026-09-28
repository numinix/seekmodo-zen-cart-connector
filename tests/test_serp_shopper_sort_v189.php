<?php
/**
 * SERP shopper sort must honor intentional sort= with Seekmodo on.
 *
 * Ticket #615985 (Cannapot): Relevance works, but name/price/date sorts
 * stayed locked to ORDER BY FIELD. Capture shopper sort before Zen Cart
 * injects PRODUCT_LISTING_DEFAULT, reuse theme native ORDER BY, and
 * keep injected defaults as relevance.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/zc_plugins/Seekmodo/v1.3.89/catalog/includes/functions/numinix_seekmodo_search_lib.php';

$ids = [42, 17, 88];
$failures = 0;

function assert_contains(string $haystack, string $needle, string $label): void
{
    global $failures;
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: $label\n  expected to contain: $needle\n  got: $haystack\n");
        $failures++;
        return;
    }
    echo "OK: $label\n";
}

function assert_same($expected, $actual, string $label): void
{
    global $failures;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: $label\n  expected: " . var_export($expected, true)
            . "\n  actual: " . var_export($actual, true) . "\n");
        $failures++;
        return;
    }
    echo "OK: $label\n";
}

function reset_sort_state(): void
{
    unset(
        $GLOBALS['numinix_seekmodo_shopper_sort_captured'],
        $GLOBALS['numinix_seekmodo_shopper_sort'],
        $GLOBALS['numinix_seekmodo_native_listing_order']
    );
}

// --- Injected default (no shopper sort) keeps FIELD relevance ---
reset_sort_state();
$_SERVER['QUERY_STRING'] = 'main_page=advanced_search_result&keyword=auto';
$_SERVER['REQUEST_URI'] = '/index.php?main_page=advanced_search_result&keyword=auto';
$_GET = ['main_page' => 'advanced_search_result', 'keyword' => 'auto'];
_numinix_seekmodo_remember_shopper_sort_from_request(true);
// Zen Cart later injects default:
$_GET['sort'] = '2a';
_numinix_seekmodo_capture_native_listing_order(
    'SELECT p.products_id FROM products p ORDER BY pd.products_name'
);
$sql = _numinix_seekmodo_listing_order_sql($ids);
assert_contains($sql, 'ORDER BY FIELD(p.products_id, 42,17,88)', 'injected default keeps FIELD');
assert_same(null, _numinix_seekmodo_query_sort_value(), 'injected default query_sort is null');

// --- Explicit sort=2d via QUERY_STRING + Cannapot native ORDER BY ---
reset_sort_state();
$_SERVER['QUERY_STRING'] = 'main_page=advanced_search_result&keyword=white+widow&sort=2d';
$_SERVER['REQUEST_URI'] = '/index.php?main_page=advanced_search_result&keyword=white+widow&sort=2d';
$_GET = ['sort' => '2d', 'keyword' => 'white widow'];
_numinix_seekmodo_remember_shopper_sort_from_request(true);
_numinix_seekmodo_capture_native_listing_order(
    'SELECT p.products_id FROM products p ORDER BY pd.products_name DESC'
);
$sql = _numinix_seekmodo_listing_order_sql($ids);
assert_contains($sql, 'ORDER BY pd.products_name DESC', 'sort=2d uses native name DESC');
assert_same('2d', _numinix_seekmodo_query_sort_value(), 'sort=2d remembered');

// --- Cannapot price desc (sort=3d) via native ORDER BY, not manufacturer ---
reset_sort_state();
$_SERVER['QUERY_STRING'] = 'main_page=advanced_search_result&keyword=x&sort=3d';
$_GET = ['sort' => '3d'];
_numinix_seekmodo_remember_shopper_sort_from_request(true);
_numinix_seekmodo_capture_native_listing_order(
    'SELECT p.products_id FROM products p ORDER BY p.products_price DESC, pd.products_name'
);
$sql = _numinix_seekmodo_listing_order_sql($ids);
assert_contains($sql, 'ORDER BY p.products_price DESC', 'sort=3d uses native price DESC');
if (strpos($sql, 'manufacturers_name') !== false) {
    fwrite(STDERR, "FAIL: sort=3d must not map to manufacturers_name\n");
    $failures++;
} else {
    echo "OK: sort=3d does not use manufacturers_name\n";
}

// --- Cannapot date desc (sort=5d) ---
reset_sort_state();
$_SERVER['QUERY_STRING'] = 'sort=5d&keyword=x';
$_GET = ['sort' => '5d'];
_numinix_seekmodo_remember_shopper_sort_from_request(true);
_numinix_seekmodo_capture_native_listing_order(
    'SELECT p.products_id FROM products p ORDER BY p.products_date_added DESC, pd.products_name'
);
$sql = _numinix_seekmodo_listing_order_sql($ids);
assert_contains($sql, 'ORDER BY p.products_date_added DESC', 'sort=5d uses native date DESC');

// --- Empty QUERY_STRING but HEADER_START trusts $_GET sort=2d ---
reset_sort_state();
$_SERVER['QUERY_STRING'] = '';
$_SERVER['REQUEST_URI'] = '/shop/';
$_GET = ['sort' => '2d', 'keyword' => 'x'];
_numinix_seekmodo_remember_shopper_sort_from_request(true);
assert_same('2d', _numinix_seekmodo_query_sort_value(), 'HEADER_START trusts GET sort when QS empty');
_numinix_seekmodo_capture_native_listing_order(
    'SELECT * FROM products ORDER BY pd.products_name DESC'
);
$sql = _numinix_seekmodo_listing_order_sql($ids);
assert_contains($sql, 'ORDER BY pd.products_name DESC', 'empty QS + trusted GET uses native order');

// --- SEARCH_RESULTS must NOT trust post-injection GET alone ---
reset_sort_state();
$_SERVER['QUERY_STRING'] = 'keyword=x';
$_SERVER['REQUEST_URI'] = '/index.php?keyword=x';
$_GET = ['keyword' => 'x', 'sort' => '2a']; // injected
_numinix_seekmodo_remember_shopper_sort_from_request(false);
assert_same(null, _numinix_seekmodo_query_sort_value(), 'SEARCH_RESULTS ignores injected GET');

// --- sort=relevance keeps FIELD even if native ORDER BY is name ---
reset_sort_state();
$_SERVER['QUERY_STRING'] = 'keyword=x&sort=relevance';
$_GET = ['sort' => 'relevance'];
_numinix_seekmodo_remember_shopper_sort_from_request(true);
_numinix_seekmodo_capture_native_listing_order(
    'SELECT * FROM products ORDER BY pd.products_name'
);
$sql = _numinix_seekmodo_listing_order_sql($ids);
assert_contains($sql, 'ORDER BY FIELD(p.products_id, 42,17,88)', 'sort=relevance keeps FIELD');

// --- REQUEST_URI fallback when QUERY_STRING empty ---
reset_sort_state();
$_SERVER['QUERY_STRING'] = '';
$_SERVER['REQUEST_URI'] = '/index.php?main_page=advanced_search_result&sort=2a&keyword=x';
$_GET = [];
_numinix_seekmodo_remember_shopper_sort_from_request(false);
assert_same('2a', _numinix_seekmodo_query_sort_value(), 'REQUEST_URI supplies sort when QS empty');

// --- Mark helper ---
reset_sort_state();
$_SERVER['QUERY_STRING'] = 'keyword=auto';
$_SERVER['REQUEST_URI'] = '/index.php?keyword=auto';
$_GET = ['keyword' => 'auto']; // no sort yet (HEADER_START)
_numinix_seekmodo_remember_shopper_sort_from_request(true);
$_GET['sort'] = '2a'; // Zen Cart injects after HEADER_START
_numinix_seekmodo_mark_serp_relevance_sort();
assert_same('relevance', $_GET['sort'] ?? null, 'mark helper sets relevance');

reset_sort_state();
$_SERVER['QUERY_STRING'] = 'keyword=auto&sort=3d';
$_SERVER['REQUEST_URI'] = '/index.php?keyword=auto&sort=3d';
$_GET = ['sort' => '3d'];
_numinix_seekmodo_remember_shopper_sort_from_request(true);
_numinix_seekmodo_mark_serp_relevance_sort();
assert_same('3d', $_GET['sort'] ?? null, 'mark helper leaves explicit sort');

if ($failures > 0) {
    exit(1);
}
echo "All assertions passed.\n";
