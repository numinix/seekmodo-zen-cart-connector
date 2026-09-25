<?php
/**
 * CLI-only exact inventory + complete order-line sync for restock guidance.
 *
 * Usage:
 *   php numinix_seekmodo_sync_restock.php --dry-run
 *   php numinix_seekmodo_sync_restock.php --days=365
 *   php numinix_seekmodo_sync_restock.php --exclude-statuses=4,7
 *   php numinix_seekmodo_sync_restock.php --inventory-only
 *   php numinix_seekmodo_sync_restock.php --orders-only
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(2);
}
$catalogRoot = realpath(__DIR__ . '/../../../../');
if ($catalogRoot === false || !is_dir($catalogRoot . '/includes')) {
    fwrite(STDERR, "ERROR: cannot resolve Zen Cart docroot\n");
    exit(2);
}
chdir($catalogRoot);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
require $catalogRoot . '/includes/application_top.php';

$lib = __DIR__ . '/includes/functions/numinix_seekmodo_restock_lib.php';
if (!is_file($lib)) {
    fwrite(STDERR, "ERROR: restock library missing\n");
    exit(2);
}
require_once $lib;

$dryRun = in_array('--dry-run', $argv, true);
$inventoryOnly = in_array('--inventory-only', $argv, true);
$ordersOnly = in_array('--orders-only', $argv, true);
if (!$dryRun) {
    if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
        fwrite(STDERR, "ERROR: connector is off or not paired\n");
        exit(4);
    }
    if (function_exists('numinix_seekmodo_can_index') && !numinix_seekmodo_can_index()) {
        $observed = function_exists('numinix_seekmodo_current_host')
            ? (string) numinix_seekmodo_current_host()
            : (string) ($_SERVER['HTTP_HOST'] ?? '');
        fwrite(
            STDERR,
            "ERROR: restock writes blocked on non-canonical host '{$observed}'\n"
        );
        exit(5);
    }
}
$days = 365;
$batch = 500;
$excludedStatuses = [];
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m) === 1) {
        $days = max(1, min(730, (int) $m[1]));
    } elseif (preg_match('/^--batch=(\d+)$/', $arg, $m) === 1) {
        $batch = max(1, min(1000, (int) $m[1]));
    } elseif (preg_match('/^--exclude-statuses=([0-9,]+)$/', $arg, $m) === 1) {
        $excludedStatuses = array_values(array_filter(
            array_map('intval', explode(',', $m[1])),
            static fn (int $id): bool => $id > 0
        ));
    }
}
if ($inventoryOnly && $ordersOnly) {
    fwrite(STDERR, "ERROR: choose at most one of --inventory-only / --orders-only\n");
    exit(2);
}

$client = null;
if ($dryRun) {
    $client = new class {
        /** @var list<array{name:string,args:array<string,mixed>}> */
        public array $calls = [];
        public function callBulkTool(string $name, array $args): array
        {
            $this->calls[] = ['name' => $name, 'args' => $args];
            return ['dry_run' => true];
        }
    };
}

$output = ['dry_run' => $dryRun];
if (!$ordersOnly) {
    $output['inventory'] = numinix_seekmodo_push_restock_inventory($client, $batch);
}
if (!$inventoryOnly) {
    $output['orders'] = numinix_seekmodo_push_restock_orders($client, $days, $excludedStatuses);
}
if ($dryRun && is_object($client) && property_exists($client, 'calls')) {
    $output['requests'] = array_map(
        static function (array $call): array {
            $args = $call['args'];
            return [
                'tool' => $call['name'],
                'rows' => isset($args['rows']) && is_array($args['rows']) ? count($args['rows']) : null,
                'orders' => isset($args['orders']) && is_array($args['orders']) ? count($args['orders']) : null,
            ];
        },
        $client->calls
    );
}
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
