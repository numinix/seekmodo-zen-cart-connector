<?php
/**
 * Regression test for `_numinix_seekmodo_build_search_payload()` and
 * the two shopper-context helpers added in connector v1.0.2 (Sprint
 * 1, PR 1 — §0.6 P0-1 / P0-3 in PROJECT_PLAN.md).
 *
 * Self-contained — no PHPUnit dependency. Mirrors test_client_modes.php.
 * Runs as:
 *
 *     php services/zen-cart-connector/tests/test_search_payload.php
 *
 * Exits 0 on pass, non-zero on fail.
 *
 * Why: the gateway's `SearchTool` skips bot-check classification when
 * any of `ip`, `ua`, `session_id` is empty. Pre-1.0.2 the connector
 * never populated those, so 0/64296 search rows were classified as
 * bots. This test pins the contract — every future build must keep
 * shipping a non-empty triple in the storefront search path.
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.1/catalog/includes/functions/numinix_seekmodo_search_lib.php';

/**
 * Reset $_SERVER between cases so prior fixtures don't leak. The
 * connector helpers read keys lazily via isset() so missing keys
 * exercise the empty-string fallback path.
 */
function resetServerSuperglobal(): void
{
    foreach ([
        'HTTP_USER_AGENT',
        'HTTP_REFERER',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ] as $k) {
        unset($_SERVER[$k]);
    }
    $_GET = [];
}

/** @param mixed $expected @param mixed $actual */
function assertEq(string $label, $expected, $actual, array &$errors, int &$passed): void
{
    if ($expected === $actual) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $msg = "  FAIL {$label}: expected " . var_export($expected, true)
        . ", got " . var_export($actual, true);
    $errors[] = $msg;
    echo $msg . "\n";
}

function assertTruthy(string $label, bool $cond, array &$errors, int &$passed): void
{
    if ($cond) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $errors[] = "  FAIL {$label}";
    echo "  FAIL {$label}\n";
}

// =================================================================
// Case 1. _numinix_seekmodo_client_ip — proxy-header precedence
// =================================================================
echo "Case 1. client_ip header precedence\n";

// 1a. CF-Connecting-IP wins over XFF / REMOTE_ADDR.
resetServerSuperglobal();
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.45';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10, 10.0.0.1';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';
assertEq('cf_connecting_ip_wins', '203.0.113.45', _numinix_seekmodo_client_ip(), $errors, $passed);

// 1b. XFF wins over REMOTE_ADDR when CF header is absent. Leftmost
//     entry of the chain (the originator).
resetServerSuperglobal();
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10, 10.0.0.1';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';
assertEq('xff_wins_when_cf_absent', '198.51.100.10', _numinix_seekmodo_client_ip(), $errors, $passed);

// 1c. REMOTE_ADDR is the last-resort fallback (no proxy in front).
resetServerSuperglobal();
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';
assertEq('remote_addr_fallback', '10.0.0.7', _numinix_seekmodo_client_ip(), $errors, $passed);

// 1d. No headers at all (CLI / cron) -> empty string. Gateway's
//     bot-check correctly skips classification when ip is empty.
resetServerSuperglobal();
assertEq('no_headers_returns_empty', '', _numinix_seekmodo_client_ip(), $errors, $passed);

// 1e. Capped at 64 chars so a malformed header can't bloat the
//     payload. Synthesise a 200-char garbage value.
resetServerSuperglobal();
$_SERVER['HTTP_CF_CONNECTING_IP'] = str_repeat('a', 200);
$ip = _numinix_seekmodo_client_ip();
assertEq('ip_capped_at_64', 64, strlen($ip), $errors, $passed);

// =================================================================
// Case 2. _numinix_seekmodo_session_id — fallback chain
// =================================================================
echo "Case 2. session_id fallback chain\n";

// 2a. Hash fallback when no session active and no Redline log
//     helper. Same UA/IP -> same hash (deterministic, prefixed `h_`
//     so the gateway can spot the fallback in telemetry).
resetServerSuperglobal();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
$_SERVER['REMOTE_ADDR'] = '203.0.113.45';
$sid1 = _numinix_seekmodo_session_id();
$sid2 = _numinix_seekmodo_session_id();
assertEq('hash_session_deterministic', $sid1, $sid2, $errors, $passed);
assertTruthy('hash_session_prefixed', str_starts_with($sid1, 'h_'), $errors, $passed);

// 2b. Different UA produces a different hash.
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Different';
$sid3 = _numinix_seekmodo_session_id();
assertTruthy('hash_session_diverges_on_ua', $sid1 !== $sid3, $errors, $passed);

