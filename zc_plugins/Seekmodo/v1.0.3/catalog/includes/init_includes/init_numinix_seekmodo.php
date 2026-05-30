<?php
/**
 * Boot the Numinix Seekmodo connector on the catalog (storefront) side.
 *
 * Loads:
 *   1. The class-based SDK (Numinix\Seekmodo\Client + circuit breaker)
 *      via a tiny manual PSR-4-ish loader, so we don't need composer
 *      on the cPanel host.
 *   2. The procedural boot file (numinix_seekmodo_client.php) — this
 *      is what class.search.php / ajax_search_log.php / transfer_products.php
 *      actually call.
 *   3. The vertical sub-libs (search / indexer / events) — lazy-loaded
 *      by the boot file when their respective entry points are reached.
 *
 * No-op when the plugin is installed but MODE=off; the boot file's
 * helpers all return null in that case so the storefront keeps using
 * its existing direct-Typesense path.
 */

if (!defined('IS_ADMIN_FLAG')) {
    // Catalog request — IS_ADMIN_FLAG is undefined here.
}

// Manual class autoloader. The SDK classes live INSIDE the plugin tree
// at zc_plugins/Seekmodo/<ver>/catalog/includes/library/Numinix/Seekmodo/.
// Resolving them relative to this init script's location keeps the
// connector self-contained — no copies under DIR_FS_CATALOG/includes/
// library/ are required (the previous DIR_FS_CATALOG-rooted lookup
// silently failed, leaving class_exists() false and short-circuiting
// every gateway call).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Numinix\\Seekmodo\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $base = __DIR__ . '/../library/Numinix/Seekmodo/';
    $path = $base . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Procedural helpers — same naming convention as numinix_bot_check_client.php.
// All files live INSIDE the plugin tree so the connector is fully
// self-contained (no copies needed under DIR_FS_CATALOG/includes/functions).
// Load order:
//   1. boot file     (defines numinix_seekmodo_enabled / mode / SDK wrappers)
//   2. search lib    (defines numinix_seekmodo_run_search + filter mapping registry)
//   3. typeahead lib (defines numinix_seekmodo_run_typeahead — added in v1.0.3)
//   4. indexer lib   (defines numinix_seekmodo_run_bulk_upsert)
//   5. events lib    (defines numinix_seekmodo_mirror_click / typeahead_click / impression)
//
// Eager-load means the swap-points in the storefront's class.search.php /
// transfer_products.php / ajax_search_log.php / ajax_typeahead.php just
// call function_exists() and don't need to know the plugin's version-
// specific path.
$pluginFns = __DIR__ . '/../functions/';
foreach ([
    'numinix_seekmodo_client.php',
    'numinix_seekmodo_search_lib.php',
    'numinix_seekmodo_typeahead_lib.php',
    'numinix_seekmodo_indexer_lib.php',
    'numinix_seekmodo_events_lib.php',
] as $helper) {
    $path = $pluginFns . $helper;
    if (is_file($path)) {
        require_once $path;
    }
}
