<?php
/**
 * v1.0.19 — EnvProbe regression test.
 *
 * Locks the shape and severity tiers of the diagnostics map driving
 * the Connect admin page's APCu warning banner + Diagnostics section.
 * The tests deliberately don't invoke `EnvProbe::current()` directly
 * (its return depends on the CI host's actual extensions) — instead
 * they synthesize stub env arrays and pass them to `diagnostics()`
 * to assert the row shape and severity classification logic.
 *
 * Self-contained — no PHPUnit dependency. Mirrors the pattern in
 * tests/W6cBackendSelectorTest.php / W6bConsumptionTest.php.
 *
 *     php tests/V1019EnvProbeTest.php
 *
 * Exits 0 on pass, non-zero on fail.
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.19/catalog/includes/library/Numinix/Seekmodo/EnvProbe.php';

use Numinix\Seekmodo\EnvProbe;

/** @return array<string,mixed> */
function _v1019_env_stub(array $overrides = []): array
{
    return array_replace([
        'php_version'      => '8.1.34',
        'php_sapi'         => 'fpm-fcgi',
        'sodium_loaded'    => true,
        'apcu_loaded'      => true,
        'apcu_extension'   => true,
        'opcache_enabled'  => true,
        'curl_loaded'      => true,
        'openssl_loaded'   => true,
        'mysqli_loaded'    => true,
        'intl_loaded'      => true,
        'json_loaded'      => true,
        'server_time_unix' => 1733835600,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// 1. current() returns the canonical key set on a real host.
// ---------------------------------------------------------------------------
$env = EnvProbe::current();
$expectedKeys = [
    'php_version', 'php_sapi', 'sodium_loaded', 'apcu_loaded',
    'apcu_extension', 'opcache_enabled', 'curl_loaded',
    'openssl_loaded', 'mysqli_loaded', 'intl_loaded',
    'json_loaded', 'server_time_unix',
];
foreach ($expectedKeys as $k) {
    if (!array_key_exists($k, $env)) {
        $errors[] = "current() missing key: {$k}";
        continue;
    }
    $passed++;
}
if (!is_string($env['php_version']) || $env['php_version'] === '') {
    $errors[] = 'current()[php_version] should be a non-empty string';
} else {
    $passed++;
}
if (!is_int($env['server_time_unix']) || $env['server_time_unix'] < 1700000000) {
    $errors[] = 'current()[server_time_unix] should be a sane unix timestamp';
} else {
    $passed++;
}

// ---------------------------------------------------------------------------
// 2. diagnostics() row shape — every row must have label / value /
//    severity / hint, severity must be one of the four tiers.
// ---------------------------------------------------------------------------
$rows = EnvProbe::diagnostics(_v1019_env_stub());
if (count($rows) < 7) {
    $errors[] = 'diagnostics() should return at least 7 rows; got ' . count($rows);
} else {
    $passed++;
}
$validSeverities = [
    EnvProbe::SEV_OK, EnvProbe::SEV_WARN,
    EnvProbe::SEV_FAIL, EnvProbe::SEV_INFO,
];
foreach ($rows as $i => $row) {
    foreach (['label', 'value', 'severity', 'hint'] as $k) {
        if (!array_key_exists($k, $row)) {
            $errors[] = "diagnostics()[{$i}] missing key: {$k}";
            continue 2;
        }
    }
    if (!in_array($row['severity'], $validSeverities, true)) {
        $errors[] = "diagnostics()[{$i}] bad severity: {$row['severity']}";
    } else {
        $passed++;
    }
}

// ---------------------------------------------------------------------------
// 3. Severity classification — APCu warn vs ok, sodium fail vs ok.
//    These are the two rows the Connect page primarily depends on.
// ---------------------------------------------------------------------------
function _v1019_find(array $rows, string $labelPrefix): ?array
{
    foreach ($rows as $row) {
        if (strncmp($row['label'], $labelPrefix, strlen($labelPrefix)) === 0) {
            return $row;
        }
    }
    return null;
}

// All-good baseline: every check should be OK or INFO.
$rowsOk = EnvProbe::diagnostics(_v1019_env_stub());
foreach ($rowsOk as $row) {
    if ($row['severity'] === EnvProbe::SEV_FAIL || $row['severity'] === EnvProbe::SEV_WARN) {
        $errors[] = "all-good baseline: row '{$row['label']}' should be OK/INFO; got {$row['severity']}";
    } else {
        $passed++;
    }
}

// APCu missing: should warn (not fail), and hint should mention `pecl-apcu`.
$rowsNoApcu = EnvProbe::diagnostics(_v1019_env_stub([
    'apcu_loaded'    => false,
    'apcu_extension' => false,
]));
$apcuRow = _v1019_find($rowsNoApcu, 'APCu');
if ($apcuRow === null) {
    $errors[] = 'APCu row missing from diagnostics output';
} else {
    if ($apcuRow['severity'] !== EnvProbe::SEV_WARN) {
        $errors[] = "APCu missing should be SEV_WARN; got {$apcuRow['severity']}";
    } else {
        $passed++;
    }
    if (strpos($apcuRow['hint'], 'pecl-apcu') === false) {
        $errors[] = "APCu hint should mention pecl-apcu install package; got: {$apcuRow['hint']}";
    } else {
        $passed++;
    }
    if ($apcuRow['value'] !== 'missing') {
        $errors[] = "APCu missing value should read 'missing'; got: {$apcuRow['value']}";
    } else {
        $passed++;
    }
}

// APCu loaded but disabled (apc.enabled=0): still SEV_WARN, but
// value should reflect the disabled-not-missing state so the merchant
// doesn't think they need to re-install.
$rowsApcuDisabled = EnvProbe::diagnostics(_v1019_env_stub([
    'apcu_loaded'    => false,
    'apcu_extension' => true,
]));
$apcuDisabledRow = _v1019_find($rowsApcuDisabled, 'APCu');
if ($apcuDisabledRow === null) {
    $errors[] = 'APCu disabled row missing from diagnostics output';
} else {
    if ($apcuDisabledRow['severity'] !== EnvProbe::SEV_WARN) {
        $errors[] = "APCu disabled should be SEV_WARN; got {$apcuDisabledRow['severity']}";
    } else {
        $passed++;
    }
    if (strpos($apcuDisabledRow['value'], 'disabled') === false) {
        $errors[] = "APCu disabled value should mention disabled; got: {$apcuDisabledRow['value']}";
    } else {
        $passed++;
    }
}

// Sodium missing: must SEV_FAIL (pairing breaks).
$rowsNoSodium = EnvProbe::diagnostics(_v1019_env_stub(['sodium_loaded' => false]));
$sodiumRow = _v1019_find($rowsNoSodium, 'sodium');
if ($sodiumRow === null) {
    $errors[] = 'sodium row missing';
} else {
    if ($sodiumRow['severity'] !== EnvProbe::SEV_FAIL) {
        $errors[] = "sodium missing should be SEV_FAIL; got {$sodiumRow['severity']}";
    } else {
        $passed++;
    }
}

// cURL missing: SEV_FAIL.
$rowsNoCurl = EnvProbe::diagnostics(_v1019_env_stub(['curl_loaded' => false]));
$curlRow = _v1019_find($rowsNoCurl, 'cURL');
if ($curlRow === null) {
    $errors[] = 'cURL row missing';
} elseif ($curlRow['severity'] !== EnvProbe::SEV_FAIL) {
    $errors[] = "cURL missing should be SEV_FAIL; got {$curlRow['severity']}";
} else {
    $passed++;
}

// OPcache disabled: SEV_WARN, not FAIL.
$rowsNoOpcache = EnvProbe::diagnostics(_v1019_env_stub(['opcache_enabled' => false]));
$opRow = _v1019_find($rowsNoOpcache, 'OPcache');
if ($opRow === null) {
    $errors[] = 'OPcache row missing';
} elseif ($opRow['severity'] !== EnvProbe::SEV_WARN) {
    $errors[] = "OPcache disabled should be SEV_WARN; got {$opRow['severity']}";
} else {
    $passed++;
}

// PHP-version-aware package names: a 7.4 host should emit
// `ea-php74-pecl-apcu` in the APCu hint, not the default 8.1.
$rowsPhp74 = EnvProbe::diagnostics(_v1019_env_stub([
    'php_version'    => '7.4.33',
    'apcu_loaded'    => false,
    'apcu_extension' => false,
]));
$apcu74 = _v1019_find($rowsPhp74, 'APCu');
if ($apcu74 === null || strpos($apcu74['hint'], 'ea-php74-pecl-apcu') === false) {
    $errors[] = 'PHP 7.4 host APCu hint should reference ea-php74-pecl-apcu; got: '
        . ($apcu74 === null ? '(no row)' : $apcu74['hint']);
} else {
    $passed++;
}

// ---------------------------------------------------------------------------
// 4. lockedDomainStatus() — three branches via constant fixtures.
// ---------------------------------------------------------------------------

// Branch: no lock + no catalog -> info/unset.
[$sev, $code, $detail] = EnvProbe::lockedDomainStatus();
if ($sev !== EnvProbe::SEV_INFO || $code !== 'unset') {
    $errors[] = "lockedDomainStatus() default should be (info, unset); got ({$sev}, {$code})";
} else {
    $passed++;
}

// Branch: locked but no catalog URL -> info/unknown.
// We can't redefine constants in PHP, so this branch (and the
// matches/mismatch branches) get smoke-tested in
// CI via an integration run against a paired Zen Cart instance.
// The default-no-lock branch above is enough to assert the function
// is callable and returns the right shape.

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------
echo "\n=== EnvProbe (v1.0.19) ===\n";
echo "passed: {$passed} | failed: " . count($errors) . "\n";
foreach ($errors as $e) {
    echo "  FAIL: {$e}\n";
}
exit($errors === [] ? 0 : 1);