// 2c. No UA, no IP -> empty (gateway bot-check correctly skips).
resetServerSuperglobal();
assertEq('no_signals_returns_empty', '', _numinix_seekmodo_session_id(), $errors, $passed);

// 2d. Capped at 64 chars even when the upstream session id is huge
//     (defensive — Zen Cart's session id is typically 32 hex chars
//     but plugins can override the handler).
resetServerSuperglobal();
$_SERVER['HTTP_USER_AGENT'] = str_repeat('U', 600);
$_SERVER['REMOTE_ADDR'] = '203.0.113.45';
assertTruthy('hash_session_capped', strlen(_numinix_seekmodo_session_id()) <= 64, $errors, $passed);

// =================================================================
// Case 3. _numinix_seekmodo_build_search_payload — wire shape
// =================================================================
echo "Case 3. build_search_payload shopper-context fields\n";

// 3a. All four fields populated when the shopper has a real
//     browser request (CF-front, UA + Referer present).
resetServerSuperglobal();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Safari/605.1.15';
$_SERVER['HTTP_REFERER'] = 'https://www.redlinestands.com/catalog/index.php?main_page=index';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.45';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

$payload = _numinix_seekmodo_build_search_payload(['keyword' => 'lift table']);
assertEq('payload_q_set', 'lift table', $payload['q'], $errors, $passed);
assertEq('payload_ip_from_cf', '203.0.113.45', $payload['ip'], $errors, $passed);
assertEq(
    'payload_ua_pass_through',
    'Mozilla/5.0 (Macintosh) Safari/605.1.15',
    $payload['ua'],
    $errors,
    $passed
);
assertEq(
    'payload_referer_pass_through',
    'https://www.redlinestands.com/catalog/index.php?main_page=index',
    $payload['referer'],
    $errors,
    $passed
);
assertTruthy('payload_session_id_non_empty', !empty($payload['session_id']), $errors, $passed);

// 3b. UA cap at 512, Referer cap at 255 (telemetry column lengths).
resetServerSuperglobal();
$_SERVER['HTTP_USER_AGENT'] = str_repeat('U', 600);
$_SERVER['HTTP_REFERER'] = 'https://example.com/' . str_repeat('a', 400);
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

$payload = _numinix_seekmodo_build_search_payload(['keyword' => 'x']);
assertEq('payload_ua_capped_at_512', 512, strlen($payload['ua']), $errors, $passed);
assertEq('payload_referer_capped_at_255', 255, strlen($payload['referer']), $errors, $passed);

// 3c. Referer is omitted (key absent, not empty) when the request
//     carries no Referer header — keeps the body small for the
//     ~10% of organic visits that lack one.
resetServerSuperglobal();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

$payload = _numinix_seekmodo_build_search_payload(['keyword' => 'x']);
assertTruthy('payload_referer_omitted_when_unset', !array_key_exists('referer', $payload), $errors, $passed);
assertTruthy('payload_ua_present_when_set', array_key_exists('ua', $payload), $errors, $passed);
assertTruthy('payload_ip_present_when_set', array_key_exists('ip', $payload), $errors, $passed);

// 3d. Empty keyword maps to '*' (storefront browse / facet-only),
//     and shopper-context still attaches.
resetServerSuperglobal();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

$payload = _numinix_seekmodo_build_search_payload(['keyword' => '']);
assertEq('payload_empty_keyword_maps_to_star', '*', $payload['q'], $errors, $passed);
assertTruthy('payload_session_set_on_browse', $payload['session_id'] !== '', $errors, $passed);

// 3e. Bot-check unblock — the post-fix payload puts non-empty values
//     in all three classifier inputs (ip, ua, session_id) for a
//     normal storefront request. This is the literal acceptance
//     gate for §0.6 P0-1.
resetServerSuperglobal();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.45';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

$payload = _numinix_seekmodo_build_search_payload(['keyword' => 'x']);
assertTruthy(
    'payload_unblocks_botcheck_classifier',
    !empty($payload['session_id'])
        && !empty($payload['ua'])
        && !empty($payload['ip']),
    $errors,
    $passed
);

// =================================================================
// Report.
// =================================================================
echo "\n";
if ($errors === []) {
    echo "test_search_payload: {$passed} assertion(s) passed.\n";
    exit(0);
}
echo "test_search_payload: " . count($errors) . " failure(s), {$passed} passed.\n";
exit(1);
