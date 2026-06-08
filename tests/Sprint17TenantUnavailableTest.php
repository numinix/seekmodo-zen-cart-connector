<?php
/**
 * Regression test for the v1.0.17 expanded tenant-unavailable 4xx
 * graceful-degradation classification. Pins:
 *
 *   1. `Client::TENANT_UNAVAILABLE_ERROR_CODES` exposes every
 *      gateway lifecycle code the AKS connector v1.3 recognises,
 *      plus the two legacy codes Zen Cart connector v1.0.0+ has
 *      always handled.
 *
 *   2. `Client::classifyTenantUnavailable()` returns the matched
 *      code (lowercased) when the (status, body) tuple identifies
 *      the tenant itself as unavailable:
 *        - 403 + `tenant_paused`           → `'tenant_paused'`
 *        - 403 + `tenant_suspended`        → `'tenant_suspended'`
 *        - 403 + `tenant_disabled`         → `'tenant_disabled'`
 *        - 404 + `tenant_not_found`        → `'tenant_not_found'`
 *        - 404 + `tenant_unknown`          → `'tenant_unknown'`
 *        - 403 + `subscription_cancelled`  → legacy code preserved
 *        - 403 + `unknown_tenant`          → legacy code preserved
 *
 *   3. Returns `null` (graceful-degradation does NOT fire) for:
 *        - 403 + `signature_mismatch`      (real client bug)
 *        - 403 + `rate_limited`            (real client bug)
 *        - 401 + `tenant_paused`           (wrong status)
 *        - 500 + `tenant_paused`           (5xx is a different code path)
 *        - 403 + non-JSON body
 *        - 403 + JSON body w/ no `error` field
 *        - 403 + empty body
 *        - 403 + 5 KB+ body                (DoS guard)
 *        - 403 + JSON body w/ uppercase code (we lowercase before match)
 *
 * Self-contained — no PHPUnit. Mirrors the pattern of
 * tests/W6cBackendSelectorTest.php. Runs as:
 *
 *     php tests/Sprint17TenantUnavailableTest.php
 *
 * Exits 0 on pass, non-zero on fail.
 */

declare(strict_types=1);

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.17/catalog/includes/library/Numinix/Seekmodo/CircuitBreakerStore.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.17/catalog/includes/library/Numinix/Seekmodo/ApcuCircuitBreakerStore.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.17/catalog/includes/library/Numinix/Seekmodo/Client.php';

use Numinix\Seekmodo\Client;

$errors = [];
$passed = 0;

function s17_assert_eq($expected, $actual, string $label, array &$errors, int &$passed): void
{
    if ($expected === $actual) {
        $passed++;
        return;
    }
    $errors[] = "{$label}: expected " . var_export($expected, true)
        . ", got " . var_export($actual, true);
}

function s17_assert_true(bool $cond, string $label, array &$errors, int &$passed): void
{
    if ($cond) {
        $passed++;
        return;
    }
    $errors[] = $label;
}

// -----------------------------------------------------------------------
// Section 1 — TENANT_UNAVAILABLE_ERROR_CODES surface.
// -----------------------------------------------------------------------
$expectedCodes = [
    'tenant_paused',
    'tenant_not_found',
    'tenant_unknown',
    'tenant_suspended',
    'tenant_disabled',
    'subscription_cancelled', // legacy
    'unknown_tenant',         // legacy
];
$actualCodes = Client::TENANT_UNAVAILABLE_ERROR_CODES;
sort($expectedCodes);
$sortedActual = $actualCodes;
sort($sortedActual);
s17_assert_eq(
    $expectedCodes,
    $sortedActual,
    'TENANT_UNAVAILABLE_ERROR_CODES surface',
    $errors,
    $passed
);

// -----------------------------------------------------------------------
// Section 2 — Positive matches: every recognised lifecycle code
//             across the right status codes.
// -----------------------------------------------------------------------
$positiveCases = [
    [403, 'tenant_paused'],
    [403, 'tenant_suspended'],
    [403, 'tenant_disabled'],
    [403, 'subscription_cancelled'],
    [403, 'unknown_tenant'],
    [404, 'tenant_not_found'],
    [404, 'tenant_unknown'],
    // tenant_paused on a 404 is still a tenant-lifecycle response
    // even if the gateway today happens to use 403 for it. The
    // peek matches on (status ∈ {403,404} AND code ∈ list); we
    // don't pin an exhaustive (status, code) cross-product to
    // avoid coupling the connector to a specific gateway-side
    // status-code policy that may shift over time.
    [404, 'tenant_paused'],
];
foreach ($positiveCases as [$status, $code]) {
    $body = json_encode(['error' => $code]);
    $matched = Client::classifyTenantUnavailable($status, $body);
    s17_assert_eq(
        $code,
        $matched,
        sprintf('positive: %d + %s', $status, $code),
        $errors,
        $passed
    );
}

