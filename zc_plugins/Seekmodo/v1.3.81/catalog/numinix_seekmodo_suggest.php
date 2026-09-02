<?php
/**
 * Sprint 3 PR 6 — storefront-side typeahead AJAX endpoint.
 *
 * The client-side `jscript_seekmodo_typeahead.js` debounces a shopper's
 * keystrokes (150ms) and GETs this endpoint with `?q=...`. We call
 * `numinix_seekmodo_run_typeahead()` which routes through the
 * gateway's SuggestTool (/v1/suggest), and echo the resulting
 * envelope as JSON for the JS to render into the storefront's
 * autocomplete dropdown.
 *
 * Why this is a separate catalog-level endpoint (rather than the
 * storefront's existing `ajax/ajax_typeahead.php` shim, which most
 * Zen Cart stores already have):
 *
 *   1. We don't want to hijack the storefront's existing typeahead
 *      contract — operators may have a custom shape for it. This
 *      endpoint emits the SuggestTool's three-block envelope
 *      verbatim (keywords + products + categories) so the JS can
 *      render multi-section autocomplete without having to translate
 *      back from the storefront's flat shape.
 *   2. Operators opting INTO Seekmodo-driven typeahead want a clean
 *      "drop in this file + reference this URL in our JS" install.
 *      The existing ajax_typeahead.php can stay untouched and serve
 *      as the fallback when this endpoint returns 503 or
 *      `{ok:false, fallback:true}`.
 *
 * Response shape (always 200 unless the request is invalid):
 *   {
 *     ok: true,
 *     q: "...",
 *     keywords:   [ {keyword, search_count} ],
 *     products:   [ {products_id, value, model, price, url, image?} ],
 *     categories: [ {name, count} ],
 *     total: int                 // count of products surfaced
 *   }
 * or
 *   {
 *     ok: false,
 *     fallback: true,            // means: JS should call the storefront's own typeahead
 *     reason: "off|shadow|locked_out|too_short|circuit_open|gateway_failure"
 *   }
 *
 * Mode handling:
 *   - off / shadow / locked_out → `ok:false, fallback:true`. Storefront
 *     keeps its own typeahead UI.
 *   - enforce + gateway success → `ok:true` + the three blocks.
 *   - enforce + gateway failure → `ok:false, fallback:true`.
 *
 * No HMAC signing here — the connector's `Client::call` does that
 * server-to-gateway. The browser-to-connector hop is unauthenticated
 * (same trust model as Zen Cart's other ajax endpoints — the
 * tenant's `tenant_id` and shared secret never leave PHP).
 */

declare(strict_types=1);

// v1.0.22 fix-pack #5 (regression fix for SM-606 browser-token POSTs):
// the `<seekmodo-suggest>` web component's SDK calls our refresh URL
// with `method: 'POST'` on every keystroke that needs a fresh JWT
// (Bun-built bundle, `packages/web-components/src/shared/client.ts`
// L110). Zen Cart's `init_includes/init_sanitize.php` -- pulled in by
// `application_top.php` -- rejects any POST that doesn't carry a valid
// `securityToken` form field and 302-redirects the shopper to
// `/time-out` BEFORE our route handler can mint a token. The SDK
// follows the redirect, finds /time-out doesn't return JSON, and
// surfaces the failure as `seekmodo:refresh route returned HTTP 404`
// -- the precise console.warn line we captured in the Numinix.com
// repro under CDP Runtime.evaluate. The user-visible symptom is
// "search suggestions do not work at all" because the widget's
// `current` envelope stays null and the dropdown's shadow root only
// renders its <style> block.
//
// The shim itself ignores $_POST entirely -- seekmodo_action is read from
// $_GET['seekmodo_action'], the request body is unread. So we can safely
// downgrade the request to GET before ZC's CSRF gate sees it. We
// scope the patch as narrowly as possible: only the browser-token
// action, only when the method is POST, so existing GET clients and
// any future POST routes are untouched.
//
// IMPORTANT: never use bare `action=` — Zen Cart's init_cart_handler.php
// treats ANY `$_GET['action']` as a cart command and 302s to
// cookie_usage when the session cookie is missing.
if (
    (($_GET['seekmodo_action'] ?? '') === 'browser-token')
    && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
}

