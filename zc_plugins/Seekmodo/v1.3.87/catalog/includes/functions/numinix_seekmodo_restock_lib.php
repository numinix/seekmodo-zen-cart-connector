<?php
declare(strict_types=1);

use Numinix\Seekmodo\Client;

/**
 * Restock intelligence transport. This is independent of storefront
 * search and sends exact stock/order facts only to restock.* tools.
 */

/**
 * @param array<string,mixed> $row
 * @return array{product_id:string,sku:?string,product_name:?string,quantity:int}
 */
function numinix_seekmodo_restock_inventory_row(array $row): array
{
    $sku = trim((string) ($row['products_model'] ?? ''));
    $name = trim((string) ($row['products_name'] ?? ''));
    return [
        'product_id' => (string) (int) ($row['products_id'] ?? 0),
        'sku' => $sku === '' ? null : $sku,
        'product_name' => $name === '' ? null : $name,
        'quantity' => (int) ($row['products_quantity'] ?? 0),
    ];
}

/**
 * Return a stable tenant-scoped key without transmitting customer PII or
 * Zen Cart's directly enumerable customer ID. Guest orders remain unlinked.
 */
function numinix_seekmodo_restock_customer_key(int $customerId): ?string
{
    if ($customerId <= 0
        || !defined('NUMINIX_SEEKMODO_TENANT_ID')
        || !defined('NUMINIX_SEEKMODO_SHARED_SECRET')
    ) {
        return null;
    }
    $tenantId = trim((string) constant('NUMINIX_SEEKMODO_TENANT_ID'));
    $secret = trim((string) constant('NUMINIX_SEEKMODO_SHARED_SECRET'));
    if ($tenantId === '' || $secret === '') {
        return null;
    }
    return hash_hmac('sha256', "zc-customer\0" . $customerId, $tenantId . "\0" . $secret);
}

/**
 * @param object|null $client Test doubles only need callBulkTool().
 * @return array{products:int,batches:int,failed_batches:int,snapshot_id:string,finalized:bool}
 */
function numinix_seekmodo_push_restock_inventory(
    $client = null,
    int $batchSize = 500,
    ?string $snapshotId = null,
    ?string $capturedAt = null
): array {
    global $db;
    $batchSize = max(1, min(1000, $batchSize));
    $snapshotId = $snapshotId ?: numinix_seekmodo_restock_snapshot_id();
    $capturedAt = $capturedAt ?: gmdate(DATE_ATOM);
    $result = [
        'products' => 0,
        'batches' => 0,
        'failed_batches' => 0,
        'snapshot_id' => $snapshotId,
        'finalized' => false,
    ];
    if (!is_object($db) || !method_exists($db, 'Execute')) {
        return $result;
    }
    if ($client === null) {
        $client = Client::fromConfiguration();
    }
    if (!is_object($client) || !method_exists($client, 'callBulkTool')) {
        return $result;
    }

    $languageId = isset($_SESSION['languages_id']) ? max(1, (int) $_SESSION['languages_id']) : 1;
    $sql = 'SELECT p.products_id, p.products_model, p.products_quantity, pd.products_name '
        . 'FROM ' . TABLE_PRODUCTS . ' p '
        . 'LEFT JOIN ' . TABLE_PRODUCTS_DESCRIPTION . ' pd '
        . 'ON pd.products_id = p.products_id AND pd.language_id = ' . $languageId . ' '
        . 'WHERE p.products_status = 1 ORDER BY p.products_id ASC';
    $query = $db->Execute($sql);
    $rows = [];
    while (!$query->EOF) {
        $row = numinix_seekmodo_restock_inventory_row((array) $query->fields);
        if ($row['product_id'] !== '0') {
            $rows[] = $row;
        }
        $query->MoveNext();
        if (count($rows) >= $batchSize) {
            numinix_seekmodo_send_restock_inventory_batch(
                $client,
                $snapshotId,
                $capturedAt,
                $rows,
                $result
            );
            $rows = [];
        }
    }
    if ($rows !== []) {
        numinix_seekmodo_send_restock_inventory_batch(
            $client,
            $snapshotId,
            $capturedAt,
            $rows,
            $result
        );
    }
    // An empty store is still a complete snapshot and must clear any
    // previously-current rows, so stage one empty batch before finalize.
    if ($result['batches'] === 0) {
        numinix_seekmodo_send_restock_inventory_batch(
            $client,
            $snapshotId,
            $capturedAt,
            [],
            $result
        );
    }
    if ($result['failed_batches'] === 0) {
        $finalized = $client->callBulkTool(
            'restock.inventory.finalize',
            ['snapshot_id' => $snapshotId]
        );
        if (is_array($finalized)) {
            $result['finalized'] = true;
        } else {
            $result['failed_batches']++;
        }
    }
    return $result;
}

