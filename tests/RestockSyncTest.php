<?php
declare(strict_types=1);

foreach ([
    'TABLE_PRODUCTS' => 'products',
    'TABLE_PRODUCTS_DESCRIPTION' => 'products_description',
    'TABLE_ORDERS' => 'orders',
    'TABLE_ORDERS_PRODUCTS' => 'orders_products',
    'TABLE_ORDERS_STATUS' => 'orders_status',
    'DEFAULT_CURRENCY' => 'USD',
    'NUMINIX_SEEKMODO_TENANT_ID' => 'test-tenant',
    'NUMINIX_SEEKMODO_SHARED_SECRET' => 'test-secret',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
$_SESSION = ['languages_id' => 1];

final class RestockFakeResult
{
    public bool $EOF = false;
    /** @var array<string,mixed> */
    public array $fields = [];
    private int $index = 0;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows)
    {
        $this->sync();
    }
    public function MoveNext(): void
    {
        $this->index++;
        $this->sync();
    }
    private function sync(): void
    {
        $this->EOF = !isset($this->rows[$this->index]);
        $this->fields = $this->EOF ? [] : $this->rows[$this->index];
    }
}

final class RestockFakeDb
{
    /** @param list<array<string,mixed>> $products @param list<array<string,mixed>> $orders */
    public function __construct(private array $products, private array $orders)
    {
    }
    public function Execute(string $sql): RestockFakeResult
    {
        return new RestockFakeResult(str_contains($sql, 'FROM products p') ? $this->products : $this->orders);
    }
}

final class RestockFakeClient
{
    /** @var list<array{name:string,args:array<string,mixed>}> */
    public array $calls = [];
    private int $callIndex = 0;
    /** @param list<int> $failAt */
    public function __construct(private array $failAt = [])
    {
    }
    public function callBulkTool(string $name, array $args): ?array
    {
        $this->calls[] = ['name' => $name, 'args' => $args];
        $index = $this->callIndex++;
        if (in_array($index, $this->failAt, true)) {
            return null;
        }
        return ['ok' => true];
    }
}

require_once __DIR__
    . '/../zc_plugins/Seekmodo/v1.3.87/catalog/includes/functions/numinix_seekmodo_restock_lib.php';

$errors = [];
$passed = 0;
$eq = static function (string $label, mixed $expected, mixed $actual) use (&$errors, &$passed): void {
    if ($expected !== $actual) {
        $errors[] = "{$label}: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true);
        return;
    }
    $passed++;
};

$GLOBALS['db'] = new RestockFakeDb(
    [
        ['products_id' => 1, 'products_model' => 'FAST-001', 'products_quantity' => 10, 'products_name' => 'Fast'],
        ['products_id' => 2, 'products_model' => '', 'products_quantity' => 0, 'products_name' => 'Empty'],
        ['products_id' => 3, 'products_model' => 'BACK', 'products_quantity' => -4, 'products_name' => 'Backorder'],
    ],
    [
        [
            'orders_id' => 50, 'customers_id' => 10,
            'date_purchased' => '2026-09-20 12:00:00', 'currency' => 'USD',
            'orders_status' => 3, 'orders_status_name' => 'Delivered', 'orders_products_id' => 500, 'products_id' => 1,
            'products_model' => 'FAST-001', 'products_name' => 'Fast', 'products_quantity' => 2,
            'final_price' => '12.99',
        ],
        [
            'orders_id' => 51, 'customers_id' => 11,
            'date_purchased' => '2026-09-21 12:00:00', 'currency' => 'USD',
            'orders_status' => 4, 'orders_status_name' => 'Cancelled', 'orders_products_id' => 501, 'products_id' => 2,
            'products_model' => '', 'products_name' => 'Empty', 'products_quantity' => 1,
            'final_price' => '9.50',
        ],
        [
            'orders_id' => 52, 'customers_id' => 12,
            'date_purchased' => '2026-09-22 12:00:00', 'currency' => 'USD',
            'orders_status' => 7, 'orders_status_name' => 'Storniert', 'orders_products_id' => 502, 'products_id' => 3,
            'products_model' => 'BACK', 'products_name' => 'Backorder', 'products_quantity' => 4,
            'final_price' => '5.25',
        ],
    ]
);

$client = new RestockFakeClient();
$inventory = numinix_seekmodo_push_restock_inventory(
    $client,
    2,
    'fixed-snapshot',
    '2026-09-23T12:00:00Z'
);
$eq('inventory products', 3, $inventory['products']);
$eq('inventory batches', 2, $inventory['batches']);
$eq('inventory finalized', true, $inventory['finalized']);
$eq('inventory tool', 'restock.inventory.upsert', $client->calls[0]['name']);
$eq('exact stock quantity', 10, $client->calls[0]['args']['rows'][0]['quantity']);
$eq('negative backorder retained', -4, $client->calls[1]['args']['rows'][0]['quantity']);
$eq('finalize tool', 'restock.inventory.finalize', $client->calls[2]['name']);

$orders = numinix_seekmodo_push_restock_orders($client, 90, [7]);
$eq('orders count', 3, $orders['orders']);
$eq('order lines count', 3, $orders['lines']);
$orderCall = $client->calls[3];
$eq('orders tool', 'restock.orders.upsert', $orderCall['name']);
$eq('completed counts as demand', true, $orderCall['args']['orders'][0]['include_in_demand']);
$eq('cancelled excluded from demand', false, $orderCall['args']['orders'][1]['include_in_demand']);
$eq('localized configured status excluded', false, $orderCall['args']['orders'][2]['include_in_demand']);
$eq('price converted to cents', 1299, $orderCall['args']['orders'][0]['lines'][0]['unit_price_cents']);
$eq(
    'customer id becomes tenant-scoped opaque key',
    hash_hmac('sha256', "zc-customer\0" . '10', "test-tenant\0test-secret"),
    $orderCall['args']['orders'][0]['customer_key']
);

$largeOrders = [];
foreach ([60 => 850, 61 => 200] as $orderId => $lineCount) {
    for ($line = 1; $line <= $lineCount; $line++) {
        $largeOrders[] = [
            'orders_id' => $orderId,
            'customers_id' => $orderId,
            'date_purchased' => '2026-09-22 12:00:00',
            'currency' => 'USD',
            'orders_status' => 3,
            'orders_status_name' => 'Delivered',
            'orders_products_id' => ($orderId * 1000) + $line,
            'products_id' => $line,
            'products_model' => 'SKU-' . $line,
            'products_name' => 'Product ' . $line,
            'products_quantity' => 1,
            'final_price' => '1.00',
        ];
    }
}
$GLOBALS['db'] = new RestockFakeDb([], $largeOrders);
$largeClient = new RestockFakeClient();
numinix_seekmodo_push_restock_orders($largeClient, 90);
$eq('large complete orders split into safe batches', 2, count($largeClient->calls));
foreach ($largeClient->calls as $index => $call) {
    $lineCount = array_sum(array_map(
        static fn (array $order): int => count($order['lines']),
        $call['args']['orders']
    ));
    $eq('order batch ' . $index . ' stays within 1000 lines', true, $lineCount <= 1000);
}

$GLOBALS['db'] = new RestockFakeDb(
    [
        ['products_id' => 1, 'products_model' => 'FAST-001', 'products_quantity' => 10, 'products_name' => 'Fast'],
        ['products_id' => 2, 'products_model' => '', 'products_quantity' => 0, 'products_name' => 'Empty'],
        ['products_id' => 3, 'products_model' => 'BACK', 'products_quantity' => -4, 'products_name' => 'Backorder'],
    ],
    []
);
$failedClient = new RestockFakeClient([1]);
$partial = numinix_seekmodo_push_restock_inventory(
    $failedClient,
    2,
    'partial-snapshot',
    '2026-09-23T12:00:00Z'
);
$eq('partial snapshot reports failure', 1, $partial['failed_batches']);
$eq('partial snapshot not finalized', false, $partial['finalized']);
$eq('partial snapshot never calls finalize', 2, count($failedClient->calls));

$cronSource = file_get_contents(
    __DIR__ . '/../zc_plugins/Seekmodo/v1.3.87/catalog/includes/library/Numinix/Seekmodo/CronReconciler.php'
);
$eq(
    'managed daily cron includes restock sync',
    true,
    is_string($cronSource)
        && str_contains($cronSource, 'numinix_seekmodo_sync_restock.php')
        && str_contains($cronSource, '--exclude-statuses=')
);
$syncSource = file_get_contents(
    __DIR__ . '/../zc_plugins/Seekmodo/v1.3.87/catalog/numinix_seekmodo_sync_restock.php'
);
$eq(
    'restock CLI enforces canonical write host',
    true,
    is_string($syncSource)
        && str_contains($syncSource, 'numinix_seekmodo_can_index')
        && !str_contains($syncSource, "HTTP_HOST'] ?? 'localhost'")
);
$installerSource = file_get_contents(
    __DIR__ . '/../zc_plugins/Seekmodo/v1.3.87/Installer/ScriptedInstaller.php'
);
$eq(
    'installer provisions numeric excluded-status configuration',
    true,
    is_string($installerSource)
        && str_contains($installerSource, 'NUMINIX_SEEKMODO_RESTOCK_EXCLUDED_STATUS_IDS')
);

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo "RestockSyncTest: {$passed} assertions passed\n";
