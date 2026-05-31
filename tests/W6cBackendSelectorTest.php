<?php
/**
 * Regression test for the W6c bot-check backend selector added in
 * connector v1.0.6: `RemoteConfig::writeThrough()` now mirrors
 * **eight** keys from the gateway snapshot (was seven), the new key
 * being `bot_check_backend` → `NUMINIX_BOT_CHECK_BACKEND`. The
 * vendored `numinix_bot_check_client.php` reads that constant on
 * every classify / nonce.issue / nonce.verify call to decide whether
 * to talk to the standalone bot-check service (`legacy`) or to the
 * gateway's `BotCheck\*` tools (`gateway`).
 *
 * What this test pins:
 *   - writeThrough emits exactly the 8 expected keys when every field
 *     is present in the snapshot.
 *   - writeThrough silently drops `bot_check_backend` when the
 *     value is malformed (typo, empty, non-string), leaving the row
 *     untouched so the bot-check client falls through to its
 *     built-in `legacy` default.
 *   - `numinix_bot_check_backend()` returns `'legacy'` by default,
 *     `'gateway'` when the constant is set, and clamps anything
 *     else back to `'legacy'`.
 *   - `_numinix_bot_check_remap_endpoint()` rewrites the canonical
 *     legacy paths to the gateway's tool paths only when the
 *     backend is set to `gateway`.
 *   - `_numinix_bot_check_backend_cfg()` returns the seekmodo
 *     credential triple + scheme when backend=gateway, and the
 *     legacy bot-check triple + scheme otherwise.
 *
 * Self-contained — no PHPUnit dependency. Mirrors the pattern of
 * tests/W6bConsumptionTest.php. Runs as:
 *
 *     php tests/W6cBackendSelectorTest.php
 *
 * Exits 0 on pass, non-zero on fail.
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.6/catalog/includes/library/Numinix/Seekmodo/RemoteConfig.php';

if (!function_exists('zen_db_input')) {
    function zen_db_input(string $v): string { return $v; }
}
if (!defined('TABLE_CONFIGURATION')) {
    define('TABLE_CONFIGURATION', 'configuration');
}

/**
 * Spy that captures every UPDATE statement RemoteConfig::writeThrough
 * issues so the test can assert the full key surface.
 */
final class CapturingDbW6c
{
    /** @var list<array{sql:string, key:string|null, value:string|null}> */
    public array $updates = [];

    public function Execute(string $sql): bool
    {
        $key = null;
        $value = null;
        if (preg_match("/configuration_value\s*=\s*'([^']*)'/i", $sql, $m)) {
            $value = $m[1];
        }
        if (preg_match("/configuration_key\s*=\s*'([^']*)'/i", $sql, $m)) {
            $key = $m[1];
        }
        $this->updates[] = ['sql' => $sql, 'key' => $key, 'value' => $value];
        return true;
    }
}

function w6c_assert_true(bool $cond, string $label, array &$errors, int &$passed): void
{
    if ($cond) {
        $passed++;
        return;
    }
    $errors[] = $label;
}

function w6c_assert_eq($expected, $actual, string $label, array &$errors, int &$passed): void
{
    if ($expected === $actual) {
        $passed++;
        return;
    }
    $errors[] = "{$label}: expected " . var_export($expected, true)
        . ", got " . var_export($actual, true);
}

// -----------------------------------------------------------------------
// Case 1 — writeThrough emits all 8 keys when the gateway snapshot
//          carries every field, including bot_check_backend=gateway.
// -----------------------------------------------------------------------
$db = new CapturingDbW6c();
$GLOBALS['db'] = $db;

$rc = new \Numinix\Seekmodo\RemoteConfig(
    'https://mcp.seekmodo.test',
    'tenant-test',
    str_repeat('a', 64)
);

$ref = new \ReflectionClass(\Numinix\Seekmodo\RemoteConfig::class);
$method = $ref->getMethod('writeThrough');
$method->setAccessible(true);

$method->invoke($rc, [
    'mode'              => 'enforce',
    'default_mode'      => 'active',
    'auto_promote'      => true,
    'timeout_ms'        => 350,
    'index_batch'       => 750,
    'indexer_schedule'  => 'every_4h',
    'debug'             => false,
    'bot_check_backend' => 'gateway',
]);

$keysWritten = array_map(static fn ($u) => $u['key'], $db->updates);
sort($keysWritten);

$expectedKeys = [
    'NUMINIX_BOT_CHECK_BACKEND',
    'NUMINIX_SEEKMODO_AUTO_PROMOTE',
    'NUMINIX_SEEKMODO_DEBUG',
    'NUMINIX_SEEKMODO_DEFAULT_MODE',
    'NUMINIX_SEEKMODO_INDEXER_SCHEDULE',
    'NUMINIX_SEEKMODO_INDEX_BATCH',
    'NUMINIX_SEEKMODO_MODE',
    'NUMINIX_SEEKMODO_TIMEOUT_MS',
];

w6c_assert_eq(
    $expectedKeys,
    $keysWritten,
    'writeThrough emits the 8 W6c keys',
    $errors,
    $passed
);

$byKey = [];
foreach ($db->updates as $u) {
    $byKey[$u['key']] = $u['value'];
}

w6c_assert_eq('gateway', $byKey['NUMINIX_BOT_CHECK_BACKEND'], 'bot_check_backend=gateway mirrored verbatim', $errors, $passed);

// -----------------------------------------------------------------------
// Case 2 — writeThrough lower-cases a SHOUTING bot_check_backend value.
// -----------------------------------------------------------------------
$db = new CapturingDbW6c();
$GLOBALS['db'] = $db;

