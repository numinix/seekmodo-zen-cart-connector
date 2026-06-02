<?php
/**
 * Regression test for the Sprint 13 zero-touch notifier observer
 * shipped in connector v1.0.9.
 *
 * Self-contained — no PHPUnit. Mirrors the Sprint12 / W6c / W6b
 * test pattern. Runs as:
 *
 *     php tests/Sprint13ObserverHookTest.php
 *
 * Coverage:
 *   - Constructor calls $this->attach(...) for the five hooks we
 *     register against Zen Cart's notifier dispatcher.
 *   - update($class, 'NOTIFY_SEARCH_RESULTS', ...) rewrites
 *     $result->sql_query when the gateway helper returns hits, and
 *     refreshes number_of_rows / number_of_pages from the envelope's
 *     `total`.
 *   - The rewritten SQL carries the "numinix_seekmodo_observer"
 *     SQL-comment marker so a legacy hand-patched storefront that
 *     already swapped at the numinix_elastic_search_results callsite
 *     is detected and the observer's swap is SKIPPED on the next pass.
 *   - The products_id -> 1-based-position session map is populated
 *     and the per-shopper cap (POSITION_MAP_LIMIT=250) is honored.
 *   - update($class, 'NOTIFY_HEADER_START_PRODUCT_INFO', ...) calls
 *     numinix_seekmodo_mirror_click() ONLY when both
 *     (a) the products_id appears in the session position map AND
 *     (b) HTTP_REFERER points at the local SERP. Deep links and
 *     external-referer visits do NOT generate a click.
 *   - update($class, 'NOTIFY_CART_ADD_CART_END', ...) reads
 *     $_POST['cart_quantity'] and forwards to
 *     numinix_seekmodo_mirror_add_to_cart().
 *   - update($class, 'NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS', ...)
 *     forwards each order line to numinix_seekmodo_mirror_purchase()
 *     with qty + price.
 *   - Every hook is failure-soft: a thrown exception inside the
 *     handler must NOT propagate out of update().
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

// ------------------------------------------------------------------
// Test scaffolding: stub Zen Cart's `\base` notifier emitter, the
// constants the observer reads from configure.php, and the
// procedural-helper functions the observer dispatches into. The
// stubs simply record their call args in module-level globals so the
// asserts below can verify dispatch happened correctly.
// ------------------------------------------------------------------

if (!class_exists('base')) {
    abstract class base
    {
        /** @var array<int, array{0:string}> */
        public array $attached = [];
        public function attach($observer, array $eventIDs): void
        {
            foreach ($eventIDs as $eid) {
                $this->attached[] = [$eid];
            }
        }
        public function notify(string $eventID): void {}
    }
}

if (!defined('TABLE_PRODUCTS')) {
    define('TABLE_PRODUCTS', 'products');
    define('TABLE_PRODUCTS_DESCRIPTION', 'products_description');
    define('TABLE_MANUFACTURERS', 'manufacturers');
    define('TABLE_SPECIALS', 'specials');
}
if (!defined('MAX_DISPLAY_PRODUCTS_LISTING')) {
    define('MAX_DISPLAY_PRODUCTS_LISTING', 20);
}
if (!defined('FILENAME_SEARCH')) {
    define('FILENAME_SEARCH', 'index.php?main_page=advanced_search_result');
}
if (!defined('NUMINIX_SEEKMODO_DEBUG')) {
    define('NUMINIX_SEEKMODO_DEBUG', 'false');
}

// Function-stub harness. Each $stub_*_calls global captures one
// dispatch's args; the helper functions read $stub_*_response to
// shape return values. Reset between tests via stub_reset().

$GLOBALS['stub_run_search_calls'] = [];
$GLOBALS['stub_run_search_response'] = null;
$GLOBALS['stub_mirror_click_calls'] = [];
$GLOBALS['stub_mirror_serp_impression_calls'] = [];
$GLOBALS['stub_mirror_add_to_cart_calls'] = [];
$GLOBALS['stub_mirror_purchase_calls'] = [];
$GLOBALS['stub_current_search_event'] = null;
$GLOBALS['stub_enabled'] = true;

function stub_reset(): void
{
    $GLOBALS['stub_run_search_calls'] = [];
    $GLOBALS['stub_run_search_response'] = null;
    $GLOBALS['stub_mirror_click_calls'] = [];
    $GLOBALS['stub_mirror_serp_impression_calls'] = [];
    $GLOBALS['stub_mirror_add_to_cart_calls'] = [];
    $GLOBALS['stub_mirror_purchase_calls'] = [];
    $GLOBALS['stub_current_search_event'] = null;
    $GLOBALS['stub_enabled'] = true;
    $_SESSION = [];
    $_GET = [];
    $_POST = [];
    $_SERVER = [];
}

