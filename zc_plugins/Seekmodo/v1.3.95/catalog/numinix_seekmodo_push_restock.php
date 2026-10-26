<?php
/**
 * numinix_seekmodo_push_restock.php
 *
 * Sends on-hand stock and recent order lines to Seekmodo restock tools.
 * Shopper search documents are unchanged. Customer ids are hashed.
 *
 *   php zc_plugins/Seekmodo/v1.3.95/catalog/numinix_seekmodo_push_restock.php
 *   php .../numinix_seekmodo_push_restock.php --dry-run
 *   php .../numinix_seekmodo_push_restock.php --days=90
 */
declare(strict_types=1);

$___smLogLib = __DIR__ . '/includes/functions/numinix_seekmodo_log_lib.php';
if (is_file($___smLogLib)) {
    require_once $___smLogLib;
}
if (function_exists('numinix_seekmodo_require_cli')) {
    numinix_seekmodo_require_cli('numinix_seekmodo_push_restock.php');
} elseif (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    if (!headers_sent()) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "FATAL: numinix_seekmodo_push_restock.php must run from CLI\n";
    exit(2);
}

$catalogRoot = realpath(__DIR__ . '/../../../../');
if ($catalogRoot === false || !is_dir($catalogRoot . '/includes')) {
    fwrite(STDERR, "ERROR: cannot resolve catalog docroot from " . __FILE__ . "\n");
    exit(2);
}
chdir($catalogRoot);

require './includes/configure.php';
ini_set('include_path', DIR_FS_CATALOG . PATH_SEPARATOR . ini_get('include_path'));
chdir(DIR_FS_CATALOG);
require_once 'includes/application_top.php';

if (function_exists('numinix_seekmodo_disable_query_cache')) {
    numinix_seekmodo_disable_query_cache();
}
if (PHP_SAPI === 'cli' && session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}

$dryRun = false;
$lookbackDays = 90;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^--days=(\d+)$/', $arg, $m) === 1) {
        $lookbackDays = max(1, min(365, (int) $m[1]));
    }
}

$languageId = (int) ($_SESSION['languages_id'] ?? 1);
if ($languageId <= 0) {
    $languageId = 1;
}

/**
 * @param array<string,mixed> $body
 * @return array<string,mixed>|null
 */
function restock_post(string $tool, array $body): ?array
{
    if (function_exists('numinix_seekmodo_tool')) {
        return numinix_seekmodo_tool($tool, $body, 60000);
    }
    if (!defined('NUMINIX_SEEKMODO_URL')
        || !defined('NUMINIX_SEEKMODO_TENANT_ID')
        || !defined('NUMINIX_SEEKMODO_SHARED_SECRET')) {
        fwrite(STDERR, "ERROR: connector is not paired\n");
        return null;
    }
    $url = rtrim((string) NUMINIX_SEEKMODO_URL, '/');
    $tenant = trim((string) NUMINIX_SEEKMODO_TENANT_ID);
    $secret = trim((string) NUMINIX_SEEKMODO_SHARED_SECRET);
    if ($url === '' || $tenant === '' || $secret === '') {
        fwrite(STDERR, "ERROR: connector is not paired\n");
        return null;
    }
    $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($raw === false) {
        return null;
    }
    $headers = [
        'Content-Type: application/json',
        'X-Seekmodo-Tenant: ' . $tenant,
        'X-Seekmodo-Signature: ' . hash_hmac('sha256', $raw, $secret),
        'X-Seekmodo-Timestamp: ' . time(),
        'Accept: application/json',
    ];
    $host = \Numinix\Seekmodo\Client::storefrontHost();
    if ($host !== '') {
        $headers[] = 'X-Seekmodo-Storefront-Host: ' . $host;
    }
    $ch = curl_init($url . '/v1/' . $tool);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $raw,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($resp) || $status < 200 || $status >= 300) {
        $preview = is_string($resp) ? substr($resp, 0, 180) : '';
        fwrite(STDERR, "ERROR: {$tool} HTTP {$status} {$preview}\n");
        return null;
    }
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : null;
}

function restock_cancelled(?string $statusName): bool
{
    if ($statusName === null || trim($statusName) === '') {
        return false;
    }
    return preg_match('/cancel|void/i', $statusName) === 1;
}

