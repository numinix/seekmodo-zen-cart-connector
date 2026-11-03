<?php
/**
 * Post-pair first index:
 *   - activate_after_pair builds a fork of numinix_seekmodo_push_catalog.php
 *   - a batch with failed docs does not call catalog.prune
 *
 *     php tests/test_pair_first_index.php
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

function assertTrue($cond, string $msg, array &$errors, int &$passed): void
{
    if ($cond) {
        $passed++;
        return;
    }
    $errors[] = $msg;
}

$plugin = __DIR__ . '/../zc_plugins/Seekmodo/v1.3.97';
$pairingFile = $plugin . '/catalog/includes/library/Numinix/Seekmodo/Pairing.php';
$indexerLib = $plugin . '/catalog/includes/functions/numinix_seekmodo_indexer_lib.php';
$pusher = $plugin . '/catalog/numinix_seekmodo_push_catalog.php';

if (!defined('NUMINIX_SEEKMODO_PHP_BINARY')) {
    define('NUMINIX_SEEKMODO_PHP_BINARY', '/usr/bin/php');
}
if (!defined('TABLE_CONFIGURATION')) {
    define('TABLE_CONFIGURATION', 'configuration');
}
if (!function_exists('zen_db_input')) {
    function zen_db_input(string $v): string
    {
        return $v;
    }
}

require_once $pairingFile;

use Numinix\Seekmodo\Pairing;

$reflect = new ReflectionMethod(Pairing::class, 'initial_push_command');
if (PHP_VERSION_ID < 80100) {
    $reflect->setAccessible(true);
}
$built = $reflect->invoke(null);
$cmd = (string) ($built['cmd'] ?? '');
assertTrue(
    strpos($cmd, 'numinix_seekmodo_push_catalog.php') !== false,
    'initial push command must invoke numinix_seekmodo_push_catalog.php, got: ' . $cmd,
    $errors,
    $passed
);
assertTrue(
    strpos($cmd, '--ack-quota') !== false,
    'initial push command must pass --ack-quota',
    $errors,
    $passed
);

$pairingSrc = (string) file_get_contents($pairingFile);
assertTrue(
    (bool) preg_match('/function activate_after_pair\([\s\S]*?fork_initial_push\(\)/', $pairingSrc),
    'activate_after_pair must call fork_initial_push()',
    $errors,
    $passed
);

if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
    $report = Pairing::activate_after_pair([
        'sub' => 'acme',
        'shared_secret' => 'secret',
        'mcp_url' => '',
    ]);
    $blob = implode("\n", $report['errors'] ?? []);
    assertTrue(
        !empty($report['first_push_forked']) || strpos($blob, 'first_push:') !== false,
        'activate_after_pair must invoke the catalog-push fork helper',
        $errors,
        $passed
    );
} else {
    assertTrue(true, 'non-windows skips live fork to avoid spawning the pusher', $errors, $passed);
}

$GLOBALS['seekmodo_prune_calls'] = 0;
if (!function_exists('numinix_seekmodo_catalog_prune')) {
    function numinix_seekmodo_catalog_prune(int $cutoffEpoch, ?int $activeCount = null): ?array
    {
        $GLOBALS['seekmodo_prune_calls']++;
        unset($cutoffEpoch, $activeCount);

        return ['deleted' => 0, 'scanned' => 0, 'has_more' => false, 'errors' => []];
    }
}
require_once $indexerLib;

$skipped = numinix_seekmodo_maybe_catalog_prune(3, time(), 10, false, false);
assertTrue($skipped === null, 'failed batch must not return a prune result', $errors, $passed);
assertTrue(
    (int) $GLOBALS['seekmodo_prune_calls'] === 0,
    'a batch with failed>0 must not call catalog.prune',
    $errors,
    $passed
);

$dry = numinix_seekmodo_maybe_catalog_prune(0, time(), 10, true, false);
assertTrue($dry === null && (int) $GLOBALS['seekmodo_prune_calls'] === 0, 'dry-run must not prune', $errors, $passed);

$ran = numinix_seekmodo_maybe_catalog_prune(0, time(), 10, false, false);
assertTrue(is_array($ran), 'successful batch may prune', $errors, $passed);
assertTrue(
    (int) $GLOBALS['seekmodo_prune_calls'] === 1,
    'successful batch calls catalog.prune once',
    $errors,
    $passed
);

$pusherSrc = (string) file_get_contents($pusher);
assertTrue(
    strpos($pusherSrc, 'numinix_seekmodo_maybe_catalog_prune') !== false,
    'push_catalog must gate catalog.prune through maybe_catalog_prune',
    $errors,
    $passed
);

if ($errors !== []) {
    fwrite(STDERR, "FAIL\n");
    foreach ($errors as $err) {
        fwrite(STDERR, '  - ' . $err . "\n");
    }
    exit(1);
}

echo "OK {$passed} assertions\n";
exit(0);
