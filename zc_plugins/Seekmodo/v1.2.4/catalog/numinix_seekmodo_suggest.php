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
// The shim itself ignores $_POST entirely -- the action is read from
// $_GET['action'], the request body is unread. So we can safely
// downgrade the request to GET before ZC's CSRF gate sees it. We
// scope the patch as narrowly as possible: only the browser-token
// action, only when the method is POST, so existing GET clients and
// any future POST routes are untouched.
if (
    (($_GET['action'] ?? '') === 'browser-token')
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
if (($_GET['action'] ?? '') === 'browser-token') {
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

// Auto-load: application_top includes the auto-loaded init files,
// so the search lib + typeahead lib are already on disk. Defensive
// require keeps this script runnable on environments where someone
// disabled init_numinix_seekmodo.php.
if (!function_exists('numinix_seekmodo_run_typeahead')) {
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

echo json_encode([
    'ok' => true,
    'q' => $envelope['q'] ?? $q,
    'keywords' => $envelope['keywords'] ?? [],
    'products' => $envelope['items'] ?? [],
    'categories' => $envelope['categories'] ?? [],
    'total' => $envelope['total'] ?? count($envelope['items'] ?? []),
], JSON_UNESCAPED_SLASHES);
