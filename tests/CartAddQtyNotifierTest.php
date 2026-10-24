<?php
/**
 * Numinix NOTIFIER_CART_ADD_CART_END passes (null, products_id, qty).
 * SEO product URLs also set $_GET['products_id']. The observer must not
 * treat that request id as proof that param1 is the product, or param2
 * (the real products_id) is stored as the cart quantity.
 *
 *     php tests/CartAddQtyNotifierTest.php
 */

declare(strict_types=1);

$errors = [];
$passed = 0;

if (!class_exists('base')) {
    abstract class base
    {
        public array $attached = [];
        public function attach($observer, array $eventIDs): void
        {
            foreach ($eventIDs as $eid) {
                $this->attached[] = [$eid];
            }
        }
    }
}

if (!function_exists('numinix_seekmodo_log_append')) {
    function numinix_seekmodo_log_append(string $msg, array $ctx = []): void {}
}
if (!function_exists('numinix_seekmodo_mirror_add_to_cart')) {
    function numinix_seekmodo_mirror_add_to_cart(int $productsId, array $opts = []): void
    {
        $GLOBALS['atc_calls'][] = ['products_id' => $productsId, 'opts' => $opts];
    }
}

require_once __DIR__ . '/../zc_plugins/Seekmodo/v1.3.94/catalog/includes/classes/observers/NuminixSeekmodoObserver.php';

function atc_assert(bool $cond, string $label): void
{
    global $errors, $passed;
    if ($cond) {
        $passed++;
        echo "PASS  $label\n";
        return;
    }
    $errors[] = $label;
    echo "FAIL  $label\n";
}

function atc_fire($param1, $param2, $param3): array
{
    $GLOBALS['atc_calls'] = [];
    $obs = new NuminixSeekmodoObserver();
    $class = new class extends base {};
    $obs->update($class, 'NOTIFIER_CART_ADD_CART_END', $param1, $param2, $param3);
    return $GLOBALS['atc_calls'];
}

// Direct product-page add. Form qty is 1. Notifier args are
// (null, products_id, qty). GET still carries the SEO products_id.
$_GET = ['products_id' => '1231'];
$_POST = ['products_id' => '1231', 'cart_quantity' => '1'];
$null = null;
$pid = 1231;
$qty = 1;
$calls = atc_fire($null, $pid, $qty);
atc_assert(count($calls) === 1, 'notifier fires one add_to_cart');
atc_assert(($calls[0]['products_id'] ?? 0) === 1231, 'products_id stays 1231');
atc_assert(($calls[0]['opts']['qty'] ?? null) === 1, 'qty is the cart quantity, not the product id');

// Stock Zen Cart: (products_id, qty) with the same SEO query string present.
$_GET = ['products_id' => '77'];
$_POST = ['cart_quantity' => '3'];
$stockId = 77;
$stockQty = 3;
$ignored = null;
$calls = atc_fire($stockId, $stockQty, $ignored);
atc_assert(count($calls) === 1, 'stock notifier fires one add_to_cart');
atc_assert(($calls[0]['products_id'] ?? 0) === 77, 'stock products_id');
atc_assert(($calls[0]['opts']['qty'] ?? null) === 3, 'stock qty');

echo "\n$passed passed, " . count($errors) . " failed\n";
exit($errors === [] ? 0 : 1);
