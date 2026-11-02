<?php
/**
 * Indexer observer — stamps dirty / delete queues when catalog rows change.
 *
 * Hooks admin product save/update and Numinix plugin releases so delta
 * ticks pick up changes within ~15 minutes without a full push_catalog run.
 */
declare(strict_types=1);

if (!class_exists('base', false)) {
    return;
}

final class NuminixSeekmodoIndexerObserver extends base
{
    public function __construct()
    {
        $this->attach(
            $this,
            [
                'NOTIFY_MODULES_UPDATE_PRODUCT_END',
                'NOTIFY_NEW_PLUGIN_RELEASE',
            ]
        );
    }

  /**
   * @param base $notifier
   * @param array<string, mixed>|int $eventArray
   */
    public function update(&$notifier, $eventArray): void
    {
        if (!function_exists('numinix_seekmodo_enabled') || !numinix_seekmodo_enabled()) {
            return;
        }
        if (function_exists('numinix_seekmodo_mode') && numinix_seekmodo_mode() === 'off') {
            return;
        }
        $productsId = $this->resolveProductsId($eventArray);
        if ($productsId <= 0) {
            return;
        }
        $this->queueProduct($productsId);
    }

    /**
     * @param array<string, mixed>|int $eventArray
     */
    private function resolveProductsId($eventArray): int
    {
        if (is_int($eventArray)) {
            return $eventArray;
        }
        if (!is_array($eventArray)) {
            return 0;
        }
        return (int) ($eventArray['products_id'] ?? 0);
    }

    private function queueProduct(int $productsId): void
    {
        global $db;
        $status = 0;
        if (isset($db) && is_object($db)) {
            try {
                $row = $db->Execute(
                    'SELECT products_status FROM ' . TABLE_PRODUCTS
                    . ' WHERE products_id = ' . $productsId . ' LIMIT 1'
                );
                if ($row && !$row->EOF) {
                    $status = (int) $row->fields['products_status'];
                }
            } catch (\Throwable $e) {
                $status = 1;
            }
        }
        if ($status !== 1) {
            if (function_exists('numinix_seekmodo_queue_catalog_delete')) {
                numinix_seekmodo_queue_catalog_delete($productsId);
            }
            return;
        }
        if (function_exists('numinix_seekmodo_queue_catalog_dirty')) {
            numinix_seekmodo_queue_catalog_dirty($productsId);
        }
    }
}