// -----------------------------------------------------------------------
// Section 3 — Lowercase normalization. A gateway response that
//             accidentally uppercases the code still matches.
// -----------------------------------------------------------------------
$matched = Client::classifyTenantUnavailable(403, json_encode(['error' => 'TENANT_PAUSED']));
s17_assert_eq('tenant_paused', $matched, 'uppercase code lowercased before match', $errors, $passed);

$matched = Client::classifyTenantUnavailable(403, json_encode(['error' => 'Tenant_Suspended']));
s17_assert_eq('tenant_suspended', $matched, 'mixed-case code lowercased before match', $errors, $passed);

// -----------------------------------------------------------------------
// Section 4 — Negative matches: real client bugs (signature
//             mismatch, rate limited, malformed). These must
//             return null so the operator's admin status page
//             surfaces the real failure instead of silently
//             marking the tenant as paused.
// -----------------------------------------------------------------------
$negativeCases = [
    [403, json_encode(['error' => 'signature_mismatch']), 'signature_mismatch is a real bug'],
    [403, json_encode(['error' => 'rate_limited']),       'rate_limited is a real bug'],
    [403, json_encode(['error' => 'invalid_request']),    'invalid_request is a real bug'],
    [400, json_encode(['error' => 'tenant_paused']),      '400 is not a tenant-lifecycle status'],
    [401, json_encode(['error' => 'tenant_paused']),      '401 is not a tenant-lifecycle status'],
    [500, json_encode(['error' => 'tenant_paused']),      '5xx takes a different code path'],
    [200, json_encode(['error' => 'tenant_paused']),      '2xx is impossible but defensive'],
];
foreach ($negativeCases as [$status, $body, $label]) {
    $matched = Client::classifyTenantUnavailable($status, $body);
    s17_assert_eq(null, $matched, "negative: {$label}", $errors, $passed);
}

// -----------------------------------------------------------------------
// Section 5 — Defensive guards: empty body, non-JSON, non-array,
//             missing error field, oversized body. Must never
//             throw and must return null.
// -----------------------------------------------------------------------
s17_assert_eq(null, Client::classifyTenantUnavailable(403, null),         'null body',           $errors, $passed);
s17_assert_eq(null, Client::classifyTenantUnavailable(403, ''),           'empty body',          $errors, $passed);
s17_assert_eq(null, Client::classifyTenantUnavailable(403, 'not json'),   'non-json body',       $errors, $passed);
s17_assert_eq(null, Client::classifyTenantUnavailable(403, '"a string"'), 'json string body',    $errors, $passed);
s17_assert_eq(null, Client::classifyTenantUnavailable(403, '[1,2,3]'),    'json array body',     $errors, $passed);
s17_assert_eq(null, Client::classifyTenantUnavailable(403, '{}'),         'no error field',      $errors, $passed);
s17_assert_eq(null, Client::classifyTenantUnavailable(403, '{"error":""}'),       'blank error', $errors, $passed);
s17_assert_eq(null, Client::classifyTenantUnavailable(403, '{"error":123}'),      'numeric error', $errors, $passed);
s17_assert_eq(null, Client::classifyTenantUnavailable(403, '{"error":["paused"]}'), 'array error',  $errors, $passed);

// 5 KB body (over the 4096-byte cap)
$bigBody = '{"error":"tenant_paused","filler":"' . str_repeat('x', 5000) . '"}';
s17_assert_eq(null, Client::classifyTenantUnavailable(403, $bigBody), 'oversized body', $errors, $passed);

// -----------------------------------------------------------------------
// Report.
// -----------------------------------------------------------------------
echo "\nSprint17TenantUnavailableTest: $passed passed, " . count($errors) . " failed\n";
foreach ($errors as $err) {
    echo "  FAIL $err\n";
}
exit(empty($errors) ? 0 : 1);