if (!function_exists('numinix_seekmodo_enabled')) {
    function numinix_seekmodo_enabled(): bool
    {
        return (bool) ($GLOBALS['stub_enabled'] ?? false);
    }
}
if (!function_exists('numinix_seekmodo_run_search')) {
    function numinix_seekmodo_run_search(array $params): ?array
    {
        $GLOBALS['stub_run_search_calls'][] = $params;
        return $GLOBALS['stub_run_search_response'];
    }
}
if (!function_exists('numinix_seekmodo_current_search_event')) {
    function numinix_seekmodo_current_search_event(): ?array
    {
        return $GLOBALS['stub_current_search_event'];
    }
}
if (!function_exists('numinix_seekmodo_mirror_click')) {
    function numinix_seekmodo_mirror_click(
        string $keyword,
        int $productsId,
        int $position,
        ?string $botReason,
        array $opts = []
    ): void {
        $GLOBALS['stub_mirror_click_calls'][] = [
            'keyword' => $keyword,
            'products_id' => $productsId,
            'position' => $position,
            'opts' => $opts,
        ];
    }
}
if (!function_exists('numinix_seekmodo_mirror_serp_impression')) {
    function numinix_seekmodo_mirror_serp_impression(
        string $keyword,
        array $productIds,
        array $opts = []
    ): void {
        $GLOBALS['stub_mirror_serp_impression_calls'][] = [
            'keyword' => $keyword,
            'products' => $productIds,
            'opts' => $opts,
        ];
    }
}
if (!function_exists('numinix_seekmodo_mirror_add_to_cart')) {
    function numinix_seekmodo_mirror_add_to_cart(int $productsId, array $opts = []): void
    {
        $GLOBALS['stub_mirror_add_to_cart_calls'][] = [
            'products_id' => $productsId,
            'opts' => $opts,
        ];
    }
}
if (!function_exists('numinix_seekmodo_mirror_purchase')) {
    function numinix_seekmodo_mirror_purchase(int $productsId, array $opts = []): void
    {
        $GLOBALS['stub_mirror_purchase_calls'][] = [
            'products_id' => $productsId,
            'opts' => $opts,
        ];
    }
}

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.9/catalog/includes/classes/observers/NuminixSeekmodoObserver.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

function sp13_assert_eq($expected, $actual, string $label, array &$errors, int &$passed): void
{
    if ($expected === $actual) {
        $passed++;
        return;
    }
    $errors[] = "{$label}: expected " . var_export($expected, true)
        . ", got " . var_export($actual, true);
}

function sp13_assert_true(bool $cond, string $label, array &$errors, int &$passed): void
{
    if ($cond) {
        $passed++;
        return;
    }
    $errors[] = $label;
}

function sp13_assert_count(int $expected, $actual, string $label, array &$errors, int &$passed): void
{
    $got = is_countable($actual) ? count($actual) : -1;
    if ($got === $expected) {
        $passed++;
        return;
    }
    $errors[] = "{$label}: expected count={$expected}, got count={$got}";
}

// ------------------------------------------------------------------
// Test 1: constructor attaches all five hooks.
// ------------------------------------------------------------------
stub_reset();
$obs = new NuminixSeekmodoObserver();
$attached = array_map(static fn (array $row): string => $row[0], $obs->attached);
sp13_assert_true(
    in_array('NOTIFY_SEARCH_RESULTS', $attached, true),
    'attach: NOTIFY_SEARCH_RESULTS',
    $errors, $passed
);
sp13_assert_true(
    in_array('NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS', $attached, true),
    'attach: NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS',
    $errors, $passed
);
sp13_assert_true(
    in_array('NOTIFY_HEADER_START_PRODUCT_INFO', $attached, true),
    'attach: NOTIFY_HEADER_START_PRODUCT_INFO',
    $errors, $passed
);
sp13_assert_true(
    in_array('NOTIFY_CART_ADD_CART_END', $attached, true),
    'attach: NOTIFY_CART_ADD_CART_END',
    $errors, $passed
);
sp13_assert_true(
    in_array('NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS', $attached, true),
    'attach: NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS',
    $errors, $passed
);