/**
 * @param object $client
 * @param list<array<string,mixed>> $rows
 * @param array{products:int,batches:int,failed_batches:int,snapshot_id:string,finalized:bool} $result
 */
function numinix_seekmodo_send_restock_inventory_batch(
    $client,
    string $snapshotId,
    string $capturedAt,
    array $rows,
    array &$result
): void {
    $response = $client->callBulkTool('restock.inventory.upsert', [
        'snapshot_id' => $snapshotId,
        'captured_at' => $capturedAt,
        'rows' => $rows,
    ]);
    $result['batches']++;
    if (!is_array($response)) {
        $result['failed_batches']++;
        return;
    }
    $result['products'] += count($rows);
}

/**
 * Backfill complete order lines independently from keyword-attributed
 * purchase telemetry.
 *
 * @param object|null $client Test doubles only need callBulkTool().
 * @return array{orders:int,lines:int,batches:int,failed_batches:int}
 */
function numinix_seekmodo_push_restock_orders(
    $client = null,
    int $days = 365,
    array $excludedStatusIds = []
): array
{
    global $db;
    $days = max(1, min(730, $days));
    $result = ['orders' => 0, 'lines' => 0, 'batches' => 0, 'failed_batches' => 0];
    if (!is_object($db) || !method_exists($db, 'Execute')) {
        return $result;
    }
    if ($client === null) {
        $client = Client::fromConfiguration();
    }
    if (!is_object($client) || !method_exists($client, 'callBulkTool')) {
        return $result;
    }
    if ($excludedStatusIds === [] && defined('NUMINIX_SEEKMODO_RESTOCK_EXCLUDED_STATUS_IDS')) {
        $excludedStatusIds = explode(',', (string) NUMINIX_SEEKMODO_RESTOCK_EXCLUDED_STATUS_IDS);
    }
    $excludedStatusIds = array_values(array_unique(array_filter(
        array_map('intval', $excludedStatusIds),
        static fn (int $id): bool => $id > 0
    )));

    $languageId = isset($_SESSION['languages_id']) ? max(1, (int) $_SESSION['languages_id']) : 1;
    $sql = 'SELECT o.orders_id, o.customers_id, o.date_purchased, o.currency, o.orders_status, '
        . 'os.orders_status_name, op.orders_products_id, op.products_id, '
        . 'op.products_model, op.products_name, op.products_quantity, op.final_price '
        . 'FROM ' . TABLE_ORDERS . ' o '
        . 'INNER JOIN ' . TABLE_ORDERS_PRODUCTS . ' op ON op.orders_id = o.orders_id '
        . 'LEFT JOIN ' . TABLE_ORDERS_STATUS . ' os '
        . 'ON os.orders_status_id = o.orders_status AND os.language_id = ' . $languageId . ' '
        . 'WHERE o.date_purchased >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY) '
        . 'ORDER BY o.orders_id ASC, op.orders_products_id ASC';
    $query = $db->Execute($sql);

    $batch = [];
    $current = null;
    $batchLines = 0;
    $queueOrder = static function (array $order) use (
        $client,
        &$batch,
        &$batchLines,
        &$result
    ): void {
        $orderLines = count($order['lines'] ?? []);
        if ($orderLines > 1000) {
            $result['failed_batches']++;
            error_log(
                'Seekmodo restock skipped order ' . (string) ($order['order_id'] ?? '')
                . ': complete order exceeds 1000 lines'
            );
            return;
        }
        if ($batch !== [] && (count($batch) >= 100 || $batchLines + $orderLines > 1000)) {
            numinix_seekmodo_send_restock_orders_batch($client, $batch, $result);
            $batch = [];
            $batchLines = 0;
        }
        $batch[] = $order;
        $batchLines += $orderLines;
        if (count($batch) >= 100 || $batchLines >= 900) {
            numinix_seekmodo_send_restock_orders_batch($client, $batch, $result);
            $batch = [];
            $batchLines = 0;
        }
    };
    while (!$query->EOF) {
        $row = (array) $query->fields;
        $orderId = (string) (int) ($row['orders_id'] ?? 0);
        if ($current !== null && $current['order_id'] !== $orderId) {
            $queueOrder($current);
            $current = null;
        }
        if ($current === null) {
            $status = strtolower(trim((string) ($row['orders_status_name'] ?? '')));
            $statusId = (int) ($row['orders_status'] ?? 0);
            $current = [
                'order_id' => $orderId,
                'ordered_at' => gmdate(DATE_ATOM, strtotime((string) ($row['date_purchased'] ?? 'now'))),
                'customer_key' => numinix_seekmodo_restock_customer_key(
                    (int) ($row['customers_id'] ?? 0)
                ),
                'include_in_demand' => !in_array($statusId, $excludedStatusIds, true)
                    && preg_match('/cancel|refund|void/', $status) !== 1,
                'lines' => [],
            ];
        }
        $current['lines'][] = numinix_seekmodo_restock_order_line($orderId, $row);
        $query->MoveNext();
    }
    if ($current !== null) {
        $queueOrder($current);
    }
    if ($batch !== []) {
        numinix_seekmodo_send_restock_orders_batch($client, $batch, $result);
    }
    return $result;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function numinix_seekmodo_restock_order_line(string $orderId, array $row): array
{
    $productId = (int) ($row['products_id'] ?? $row['id'] ?? 0);
    $lineId = (int) ($row['orders_products_id'] ?? 0);
    if ($lineId <= 0) {
        $lineId = substr(hash('sha256', $orderId . ':' . $productId . ':' . json_encode($row)), 0, 32);
    }
    $sku = trim((string) ($row['products_model'] ?? $row['model'] ?? ''));
    $name = trim((string) ($row['products_name'] ?? $row['name'] ?? ''));
    $currency = strtoupper(trim((string) ($row['currency'] ?? (defined('DEFAULT_CURRENCY') ? DEFAULT_CURRENCY : ''))));
    $price = $row['final_price'] ?? $row['price'] ?? null;
    return [
        'line_id' => (string) $lineId,
        'product_id' => (string) $productId,
        'sku' => $sku === '' ? null : $sku,
        'product_name' => $name === '' ? null : $name,
        'quantity' => max(1, (int) ($row['products_quantity'] ?? $row['qty'] ?? 1)),
        'unit_price_cents' => is_numeric($price) ? (int) round((float) $price * 100) : null,
        'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
    ];
}

/**
 * @param object $client
 * @param list<array<string,mixed>> $orders
 * @param array{orders:int,lines:int,batches:int,failed_batches:int} $result
 */
function numinix_seekmodo_send_restock_orders_batch($client, array $orders, array &$result): void
{
    $response = $client->callBulkTool('restock.orders.upsert', ['orders' => $orders]);
    $result['batches']++;
    if (!is_array($response)) {
        $result['failed_batches']++;
        return;
    }
    $result['orders'] += count($orders);
    foreach ($orders as $order) {
        $result['lines'] += count($order['lines'] ?? []);
    }
}

function numinix_seekmodo_restock_snapshot_id(): string
{
    try {
        $suffix = bin2hex(random_bytes(6));
    } catch (Throwable) {
        $suffix = substr(hash('sha256', uniqid('', true)), 0, 12);
    }
    return 'zc-' . gmdate('YmdHis') . '-' . $suffix;
}
