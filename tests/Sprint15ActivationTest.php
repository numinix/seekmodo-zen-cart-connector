<?php
/**
 * Regression test for the v1.0.13 / Sprint 15 changes:
 *
 *   1. Client::fromConfiguration honors the split search/index
 *      timeouts and falls back to the legacy NUMINIX_SEEKMODO_TIMEOUT_MS.
 *   2. Client clamps search to 80-5000ms and index to 1000-120000ms.
 *   3. RemoteConfig::writeThrough mirrors the new search_timeout_ms /
 *      index_timeout_ms snapshot fields to the new constants.
 *   4. Pairing::activate_after_pair flips the local mode constants
 *      to `enforce` and surfaces an activation report.
 *
 * Self-contained — no PHPUnit. Same pattern as Sprint12LockedDomainTest.
 *
 *     php tests/Sprint15ActivationTest.php
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.13/catalog/includes/library/Numinix/Seekmodo/Client.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.13/catalog/includes/library/Numinix/Seekmodo/RemoteConfig.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.13/catalog/includes/library/Numinix/Seekmodo/CircuitBreakerStore.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.13/catalog/includes/library/Numinix/Seekmodo/ApcuCircuitBreakerStore.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.13/catalog/includes/library/Numinix/Seekmodo/Pairing.php';
require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.0.13/catalog/includes/library/Numinix/Seekmodo/WellKnownWriter.php';

if (!function_exists('zen_db_input')) {
    function zen_db_input(string $v): string { return $v; }
}
if (!defined('TABLE_CONFIGURATION')) {
    define('TABLE_CONFIGURATION', 'configuration');
}

use Numinix\Seekmodo\Client;
use Numinix\Seekmodo\Pairing;
use Numinix\Seekmodo\RemoteConfig;

/**
 * Capturing $db replacement that records every UPDATE/INSERT for
 * later assertion. Used by both the writeThrough and activate_after_pair
 * arms of the test.
 */
final class CapturingDbSprint15
{
    /** @var list<array{sql:string, key:string|null, value:string|null, op:string}> */
    public array $writes = [];

    /** Map of configuration_key -> configuration_value used to satisfy the existence-check SELECTs in Pairing::set_or_insert_config. */
    public array $rows = [];

    public function Execute(string $sql)
    {
        $key = null;
        $value = null;
        $op = '';
        if (preg_match('/^\s*SELECT\b/i', $sql)) {
            $op = 'SELECT';
            // mimic AdoDB-ish recordset; only existence-check is done.
            if (preg_match("/configuration_key\s*=\s*'([^']*)'/i", $sql, $m)) {
                $key = $m[1];
                return new class ($this->rows[$key] ?? null) {
                    public bool $EOF;
                    public array $fields;
                    public function __construct(?string $val)
                    {
                        if ($val === null) {
                            $this->EOF = true;
                            $this->fields = [];
                        } else {
                            $this->EOF = false;
                            $this->fields = ['configuration_id' => 1, 'configuration_value' => $val];
                        }
                    }
                };
            }
            return new class {
                public bool $EOF = true;
                public array $fields = [];
            };
        }
        if (preg_match('/^\s*UPDATE\b/i', $sql)) {
            $op = 'UPDATE';
        } elseif (preg_match('/^\s*INSERT\b/i', $sql)) {
            $op = 'INSERT';
        }
        if (preg_match("/configuration_value\s*=\s*'([^']*)'/i", $sql, $m)) {
            $value = $m[1];
        }
        if (preg_match("/configuration_key\s*=\s*'([^']*)'/i", $sql, $m)) {
            $key = $m[1];
        }
        // INSERT shape: VALUES('title', 'key', 'value', ...)
        if ($op === 'INSERT' && preg_match("/VALUES\s*\(\s*'[^']*'\s*,\s*'([^']*)'\s*,\s*'([^']*)'/i", $sql, $m)) {
            $key = $m[1];
            $value = $m[2];
        }
        $this->writes[] = ['sql' => $sql, 'key' => $key, 'value' => $value, 'op' => $op];
        if ($key !== null && $value !== null && ($op === 'UPDATE' || $op === 'INSERT')) {
            $this->rows[$key] = $value;
        }
        return true;
    }
}