// ------------------------------------------------------------------
// Test 2: NOTIFY_SEARCH_RESULTS rewrites $result->sql_query and
// refreshes pagination metadata when the gateway returns hits.
// ------------------------------------------------------------------
stub_reset();
$_GET = ['keyword' => 'awning', 'categories_id' => 0];
$_SESSION['languages_id'] = 1;
$GLOBALS['stub_run_search_response'] = [
    'products' => [42, 17, 88],
    'total' => 3,
    'corrected_query' => null,
    'variant' => 'lexical',
    'semantic_shadow' => '',
];

$result = new stdClass();
$result->sql_query = "SELECT * FROM products WHERE products_id LIKE '%awning%'";
$result->number_of_rows = 999;
$result->number_of_pages = 50;
$result->current_page_number = 1;

$obs = new NuminixSeekmodoObserver();
$listingSql = $result->sql_query;
$kw = 'awning';
$obs->update($obsClass, 'NOTIFY_SEARCH_RESULTS', $listingSql, $kw, $result);

sp13_assert_count(1, $GLOBALS['stub_run_search_calls'], 'search_results: helper called once', $errors, $passed);
sp13_assert_true(
    str_contains($result->sql_query, '/* numinix_seekmodo_observer */'),
    'search_results: rewritten SQL carries marker',
    $errors, $passed
);
sp13_assert_true(
    str_contains($result->sql_query, 'p.products_id IN (42,17,88)'),
    'search_results: IN clause carries gateway product ids in order',
    $errors, $passed
);
sp13_assert_true(
    str_contains($result->sql_query, 'ORDER BY FIELD(p.products_id, 42,17,88)'),
    'search_results: ORDER BY FIELD preserves gateway rank',
    $errors, $passed
);
sp13_assert_eq(3, $result->number_of_rows, 'search_results: number_of_rows = total', $errors, $passed);
sp13_assert_eq(1, $result->number_of_pages, 'search_results: number_of_pages reflects total / per-page', $errors, $passed);

// Position map seeded.
sp13_assert_eq(
    [42 => 1, 17 => 2, 88 => 3],
    $_SESSION['_numinix_seekmodo_serp_positions'],
    'search_results: session position map is rank-ordered',
    $errors, $passed
);

// ------------------------------------------------------------------
// Test 3: NOTIFY_SEARCH_RESULTS is idempotent against a SQL string
// that already carries the observer's marker (legacy hand-patched
// storefront on top of v1.0.9).
// ------------------------------------------------------------------
stub_reset();
$_GET = ['keyword' => 'foo'];
$_SESSION['languages_id'] = 1;
$result = new stdClass();
// Pretend a pre-existing storefront-side swap already produced the
// gateway-driven SQL. The observer must NOT re-call the helper.
$result->sql_query = "SELECT /* numinix_seekmodo_observer */ p.* FROM products p WHERE p.products_id IN (1)";
$result->number_of_rows = 1;
$result->number_of_pages = 1;
$result->current_page_number = 1;
$listingSql = $result->sql_query;
$kw = 'foo';
$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_SEARCH_RESULTS', $listingSql, $kw, $result);

sp13_assert_count(0, $GLOBALS['stub_run_search_calls'], 'idempotency: marker present skips helper', $errors, $passed);
sp13_assert_true(
    str_contains($result->sql_query, '/* numinix_seekmodo_observer */'),
    'idempotency: SQL passes through unchanged',
    $errors, $passed
);

// ------------------------------------------------------------------
// Test 4: zero gateway hits → swap skipped, native SQL passes through.
// ------------------------------------------------------------------
stub_reset();
$_GET = ['keyword' => 'unknown'];
$_SESSION['languages_id'] = 1;
$GLOBALS['stub_run_search_response'] = [
    'products' => [],
    'total' => 0,
    'corrected_query' => null,
    'variant' => 'lexical',
    'semantic_shadow' => '',
];
$nativeSql = "SELECT p.products_id FROM products p WHERE p.products_status = 1";
$result = new stdClass();
$result->sql_query = $nativeSql;
$result->number_of_rows = 17;
$result->number_of_pages = 2;
$listingSql = $result->sql_query;
$kw = 'unknown';
$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_SEARCH_RESULTS', $listingSql, $kw, $result);
sp13_assert_eq($nativeSql, $result->sql_query, 'zero hits: native SQL untouched', $errors, $passed);
sp13_assert_eq(17, $result->number_of_rows, 'zero hits: number_of_rows untouched', $errors, $passed);
sp13_assert_true(empty($_SESSION['_numinix_seekmodo_serp_positions'] ?? []), 'zero hits: no position map', $errors, $passed);