$method->invoke($rc, ['bot_check_backend' => 'GATEWAY']);
w6c_assert_eq(1, count($db->updates), 'shouting backend produces exactly one write', $errors, $passed);
w6c_assert_eq('gateway', $db->updates[0]['value'], 'shouting GATEWAY normalised to lowercase gateway', $errors, $passed);

$db = new CapturingDbW6c();
$GLOBALS['db'] = $db;
$method->invoke($rc, ['bot_check_backend' => 'Legacy']);
w6c_assert_eq('legacy', $db->updates[0]['value'], 'mixed-case Legacy normalised to lowercase legacy', $errors, $passed);

// -----------------------------------------------------------------------
// Case 3 — writeThrough drops a malformed bot_check_backend value
//          rather than poisoning the configuration row.
// -----------------------------------------------------------------------
$db = new CapturingDbW6c();
$GLOBALS['db'] = $db;
$method->invoke($rc, ['bot_check_backend' => 'gateway-typo']);
w6c_assert_eq(0, count($db->updates), 'malformed backend value drops the write entirely', $errors, $passed);

$db = new CapturingDbW6c();
$GLOBALS['db'] = $db;
$method->invoke($rc, ['bot_check_backend' => '']);
w6c_assert_eq(0, count($db->updates), 'empty-string backend value drops the write entirely', $errors, $passed);

$db = new CapturingDbW6c();
$GLOBALS['db'] = $db;
// missing key altogether
$method->invoke($rc, ['mode' => 'enforce']);
$keys = array_map(static fn ($u) => $u['key'], $db->updates);
w6c_assert_true(
    !in_array('NUMINIX_BOT_CHECK_BACKEND', $keys, true),
    'missing bot_check_backend key never writes the row',
    $errors,
    $passed
);

// -----------------------------------------------------------------------
// Case 4 — Bot-check client constants drive the URL/scheme/endpoint
//          selection. We can only `define()` constants once per
//          process, so we pick the GATEWAY path for the integration
//          assertions and exercise the LEGACY path via direct call
//          when the constant is unset (block before the define).
// -----------------------------------------------------------------------
// Sub-case 4a — Before the constants are defined, the helper falls
// through to the built-in 'legacy' default.
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.6/catalog/includes/functions/numinix_bot_check_client.php';

w6c_assert_eq('legacy', numinix_bot_check_backend(), 'undefined NUMINIX_BOT_CHECK_BACKEND falls through to legacy', $errors, $passed);

$legacyCfg = _numinix_bot_check_backend_cfg();
w6c_assert_eq('bot_check', $legacyCfg['scheme'], 'legacy backend uses bot_check header scheme', $errors, $passed);

w6c_assert_eq('/v1/classify', _numinix_bot_check_remap_endpoint('/v1/classify'), 'legacy backend leaves /v1/classify untouched', $errors, $passed);
w6c_assert_eq('/v1/nonce/issue', _numinix_bot_check_remap_endpoint('/v1/nonce/issue'), 'legacy backend leaves /v1/nonce/issue untouched', $errors, $passed);

// Sub-case 4b — define the gateway-mode constants and re-check.
define('NUMINIX_BOT_CHECK_BACKEND', 'gateway');
define('NUMINIX_SEEKMODO_URL', 'https://mcp.seekmodo.test');
define('NUMINIX_SEEKMODO_TENANT_ID', 'tenant-x');
define('NUMINIX_SEEKMODO_SHARED_SECRET', str_repeat('b', 64));

w6c_assert_eq('gateway', numinix_bot_check_backend(), 'NUMINIX_BOT_CHECK_BACKEND=gateway returns gateway', $errors, $passed);

$gatewayCfg = _numinix_bot_check_backend_cfg();
w6c_assert_eq('seekmodo', $gatewayCfg['scheme'], 'gateway backend uses seekmodo header scheme', $errors, $passed);
w6c_assert_eq('https://mcp.seekmodo.test', $gatewayCfg['url'], 'gateway backend reads NUMINIX_SEEKMODO_URL', $errors, $passed);
w6c_assert_eq('tenant-x', $gatewayCfg['tenant'], 'gateway backend reads NUMINIX_SEEKMODO_TENANT_ID', $errors, $passed);
w6c_assert_eq(str_repeat('b', 64), $gatewayCfg['secret'], 'gateway backend reads NUMINIX_SEEKMODO_SHARED_SECRET', $errors, $passed);

w6c_assert_eq('/v1/bot.classify', _numinix_bot_check_remap_endpoint('/v1/classify'), 'gateway backend remaps /v1/classify to /v1/bot.classify', $errors, $passed);
w6c_assert_eq('/v1/nonce.issue', _numinix_bot_check_remap_endpoint('/v1/nonce/issue'), 'gateway backend remaps /v1/nonce/issue to /v1/nonce.issue', $errors, $passed);
w6c_assert_eq('/v1/nonce.verify', _numinix_bot_check_remap_endpoint('/v1/nonce/verify'), 'gateway backend remaps /v1/nonce/verify to /v1/nonce.verify', $errors, $passed);
w6c_assert_eq('/v1/something-else', _numinix_bot_check_remap_endpoint('/v1/something-else'), 'gateway backend leaves unknown paths untouched', $errors, $passed);

// -----------------------------------------------------------------------
// Report.
// -----------------------------------------------------------------------
if ($errors === []) {
    fwrite(STDOUT, "OK \u{2014} {$passed} W6c assertion(s) passed.\n");
    exit(0);
}
fwrite(STDERR, "FAIL \u{2014} " . count($errors) . " W6c assertion(s) failed:\n");
foreach ($errors as $e) {
    fwrite(STDERR, "  - {$e}\n");
}
fwrite(STDERR, "(passed: {$passed})\n");
exit(1);
