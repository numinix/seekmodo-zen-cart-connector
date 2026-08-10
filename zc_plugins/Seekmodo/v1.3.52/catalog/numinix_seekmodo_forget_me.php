<?php
/**
 * Sprint 5 PR 6 (search-features-plan) — shopper "Forget me" deeplink.
 *
 * Shoppers (or the merchant's privacy form on their behalf) hit this
 * URL on the storefront, e.g. via a link from the privacy policy
 * page: `https://example.com/numinix_seekmodo_forget_me.php`. We
 *
 *   1. Resolve the current shopper's identity exactly the same way
 *      the search / events libs do (customer_id, sm_pid cookie,
 *      session_id) — whichever resolves wins.
 *   2. Fire the gateway's `tenant.shopper.forget` admin tool via the
 *      HMAC-signed Client, which deletes / redacts every row tied
 *      to that shopper in `numinix_telemetry_search_events` and
 *      `numinix_reco_pairs`, then writes the audit row to
 *      `numinix_mcp_shopper_erasures`.
 *   3. Clear the sm_pid cookie locally so the shopper doesn't get
 *      a fresh personalized history minted on their very next page
 *      view.
 *
 * Why this lives on the storefront (and not on admin.seekmodo.com):
 * the destructive call is gated by the tenant's own HMAC secret
 * via the connector — so the merchant's existing storefront auth
 * (customer login, privacy-form CSRF, captcha, etc.) is what
 * authorizes the erasure. Our admin.seekmodo.com UI also exposes a
 * `tenant.shopper.forget` form for operator-side requests; this
 * deeplink is the shopper-facing path.
 *
 * No new metering. tenant.shopper.forget is registered as a
 * non-metered admin tool — same posture as the other tenant.*
 * admin tools.
 *
 * Response shape:
 *   { ok: true,
 *     shopper_id_hash: '…',
 *     shopper_source: 'identified'|'cookie'|'session',
 *     rows_affected: { search_deleted, search_redacted, reco_deleted },
 *     audit_id: int|null }
 *  or
 *   { ok: false, reason: 'not_paired|no_shopper_id|gateway_failure' }
 *
 * Method: accepts GET (for the deeplink) AND POST (for forms that
 * want CSRF protection). Same payload either way; we read from
 * either superglobal.
 */

declare(strict_types=1);

// v1.0.22: see numinix_seekmodo_suggest.php for the why — resolve
// `includes/application_top.php` via __DIR__ so the shim works whether
// it's served from the live catalog root or the plugin's versioned
// dir under `/catalog/zc_plugins/Seekmodo/v<version>/catalog/`.
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
$includesDir = dirname((string) realpath($applicationTopPath));
$catalogRoot = dirname($includesDir);
if ($catalogRoot !== '' && is_dir($catalogRoot)) {
    chdir($catalogRoot);
}
require $applicationTopPath;

// v1.3.24 — ensure zc_plugins catalog init when auto_loaders did not merge.
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
header('X-Robots-Tag: noindex, nofollow');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'reason' => 'method_not_allowed']);
    return;
}

// Bail early on hosts the tenant has explicitly locked out. Matches
// the search lib's posture so a dev / staging clone running this
// connector can't accidentally call the gateway.
if (
    function_exists('numinix_seekmodo_is_locked_out')
    && numinix_seekmodo_is_locked_out()
) {
    echo json_encode(['ok' => false, 'reason' => 'locked_out']);
    return;
}

// Resolve the shopper id following the same precedence the gateway
// does so we forget the *same* identity that personalization
// recorded — never one without the other.
//
//   1. caller-supplied (explicit; rare, mostly for support paths)
//   2. logged-in customer id
//   3. sm_pid cookie
//   4. session id (last-resort ephemeral)
$shopperId = '';
foreach (['shopper_id', 'sid', 'id'] as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') {
        $shopperId = (string)$_GET[$k];
        break;
    }
    if ($method === 'POST' && isset($_POST[$k]) && $_POST[$k] !== '') {
        $shopperId = (string)$_POST[$k];
        break;
    }
}
if ($shopperId === '') {
    $cid = function_exists('numinix_seekmodo_current_customer_id')
        ? numinix_seekmodo_current_customer_id()
        : null;
    if ($cid !== null) {
        $shopperId = (string)$cid;
    }
}
if ($shopperId === '' && function_exists('numinix_seekmodo_resolve_pid')) {
    $pid = numinix_seekmodo_resolve_pid();
    if ($pid !== null && $pid !== '') {
        $shopperId = $pid;
    }
}
if ($shopperId === '' && function_exists('_numinix_seekmodo_session_id')) {
    $sid = _numinix_seekmodo_session_id();
    if ($sid !== '') {
        $shopperId = $sid;
    }
}

if ($shopperId === '') {
    echo json_encode(['ok' => false, 'reason' => 'no_shopper_id']);
    return;
}

// Cap to the gateway's max input length so a malformed cookie /
// custom header can't make us PRO a 1MB body.
$shopperId = substr($shopperId, 0, 256);

// Fire the gateway tool through the connector's signed Client. The
// Client transparently signs the body with the tenant HMAC secret,
// so the shopper's browser never sees the secret and the gateway
// can verify the call originated from this paired storefront.
//
// We use the procedural numinix_seekmodo_admin_tool helper when
// available; older v1.0.x connectors didn't have it, so we degrade
// to the class-based Client directly.
$resp = null;
try {
    if (!class_exists(\Numinix\Seekmodo\Client::class, true)) {
        echo json_encode(['ok' => false, 'reason' => 'connector_unavailable']);
        return;
    }
    $client = \Numinix\Seekmodo\Client::fromConfiguration();
    if (!$client->isEnabled()) {
        echo json_encode(['ok' => false, 'reason' => 'not_paired']);
        return;
    }
    // tenant.shopper.forget lives behind the gateway's admin route
    // (/v1/admin/tenant.shopper.forget). The HMAC envelope used by
    // the connector's per-tenant calls (/v1/search etc.) doesn't
    // hit the admin path — admin requests use the operator key,
    // which the connector doesn't (and shouldn't) have. We instead
    // route this through the regular MCP tool surface
    // (/v1/mcp/tools/call), which DOES accept HMAC + per-tenant
    // tokens and dispatches to the same TenantShopperForgetTool
    // server-side. This keeps the auth model "the storefront is
    // erasing its OWN data" — no operator key needed.
    $resp = $client->callTool('tenant.shopper.forget', [
        'shopper_id' => $shopperId,
        'actor_kind' => 'connector',
        'reason'     => isset($_REQUEST['reason'])
            ? substr((string)$_REQUEST['reason'], 0, 500)
            : 'shopper_self_serve_forget_me_deeplink',
    ]);
} catch (\Throwable $e) {
    error_log('[seekmodo-forget-me] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'reason' => 'gateway_failure']);
    return;
}

// Clear the cookie regardless of the gateway response — if the
// gateway is unreachable we still want the shopper's browser to
// stop carrying the id around. Worst case is the gateway-side
// erasure has to be retried (operator can do that from
// admin.seekmodo.com).
if (function_exists('numinix_seekmodo_clear_pid')) {
    numinix_seekmodo_clear_pid();
}

if (!is_array($resp)) {
    echo json_encode(['ok' => false, 'reason' => 'gateway_failure']);
    return;
}

echo json_encode(array_merge(['ok' => true], $resp), JSON_UNESCAPED_SLASHES);