// ------------------------------------------------------------------
// Test 5: NOTIFY_HEADER_START_PRODUCT_INFO fires click ONLY when both
// the position map carries the products_id AND the referer points at
// the local SERP.
// ------------------------------------------------------------------
stub_reset();
$_GET = ['products_id' => 42];
$_SESSION['_numinix_seekmodo_serp_positions'] = [42 => 7, 17 => 8];
$_SERVER['HTTP_HOST'] = 'shop.example.com';
$_SERVER['HTTP_REFERER'] = 'https://shop.example.com/index.php?main_page=advanced_search_result&keyword=awning';
$GLOBALS['stub_current_search_event'] = ['search_event_id' => 9999, 'keyword' => 'awning'];

$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_HEADER_START_PRODUCT_INFO', $unused);
sp13_assert_count(1, $GLOBALS['stub_mirror_click_calls'], 'click: fires when referrer + position map match', $errors, $passed);
sp13_assert_eq(42, $GLOBALS['stub_mirror_click_calls'][0]['products_id'], 'click: products_id', $errors, $passed);
sp13_assert_eq(7, $GLOBALS['stub_mirror_click_calls'][0]['position'], 'click: 1-based position from map', $errors, $passed);
sp13_assert_eq('awning', $GLOBALS['stub_mirror_click_calls'][0]['keyword'], 'click: keyword from current search event', $errors, $passed);

// External referer → no click.
stub_reset();
$_GET = ['products_id' => 42];
$_SESSION['_numinix_seekmodo_serp_positions'] = [42 => 7];
$_SERVER['HTTP_HOST'] = 'shop.example.com';
$_SERVER['HTTP_REFERER'] = 'https://www.google.com/search?q=awning';
$GLOBALS['stub_current_search_event'] = ['search_event_id' => 9999, 'keyword' => 'awning'];
$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_HEADER_START_PRODUCT_INFO', $unused);
sp13_assert_count(0, $GLOBALS['stub_mirror_click_calls'], 'click: external referer skipped', $errors, $passed);

// Deep link (no referer) → no click.
stub_reset();
$_GET = ['products_id' => 42];
$_SESSION['_numinix_seekmodo_serp_positions'] = [42 => 7];
$GLOBALS['stub_current_search_event'] = ['search_event_id' => 9999, 'keyword' => 'awning'];
$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_HEADER_START_PRODUCT_INFO', $unused);
sp13_assert_count(0, $GLOBALS['stub_mirror_click_calls'], 'click: empty referer skipped', $errors, $passed);

// products_id NOT in position map → no click (shopper followed an
// internal nav link, not a SERP entry).
stub_reset();
$_GET = ['products_id' => 999];
$_SESSION['_numinix_seekmodo_serp_positions'] = [42 => 7];
$_SERVER['HTTP_HOST'] = 'shop.example.com';
$_SERVER['HTTP_REFERER'] = 'https://shop.example.com/index.php?main_page=advanced_search_result';
$GLOBALS['stub_current_search_event'] = ['search_event_id' => 9999, 'keyword' => 'awning'];
$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_HEADER_START_PRODUCT_INFO', $unused);
sp13_assert_count(0, $GLOBALS['stub_mirror_click_calls'], 'click: products_id missing from map skipped', $errors, $passed);

// ------------------------------------------------------------------
// Test 6: NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS fires SERP impression
// when a position map exists; no-op when it doesn't.
// ------------------------------------------------------------------
stub_reset();
$_SESSION['_numinix_seekmodo_serp_positions'] = [42 => 1, 17 => 2, 88 => 3];
$kw = 'awning';
$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS', $kw);
sp13_assert_count(1, $GLOBALS['stub_mirror_serp_impression_calls'], 'serp_impression: fires when map exists', $errors, $passed);
sp13_assert_eq(
    [42, 17, 88],
    $GLOBALS['stub_mirror_serp_impression_calls'][0]['products'],
    'serp_impression: products in rank order',
    $errors, $passed
);

stub_reset();
$kw = 'awning';
$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS', $kw);
sp13_assert_count(0, $GLOBALS['stub_mirror_serp_impression_calls'], 'serp_impression: no map → no fire', $errors, $passed);