// v1.0.22: resolve `includes/application_top.php` via __DIR__ so the
// shim works whether it's served from the live catalog root (legacy
// tenant deploys that copied the file there) OR from the plugin's
// versioned dir at `/catalog/zc_plugins/Seekmodo/v<version>/catalog/`
// (the v1.0.22+ URL that NuminixSeekmodoSuggestObserver emits via
// `seekmodo:refresh`). Prior to v1.0.22 the shim was only ever
// reachable when manually copied to the live catalog root; the
// plugin-dir URL hit ZC's session bootstrap with the wrong CWD and
// 302-redirected to the storefront home before the route handler
// could run.
//
// We chdir() to the live catalog root BEFORE requiring
// application_top.php because ZC's bootstrap does relative requires
// throughout (init_includes/, modules/, etc.) and silently
// short-circuits to a redirect when the CWD isn't the catalog root.
$applicationTopCandidates = [
    __DIR__ . '/includes/application_top.php',
    __DIR__ . '/../../../../includes/application_top.php',
];
$applicationTopPath = null;
foreach ($applicationTopCandidates as $candidate) {
    if (is_file($candidate)) {
        $applicationTopPath = $candidate;
        break;
    }
}
if ($applicationTopPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'application_top_not_found']);
    return;
}
// catalog root = parent of the includes/ dir holding application_top.php
$includesDir = dirname((string) realpath($applicationTopPath));
$catalogRoot = dirname($includesDir);
if ($catalogRoot !== '' && is_dir($catalogRoot)) {
    chdir($catalogRoot);
}
require $applicationTopPath;

// v1.3.24 — load zc_plugins catalog init when Plugin Manager auto_loaders
// did not merge (common on Zen Cart 1.5.7 / shim-only installs).
$ensureHelpers = [
    __DIR__ . '/includes/functions/numinix_seekmodo_ensure_plugin_init.php',
];
if (defined('DIR_FS_CATALOG') && is_string(DIR_FS_CATALOG) && DIR_FS_CATALOG !== '') {
    $ensureHelpers = array_merge(
        $ensureHelpers,
        glob(rtrim(str_replace('\\', '/', DIR_FS_CATALOG), '/') . '/zc_plugins/Seekmodo/v*/catalog/includes/functions/numinix_seekmodo_ensure_plugin_init.php') ?: []
    );
}
$ensureHelpers = array_values(array_unique(array_filter($ensureHelpers, 'is_file')));
usort($ensureHelpers, 'strnatcmp');
$ensureHelpers = array_reverse($ensureHelpers);
if ($ensureHelpers !== []) {
    require_once $ensureHelpers[0];
}
if (function_exists('numinix_seekmodo_ensure_plugin_init')) {
    numinix_seekmodo_ensure_plugin_init();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    return;
}

// v1.0.21 (SM-606) — browser-token refresh route. Pointed at by the
// `<seekmodo-suggest>` web component's `seekmodo:refresh` meta so a
// long-running tab can mint a fresh gateway-direct JWT after the
// inline 5-min token expires, without a full page reload. Same
// contract as the WP connector's `/wp-json/seekmodo/v1/browser-token`:
// returns `{token, expires_at, session_id}` on success or
// `{error}` on any failure.
if (($_GET['seekmodo_action'] ?? '') === 'browser-token') {
    if (!function_exists('numinix_seekmodo_client') || !function_exists('numinix_seekmodo_enabled')
        || !numinix_seekmodo_enabled()
    ) {
        http_response_code(503);
        echo json_encode(['error' => 'unpaired']);
        return;
    }
    $client = numinix_seekmodo_client();
    if (!is_object($client) || !method_exists($client, 'mintBrowserToken')) {
        http_response_code(503);
        echo json_encode(['error' => 'client_unavailable']);
        return;
    }
    // v1.0.22 fixup: mintBrowserToken() POSTs /v1/tenants/token
    // directly, bypassing callTool()'s dot-only tool-name regex.
    // v1.0.21 used callTool('tenants/token') which short-circuited
    // to null on the regex check and surfaced as `mint_failed`.
    $resp = $client->mintBrowserToken(300);
    if (!is_array($resp) || !isset($resp['token'], $resp['expires_at'])) {
        http_response_code(503);
        echo json_encode(['error' => 'mint_failed']);
        return;
    }
    echo json_encode([
        'token'      => (string) $resp['token'],
        'expires_at' => (int)    $resp['expires_at'],
        'session_id' => isset($resp['session_id']) ? (string) $resp['session_id'] : '',
    ], JSON_UNESCAPED_SLASHES);
    return;
}