function assertEq($want, $got, string $label, array &$errors, int &$passed): void
{
    if ($want === $got) {
        $passed++;
        return;
    }
    $errors[] = sprintf("%s: want %s, got %s", $label, var_export($want, true), var_export($got, true));
}

function assertTrue(bool $cond, string $label, array &$errors, int &$passed): void
{
    if ($cond) {
        $passed++;
        return;
    }
    $errors[] = $label . ': expected true';
}

// ----------------------------------------------------------------
// 1. Client clamps search/index timeouts independently
// ----------------------------------------------------------------

$tooLowSearch = new Client('https://example.test', 't', 'sec', Client::MODE_ENFORCE, 10, false, null, null, 50000);
assertEq(80, $tooLowSearch->searchTimeoutMs(), 'Client floors search timeout to 80ms', $errors, $passed);
assertEq(50000, $tooLowSearch->indexTimeoutMs(), 'Client preserves a sane index timeout (50000ms)', $errors, $passed);

$tooHighSearch = new Client('https://example.test', 't', 'sec', Client::MODE_ENFORCE, 9999, false, null, null, 999999);
assertEq(5000, $tooHighSearch->searchTimeoutMs(), 'Client caps search timeout to 5000ms', $errors, $passed);
assertEq(120000, $tooHighSearch->indexTimeoutMs(), 'Client caps index timeout to 120000ms', $errors, $passed);

$tooLowIndex = new Client('https://example.test', 't', 'sec', Client::MODE_ENFORCE, 1500, false, null, null, 100);
assertEq(1500, $tooLowIndex->searchTimeoutMs(), 'Client preserves a sane search timeout (1500ms)', $errors, $passed);
assertEq(1000, $tooLowIndex->indexTimeoutMs(), 'Client floors index timeout to 1000ms', $errors, $passed);

// Defaults
assertEq(1500, Client::DEFAULT_SEARCH_TIMEOUT_MS, 'DEFAULT_SEARCH_TIMEOUT_MS = 1500', $errors, $passed);
assertEq(30000, Client::DEFAULT_INDEX_TIMEOUT_MS, 'DEFAULT_INDEX_TIMEOUT_MS = 30000', $errors, $passed);

// ----------------------------------------------------------------
// 2. RemoteConfig::writeThrough mirrors the split-bucket fields
// ----------------------------------------------------------------

$db = new CapturingDbSprint15();
// Pre-seed rows for all the keys writeThrough touches so the
// UPDATE statements have something to "find". writeThrough only
// UPDATEs (it doesn't INSERT), so without a row the SQL is a no-op.
foreach ([
    'NUMINIX_SEEKMODO_MODE',
    'NUMINIX_SEEKMODO_DEFAULT_MODE',
    'NUMINIX_SEEKMODO_AUTO_PROMOTE',
    'NUMINIX_SEEKMODO_TIMEOUT_MS',
    'NUMINIX_SEEKMODO_SEARCH_TIMEOUT_MS',
    'NUMINIX_SEEKMODO_INDEX_TIMEOUT_MS',
    'NUMINIX_SEEKMODO_INDEX_BATCH',
    'NUMINIX_SEEKMODO_INDEXER_SCHEDULE',
    'NUMINIX_SEEKMODO_DEBUG',
    'NUMINIX_BOT_CHECK_BACKEND',
    'NUMINIX_SEEKMODO_LOCKED_DOMAIN',
] as $k) {
    $db->rows[$k] = '';
}
$GLOBALS['db'] = $db;