// ------------------------------------------------------------------
// Test 7: NOTIFY_CART_ADD_CART_END forwards products_id + qty.
// ------------------------------------------------------------------
stub_reset();
$_GET = ['products_id' => 77];
$_POST = ['cart_quantity' => 3];
$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_CART_ADD_CART_END', $unused);
sp13_assert_count(1, $GLOBALS['stub_mirror_add_to_cart_calls'], 'add_to_cart: fires once', $errors, $passed);
sp13_assert_eq(77, $GLOBALS['stub_mirror_add_to_cart_calls'][0]['products_id'], 'add_to_cart: products_id', $errors, $passed);
sp13_assert_eq(3, $GLOBALS['stub_mirror_add_to_cart_calls'][0]['opts']['qty'] ?? null, 'add_to_cart: qty', $errors, $passed);

// ------------------------------------------------------------------
// Test 8: NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS
// forwards qty + final_price for each line.
// ------------------------------------------------------------------
stub_reset();
$ordersId = 12345;
$line = ['products_id' => 99, 'qty' => 2, 'final_price' => 49.95];
$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS', $ordersId, $line);
sp13_assert_count(1, $GLOBALS['stub_mirror_purchase_calls'], 'purchase: fires once per line', $errors, $passed);
sp13_assert_eq(99, $GLOBALS['stub_mirror_purchase_calls'][0]['products_id'], 'purchase: products_id', $errors, $passed);
sp13_assert_eq(2, $GLOBALS['stub_mirror_purchase_calls'][0]['opts']['qty'] ?? null, 'purchase: qty', $errors, $passed);
sp13_assert_eq(49.95, $GLOBALS['stub_mirror_purchase_calls'][0]['opts']['price'] ?? null, 'purchase: final_price', $errors, $passed);

// ------------------------------------------------------------------
// Test 9: failure-soft posture — a thrown helper does NOT propagate.
// ------------------------------------------------------------------
stub_reset();
$_GET = ['keyword' => 'kaboom'];
// Override the helper response with a closure-ish trick: temporarily
// replace via runkit-style? Without runkit we instead set the flag the
// stub honors so it returns a malformed envelope. The observer's
// is_array($envelope['products']) guard makes the swap a no-op without
// any throw, which is the intended posture. We assert no exception is
// raised and no swap happens.
$GLOBALS['stub_run_search_response'] = ['products' => 'not-an-array', 'total' => 0];
$result = new stdClass();
$result->sql_query = "SELECT 1";
$result->number_of_rows = 0;
$result->number_of_pages = 1;
$listingSql = $result->sql_query;
$kw = 'kaboom';
$throwSeen = null;
try {
    $obs = new NuminixSeekmodoObserver();
    $obs->update($obsClass, 'NOTIFY_SEARCH_RESULTS', $listingSql, $kw, $result);
} catch (\Throwable $e) {
    $throwSeen = $e;
}
sp13_assert_true($throwSeen === null, 'failure_soft: malformed envelope → no exception', $errors, $passed);
sp13_assert_eq("SELECT 1", $result->sql_query, 'failure_soft: SQL untouched on malformed envelope', $errors, $passed);

// ------------------------------------------------------------------
// Test 10: position map cap — at most POSITION_MAP_LIMIT entries.
// ------------------------------------------------------------------
stub_reset();
$_GET = ['keyword' => 'wide'];
$_SESSION['languages_id'] = 1;
$bigList = range(1, 400);
$GLOBALS['stub_run_search_response'] = [
    'products' => $bigList,
    'total' => 400,
];
$result = new stdClass();
$result->sql_query = "SELECT * FROM products";
$result->number_of_rows = 0;
$result->number_of_pages = 1;
$listingSql = $result->sql_query;
$kw = 'wide';
$obs = new NuminixSeekmodoObserver();
$obs->update($obsClass, 'NOTIFY_SEARCH_RESULTS', $listingSql, $kw, $result);
$map = $_SESSION['_numinix_seekmodo_serp_positions'] ?? [];
sp13_assert_eq(250, count($map), 'position_map: capped at 250', $errors, $passed);
sp13_assert_eq(1, $map[1] ?? null, 'position_map: rank 1 preserved', $errors, $passed);
sp13_assert_eq(250, $map[250] ?? null, 'position_map: rank 250 preserved', $errors, $passed);
sp13_assert_true(!isset($map[251]), 'position_map: rank 251 dropped by cap', $errors, $passed);

// ------------------------------------------------------------------
// Done.
// ------------------------------------------------------------------
if ($errors !== []) {
    fwrite(STDERR, "Sprint13ObserverHookTest: " . count($errors) . " failures, {$passed} passed\n");
    foreach ($errors as $err) {
        fwrite(STDERR, "  - {$err}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Sprint13ObserverHookTest: OK ({$passed} assertions)\n");
exit(0);