function restock_clip(?string $value, int $max): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

global $db;

$capturedAt = gmdate('Y-m-d\TH:i:s\Z');
$snapshotId = 'zc-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
$inventoryRows = 0;
$inventoryBatches = 0;
$ordersSent = 0;
$orderBatches = 0;
$ordersSkipped = 0;
$failed = false;

$lastProductId = 0;
while (true) {
    $sql = 'SELECT p.products_id, p.products_model, p.products_quantity, pd.products_name'
        . ' FROM ' . TABLE_PRODUCTS . ' p'
        . ' LEFT JOIN ' . TABLE_PRODUCTS_DESCRIPTION . ' pd'
        . '   ON pd.products_id = p.products_id AND pd.language_id = ' . (int) $languageId
        . ' WHERE p.products_status = 1 AND p.products_id > ' . (int) $lastProductId
        . ' ORDER BY p.products_id ASC LIMIT 1000';
    $products = $db->Execute($sql);
    if (!$products || $products->RecordCount() === 0) {
        break;
    }
    $rows = [];
    foreach ($products as $row) {
        if (!is_array($row)) {
            continue;
        }
        $pid = (int) ($row['products_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        if ($pid > $lastProductId) {
            $lastProductId = $pid;
        }
        $qty = is_numeric($row['products_quantity'] ?? null) ? (int) $row['products_quantity'] : 0;
        $rows[] = [
            'product_id' => (string) $pid,
            'sku' => restock_clip(isset($row['products_model']) ? (string) $row['products_model'] : null, 128),
            'product_name' => restock_clip(isset($row['products_name']) ? (string) $row['products_name'] : null, 255),
            'quantity' => $qty,
        ];
    }
    if (function_exists('numinix_seekmodo_release_query_result')) {
        numinix_seekmodo_release_query_result($products);
    }
    if ($rows === []) {
        break;
    }
    $inventoryRows += count($rows);
    $inventoryBatches++;
    if (!$dryRun) {
        $resp = restock_post('restock.inventory.upsert', [
            'snapshot_id' => $snapshotId,
            'captured_at' => $capturedAt,
            'rows' => $rows,
        ]);
        if ($resp === null) {
            $failed = true;
            fwrite(STDERR, "ERROR: inventory batch {$inventoryBatches} failed\n");
        }
    }
}

if ($inventoryRows === 0 && !$dryRun) {
    $resp = restock_post('restock.inventory.upsert', [
        'snapshot_id' => $snapshotId,
        'captured_at' => $capturedAt,
        'rows' => [],
    ]);
    if ($resp === null) {
        $failed = true;
    } else {
        $inventoryBatches++;
    }
}

if (!$dryRun && !$failed) {
    $resp = restock_post('restock.inventory.finalize', ['snapshot_id' => $snapshotId]);
    if ($resp === null) {
        $failed = true;
        fwrite(STDERR, "ERROR: inventory finalize failed\n");
    }
}

$since = (new DateTimeImmutable('now'))
    ->modify('-' . $lookbackDays . ' days')
    ->format('Y-m-d H:i:s');
$sinceSql = zen_db_input($since);
$lastOrderId = 0;
while (true) {
    $sql = 'SELECT o.orders_id, o.customers_id, o.date_purchased, o.currency, os.orders_status_name'
        . ' FROM ' . TABLE_ORDERS . ' o'
        . ' LEFT JOIN ' . TABLE_ORDERS_STATUS . ' os'
        . '   ON os.orders_status_id = o.orders_status AND os.language_id = ' . (int) $languageId
        . ' WHERE o.date_purchased >= \'' . $sinceSql . '\''
        . ' AND o.orders_id > ' . (int) $lastOrderId
        . ' ORDER BY o.orders_id ASC LIMIT 250';
    $orders = $db->Execute($sql);
    if (!$orders || $orders->RecordCount() === 0) {
        break;
    }
    $page = [];
    $ids = [];
    foreach ($orders as $row) {
        if (!is_array($row)) {
            continue;
        }
        $oid = (int) ($row['orders_id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        if ($oid > $lastOrderId) {
            $lastOrderId = $oid;
        }
        if (restock_cancelled(isset($row['orders_status_name']) ? (string) $row['orders_status_name'] : null)) {
            $ordersSkipped++;
            continue;
        }
        $page[$oid] = $row;
        $ids[] = $oid;
    }
    if (function_exists('numinix_seekmodo_release_query_result')) {
        numinix_seekmodo_release_query_result($orders);
    }
    if ($ids === []) {
        continue;
    }
    $lineSql = 'SELECT orders_products_id, orders_id, products_id, products_model, products_name,'
        . ' products_quantity, final_price'
        . ' FROM ' . TABLE_ORDERS_PRODUCTS
        . ' WHERE orders_id IN (' . implode(',', $ids) . ')';
    $lines = $db->Execute($lineSql);
    $byOrder = [];
    if ($lines) {
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $oid = (int) ($line['orders_id'] ?? 0);
            $byOrder[$oid][] = $line;
        }
        if (function_exists('numinix_seekmodo_release_query_result')) {
            numinix_seekmodo_release_query_result($lines);
        }
    }

    $batch = [];
    $lineCount = 0;
    $flush = static function () use (&$batch, &$lineCount, &$orderBatches, &$ordersSent, &$failed, $dryRun): void {
        if ($batch === []) {
            return;
        }
        $orderBatches++;
        $ordersSent += count($batch);
        if (!$dryRun) {
            $resp = restock_post('restock.orders.upsert', ['orders' => $batch]);
            if ($resp === null) {
                $failed = true;
                fwrite(STDERR, "ERROR: order batch {$orderBatches} failed\n");
            }
        }
        $batch = [];
        $lineCount = 0;
    };

    foreach ($ids as $oid) {
        $header = $page[$oid];
        $orderLines = [];
        foreach ($byOrder[$oid] ?? [] as $line) {
            $qty = is_numeric($line['products_quantity'] ?? null) ? (int) $line['products_quantity'] : 0;
            $pid = (int) ($line['products_id'] ?? 0);
            $lineId = (int) ($line['orders_products_id'] ?? 0);
            if ($qty < 1 || $pid <= 0 || $lineId <= 0) {
                continue;
            }
            $currency = isset($header['currency']) ? strtoupper(trim((string) $header['currency'])) : '';
            $price = null;
            if (isset($line['final_price']) && is_numeric($line['final_price'])) {
                $price = (int) round(((float) $line['final_price']) * 100);
            }
            $orderLines[] = [
                'line_id' => (string) $lineId,
                'product_id' => (string) $pid,
                'sku' => restock_clip(isset($line['products_model']) ? (string) $line['products_model'] : null, 128),
                'product_name' => restock_clip(isset($line['products_name']) ? (string) $line['products_name'] : null, 255),
                'quantity' => $qty,
                'unit_price_cents' => $price,
                'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
            ];
        }
        if ($orderLines === []) {
            $ordersSkipped++;
            continue;
        }
        if (count($orderLines) > 1000) {
            $ordersSkipped++;
            fwrite(STDERR, "WARN: order {$oid} has more than 1000 lines; skipped\n");
            continue;
        }
        if ($batch !== [] && ($lineCount + count($orderLines) > 1000 || count($batch) >= 250)) {
            $flush();
        }
        $customerId = (int) ($header['customers_id'] ?? 0);
        $orderedAt = (string) ($header['date_purchased'] ?? '');
        try {
            $orderedAt = (new DateTimeImmutable($orderedAt))->format('c');
        } catch (Throwable) {
            $ordersSkipped++;
            continue;
        }
        $batch[] = [
            'order_id' => (string) $oid,
            'ordered_at' => $orderedAt,
            'customer_key' => $customerId > 0 ? hash('sha256', (string) $customerId) : null,
            'include_in_demand' => true,
            'lines' => $orderLines,
        ];
        $lineCount += count($orderLines);
    }
    $flush();
}

echo 'snapshot=' . $snapshotId
    . ' inventory_rows=' . $inventoryRows
    . ' inventory_batches=' . $inventoryBatches
    . ' orders=' . $ordersSent
    . ' order_batches=' . $orderBatches
    . ' skipped=' . $ordersSkipped
    . ($dryRun ? ' dry_run=1' : '')
    . "\n";
exit($failed ? 1 : 0);