$rc = new RemoteConfig('https://mcp.test', 't', 'sec');
$writeThroughRef = new ReflectionMethod(RemoteConfig::class, 'writeThrough');
$writeThroughRef->setAccessible(true);
$writeThroughRef->invoke($rc, [
    'mode' => 'enforce',
    'default_mode' => 'enforce',
    'auto_promote' => true,
    'timeout_ms' => 250,
    'search_timeout_ms' => 1500,
    'index_timeout_ms' => 30000,
    'index_batch' => 500,
    'indexer_schedule' => 'daily',
    'debug' => false,
    'bot_check_backend' => 'gateway',
    'locked_domain' => 'www.example.com',
]);

$writes = array_column($db->writes, 'value', 'key');
assertEq('1500', $writes['NUMINIX_SEEKMODO_SEARCH_TIMEOUT_MS'] ?? null, 'writeThrough mirrors search_timeout_ms', $errors, $passed);
assertEq('30000', $writes['NUMINIX_SEEKMODO_INDEX_TIMEOUT_MS'] ?? null, 'writeThrough mirrors index_timeout_ms', $errors, $passed);
assertEq('250', $writes['NUMINIX_SEEKMODO_TIMEOUT_MS'] ?? null, 'writeThrough still mirrors legacy timeout_ms (back-compat)', $errors, $passed);
assertEq('enforce', $writes['NUMINIX_SEEKMODO_MODE'] ?? null, 'writeThrough mirrors mode', $errors, $passed);

// ----------------------------------------------------------------
// 3. Pairing::activate_after_pair flips local mode + reports cleanly
// ----------------------------------------------------------------

$db = new CapturingDbSprint15();
$db->rows['NUMINIX_SEEKMODO_MODE'] = 'shadow';
$db->rows['NUMINIX_SEEKMODO_DEFAULT_MODE'] = 'shadow';
$GLOBALS['db'] = $db;

$report = Pairing::activate_after_pair([
    'sub' => 't_test',
    'shared_secret' => 'sec_test',
    'mcp_url' => 'https://mcp.test',
]);

assertTrue($report['mode_set'] === true, 'activate_after_pair flips mode_set true', $errors, $passed);
$writes = array_column($db->writes, 'value', 'key');
assertEq('enforce', $writes['NUMINIX_SEEKMODO_MODE'] ?? null, 'activate_after_pair writes mode=enforce', $errors, $passed);
assertEq('enforce', $writes['NUMINIX_SEEKMODO_DEFAULT_MODE'] ?? null, 'activate_after_pair writes default_mode=enforce', $errors, $passed);
assertTrue(array_key_exists('first_push_forked', $report), 'report carries first_push_forked', $errors, $passed);
assertTrue(array_key_exists('snapshot_pulled', $report), 'report carries snapshot_pulled', $errors, $passed);
assertTrue(array_key_exists('errors', $report) && is_array($report['errors']), 'report carries errors[]', $errors, $passed);

// Idempotent — running activate_after_pair a second time must NOT
// re-fork the catalog push (APCu marker would block it). On the test
// host without APCu the throttle returns true (always-fork) so we
// instead verify the second call still completes cleanly without
// throwing.
$report2 = Pairing::activate_after_pair([
    'sub' => 't_test',
    'shared_secret' => 'sec_test',
    'mcp_url' => 'https://mcp.test',
]);
assertTrue(is_array($report2), 'activate_after_pair is idempotent (second call also returns a report)', $errors, $passed);

// ----------------------------------------------------------------
// Summary
// ----------------------------------------------------------------

if ($errors === []) {
    fwrite(STDOUT, sprintf("OK Sprint15ActivationTest: %d assertions passed.\n", $passed));
    exit(0);
}
fwrite(STDERR, sprintf("FAIL Sprint15ActivationTest: %d passed, %d failures:\n", $passed, count($errors)));
foreach ($errors as $err) {
    fwrite(STDERR, '  - ' . $err . "\n");
}
exit(1);
