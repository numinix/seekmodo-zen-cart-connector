<?php
/**
 * Regression test for Numinix\Seekmodo\Client mode handling.
 *
 * Self-contained — no PHPUnit dependency. Runs as:
 *
 *     php services/zen-cart-connector/tests/test_client_modes.php
 *
 * Exits 0 on pass, non-zero on fail. Designed to be CI-friendly (the
 * connector has no formal phpunit suite — Python verifiers in tools/
 * cover the wire-level acceptance, this PHP script covers the SDK-level
 * regression that bit us before the M3 cutover: `MODE=active` was being
 * normalized to `MODE_OFF`, which dead-ended the AutoPromoter FSM.
 *
 * Five cases:
 *
 *   1. MODE=active with NO effective_mode() helper loaded
 *      → Client::mode() === 'active' (literal pass-through)
 *      → Client::isEnabled() === true
 *
 *   2. MODE=active with effective_mode() helper present, returning 'enforce'
 *      → Client::mode() === 'enforce'  (FSM resolution applied)
 *      → Client::isEnabled() === true
 *
 *   3. MODE=active with effective_mode() helper present, returning 'shadow'
 *      → Client::mode() === 'shadow'
 *      → Client::isEnabled() === true
 *
 *   4. MODE=enforce literal
 *      → Client::mode() === 'enforce'
 *      → Client::isEnabled() === true
 *
 *   5. MODE=off literal
 *      → Client::mode() === 'off'
 *      → Client::isEnabled() === false
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.0/catalog/includes/library/Numinix/Seekmodo/CircuitBreakerStore.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.0/catalog/includes/library/Numinix/Seekmodo/ApcuCircuitBreakerStore.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.0/catalog/includes/library/Numinix/Seekmodo/Client.php';

function defineCfg(string $url, string $tenant, string $secret, string $mode): void
{
    foreach (
        [
            'NUMINIX_SEEKMODO_URL' => $url,
            'NUMINIX_SEEKMODO_TENANT_ID' => $tenant,
            'NUMINIX_SEEKMODO_SHARED_SECRET' => $secret,
            'NUMINIX_SEEKMODO_MODE' => $mode,
        ] as $k => $v
    ) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

function assertEq(string $caseName, $expected, $actual, array &$errors, int &$passed): void
{
    if ($expected === $actual) {
        $passed++;
        echo "  PASS $caseName\n";
        return;
    }
    $msg = "  FAIL $caseName: expected " . var_export($expected, true)
        . ", got " . var_export($actual, true);
    $errors[] = $msg;
    echo $msg . "\n";
}

// Helper: clear the client cache between cases by spawning a PHP
// subprocess. Constants can't be redefined in-process so we run each
// case in its own short-lived child.
function runCaseInChild(string $caseId, string $mode, ?string $effectiveModeReturn): array
{
    $payload = [
        'case' => $caseId,
        'mode' => $mode,
        'effective' => $effectiveModeReturn,
    ];
    $b64 = base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(__FILE__) . ' --child=' . escapeshellarg($b64) . ' 2>&1';
    $out = shell_exec($cmd);
    if (!is_string($out) || $out === '') {
        return ['ok' => false, 'mode' => null, 'enabled' => null, 'raw' => '(empty)'];
    }
    $lines = explode("\n", trim($out));
    $line = trim((string)end($lines));
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'mode' => null, 'enabled' => null, 'raw' => $out];
    }
    return $decoded + ['ok' => true, 'raw' => $out];
}

// Child mode — invoked by the parent for each case.
$argvIdx = $_SERVER['argv'] ?? $argv ?? [];
foreach ($argvIdx as $arg) {
    if (str_starts_with($arg, '--child=')) {
        $payload = json_decode((string)base64_decode(substr($arg, 8)), true);
        if (!is_array($payload)) {
            fwrite(STDERR, "child: bad payload\n");
            exit(2);
        }
        defineCfg(
            'https://mcp.example.invalid',
            'tenant_test',
            'a' . str_repeat('b', 63),
            (string)($payload['mode'] ?? 'off')
        );
        if ($payload['effective'] !== null) {
            $effective = (string)$payload['effective'];
            if (!function_exists('numinix_seekmodo_effective_mode')) {
                eval('function numinix_seekmodo_effective_mode(): string { return ' . var_export($effective, true) . '; }');
            }
        }
        $client = \Numinix\Seekmodo\Client::fromConfiguration();
        $resp = [
            'mode' => $client === null ? null : $client->mode(),
            'enabled' => $client === null ? null : $client->isEnabled(),
        ];
        echo json_encode($resp) . "\n";
        exit(0);
    }
}

echo "== Numinix\\Seekmodo\\Client mode regression suite ==\n\n";

// Case 1: active, no helper — must pass through as 'active' + enabled
$r = runCaseInChild('case1', 'active', null);
echo "Case 1: MODE=active, no effective_mode() helper\n";
assertEq('case1.mode', 'active', $r['mode'], $errors, $passed);
assertEq('case1.enabled', true, $r['enabled'], $errors, $passed);

// Case 2: active, helper returns 'enforce' — Client must resolve to enforce
$r = runCaseInChild('case2', 'active', 'enforce');
echo "Case 2: MODE=active, effective_mode()=enforce\n";
assertEq('case2.mode', 'enforce', $r['mode'], $errors, $passed);
assertEq('case2.enabled', true, $r['enabled'], $errors, $passed);

// Case 3: active, helper returns 'shadow' — Client must resolve to shadow
$r = runCaseInChild('case3', 'active', 'shadow');
echo "Case 3: MODE=active, effective_mode()=shadow\n";
assertEq('case3.mode', 'shadow', $r['mode'], $errors, $passed);
assertEq('case3.enabled', true, $r['enabled'], $errors, $passed);

// Case 4: enforce literal — must round-trip
$r = runCaseInChild('case4', 'enforce', null);
echo "Case 4: MODE=enforce literal\n";
assertEq('case4.mode', 'enforce', $r['mode'], $errors, $passed);
assertEq('case4.enabled', true, $r['enabled'], $errors, $passed);

// Case 5: off literal — must disable
$r = runCaseInChild('case5', 'off', null);
echo "Case 5: MODE=off literal\n";
assertEq('case5.mode', 'off', $r['mode'], $errors, $passed);
assertEq('case5.enabled', false, $r['enabled'], $errors, $passed);

echo "\n== Summary ==\n";
echo "  passed: $passed\n";
echo "  failed: " . count($errors) . "\n";
if (!empty($errors)) {
    echo "\nFailures:\n";
    foreach ($errors as $e) {
        echo $e . "\n";
    }
    exit(1);
}
echo "\nAll mode-regression cases passed.\n";
exit(0);