// Stamp unpaid / over-quota sticky from the browser after a gateway
// HTTP 402 on /v1/suggest so the next page render can skip cloud
// suggest and serve Enhanced Native only (zero storefront→gateway
// suggest calls until a successful metered search/suggest clears it).
//
// Daily unpaid-recovery plan (2026-08) Bugbot finding ("Quota guard
// misses widget stamps"): this used to hardcode `code=trial_expired`
// for every 402, even a genuine metered over_quota denial. Accept an
// optional `code` (see Client::normalizeDenialCode()) so the browser
// can pass the real reason through once the bundled web component
// starts including it on its quota-empty event; until then this
// resolves to the same `trial_expired` default as before.
if (($_GET['seekmodo_action'] ?? '') === 'stamp-cloud-denied') {
    if (class_exists('\\Numinix\\Seekmodo\\Client')) {
        $code = \Numinix\Seekmodo\Client::normalizeDenialCode(
            isset($_GET['code']) ? (string) $_GET['code'] : null
        );
        \Numinix\Seekmodo\Client::markCloudSuggestDenied(
            json_encode(['code' => $code], JSON_UNESCAPED_SLASHES) ?: '{"code":"trial_expired"}'
        );
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
    return;
}

// Live-stock partition order for gateway-direct `<seekmodo-suggest>` rows.
// The browser widget calls /v1/suggest directly; this route reorders visible
// product cards to match the SERP's live DB stock overlay.
if (($_GET['seekmodo_action'] ?? '') === 'stock-order') {
    $rawIds = isset($_GET['ids']) ? (string) $_GET['ids'] : '';
    $ids = [];
    foreach (preg_split('/\s*,\s*/', $rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $pid = (int) $part;
        if ($pid > 0) {
            $ids[$pid] = true;
        }
    }
    $ids = array_keys($ids);
    if ($ids === [] || count($ids) > 20) {
        echo json_encode(['ok' => false, 'error' => 'invalid_ids']);
        return;
    }
    if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()
        || !function_exists('numinix_seekmodo_catalog_partition_product_ids_live_stock')
    ) {
        echo json_encode(['ok' => true, 'order' => $ids]);
        return;
    }
    echo json_encode([
        'ok' => true,
        'order' => numinix_seekmodo_catalog_partition_product_ids_live_stock($ids),
    ], JSON_UNESCAPED_SLASHES);
    return;
}

// Batch product thumbnail lookup for `<seekmodo-suggest>` open-event
// hydration. The web component fetches gateway /v1/suggest in-browser;
// this route resolves optimized image_url from Zen Cart by products_id
// without another gateway round-trip.
if (($_GET['seekmodo_action'] ?? '') === 'images') {
    if (!function_exists('numinix_seekmodo_suggest_product_images')) {
        $libDir = (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : __DIR__ . '/')
            . 'includes/functions/';
        if (is_file($libDir . 'numinix_seekmodo_typeahead_lib.php')) {
            require_once $libDir . 'numinix_seekmodo_typeahead_lib.php';
        }
    }
    $rawIds = isset($_GET['ids']) ? (string) $_GET['ids'] : '';
    $ids = [];
    foreach (preg_split('/\s*,\s*/', $rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $pid = (int) $part;
        if ($pid > 0) {
            $ids[$pid] = true;
        }
    }
    $ids = array_keys($ids);
    if ($ids === [] || count($ids) > 20) {
        echo json_encode(['ok' => false, 'error' => 'invalid_ids']);
        return;
    }
    if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()
        || !function_exists('numinix_seekmodo_suggest_product_images')
    ) {
        echo json_encode(['ok' => false, 'error' => 'unavailable']);
        return;
    }
    echo json_encode([
        'ok' => true,
        'images' => numinix_seekmodo_suggest_product_images($ids),
        'names' => function_exists('numinix_seekmodo_suggest_product_names')
            ? numinix_seekmodo_suggest_product_names($ids)
            : [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

// Batch session-currency price lookup for `<seekmodo-suggest>` open-event
// hydration. Gateway /v1/suggest returns base-currency floats without a
// currency code; this route resolves zen_get_products_display_price() per
// products_id so multicurrency storefronts show the shopper's currency.
if (($_GET['seekmodo_action'] ?? '') === 'prices') {
    if (!function_exists('numinix_seekmodo_suggest_product_prices')) {
        $libDir = (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : __DIR__ . '/')
            . 'includes/functions/';
        if (is_file($libDir . 'numinix_seekmodo_typeahead_lib.php')) {
            require_once $libDir . 'numinix_seekmodo_typeahead_lib.php';
        }
    }
    $rawIds = isset($_GET['ids']) ? (string) $_GET['ids'] : '';
    $ids = [];
    foreach (preg_split('/\s*,\s*/', $rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $pid = (int) $part;
        if ($pid > 0) {
            $ids[$pid] = true;
        }
    }
    $ids = array_keys($ids);
    if ($ids === [] || count($ids) > 20) {
        echo json_encode(['ok' => false, 'error' => 'invalid_ids']);
        return;
    }
    if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()
        || !function_exists('numinix_seekmodo_suggest_product_prices')
    ) {
        echo json_encode(['ok' => false, 'error' => 'unavailable']);
        return;
    }
    echo json_encode([
        'ok' => true,
        'currency' => function_exists('numinix_seekmodo_shopper_currency')
            ? numinix_seekmodo_shopper_currency()
            : '',
        'prices' => numinix_seekmodo_suggest_product_prices($ids),
    ], JSON_UNESCAPED_SLASHES);
    return;
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$max = isset($_GET['max']) ? (int)$_GET['max'] : 8;

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode([
        'ok' => false,
        'fallback' => true,
        'reason' => 'too_short',
    ]);
    return;
}
if (mb_strlen($q) > 80) {
    echo json_encode([
        'ok' => false,
        'fallback' => true,
        'reason' => 'too_long',
    ]);
    return;
}

// Auto-load: application_top or ensure_plugin_init should have loaded
// the typeahead helpers from zc_plugins. Legacy fallback kept for
// merchants who still copied libs into catalog includes/functions/.
if (!function_exists('numinix_seekmodo_run_typeahead')) {
    if (function_exists('numinix_seekmodo_ensure_plugin_init')) {
        numinix_seekmodo_ensure_plugin_init();
    }
    $libDir = (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : __DIR__ . '/')
        . 'includes/functions/';
    if (is_file($libDir . 'numinix_seekmodo_search_lib.php')) {
        require_once $libDir . 'numinix_seekmodo_search_lib.php';
    }
    if (is_file($libDir . 'numinix_seekmodo_typeahead_lib.php')) {
        require_once $libDir . 'numinix_seekmodo_typeahead_lib.php';
    }
}

if (!function_exists('numinix_seekmodo_run_typeahead')) {
    // Connector files missing — return fallback so the storefront
    // renders its own dropdown.
    echo json_encode([
        'ok' => false,
        'fallback' => true,
        'reason' => 'connector_unavailable',
    ]);
    return;
}

$envelope = numinix_seekmodo_run_typeahead($q, $max);
if ($envelope === null) {
    // off / shadow / circuit-open / gateway failure — caller falls back
    // to the storefront's own typeahead.
    echo json_encode([
        'ok' => false,
        'fallback' => true,
        'reason' => 'gateway_null',
    ]);
    return;
}

$products = $envelope['items'] ?? [];
$keywords = $envelope['keywords'] ?? [];
$categories = $envelope['categories'] ?? [];
$total = $envelope['total'] ?? count(is_array($products) ? $products : []);
// Exact model/sku SERP pins return 0 hits when the SKU is absent. Do
// not leave prior-search keywords promoting a dead SERP (AKS 1.7.6).
if (
    function_exists('_numinix_seekmodo_looks_like_exact_part_token')
    && _numinix_seekmodo_looks_like_exact_part_token(trim((string) $q))
    && is_array($products)
    && $products === []
) {
    $keywords = [];
    $total = 0;
}

echo json_encode([
    'ok' => true,
    'q' => $envelope['q'] ?? $q,
    'keywords' => $keywords,
    'products' => $products,
    'categories' => $categories,
    'total' => $total,
], JSON_UNESCAPED_SLASHES);
