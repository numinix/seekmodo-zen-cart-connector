<?php
/**
 * Admin-side init for the Numinix Seekmodo connector.
 *
 * Mirrors the catalog-side init: registers the autoloader for
 * Numinix\Seekmodo\* and pulls in the procedural helpers. Admin
 * tools that want to invoke a manual reindex through the gateway
 * just include includes/functions/numinix_seekmodo_indexer_lib.php
 * and call numinix_seekmodo_index_chunked(...).
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

// Admin page filename + menu label. Referenced by
// zen_register_admin_pages('numinixSeekmodoConnect', ...) in the
// installer, and by zen_href_link() at runtime.
if (!defined('FILENAME_NUMINIX_SEEKMODO_CONNECT')) {
    define('FILENAME_NUMINIX_SEEKMODO_CONNECT', 'numinix_seekmodo_connect');
}
if (!defined('BOX_TOOLS_NUMINIX_SEEKMODO_CONNECT')) {
    define('BOX_TOOLS_NUMINIX_SEEKMODO_CONNECT', 'Connect to Seekmodo');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Numinix\\Seekmodo\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    // SDK classes live INSIDE the plugin tree (catalog/includes/library/
    // Numinix/Seekmodo/). Resolving relative to this admin-side init
    // script keeps the connector self-contained — no copies under
    // DIR_FS_CATALOG/includes/library/ are required.
    $base = __DIR__ . '/../../../catalog/includes/library/Numinix/Seekmodo/';
    $path = $base . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Eager-load all four catalog-side helpers so admin tools (notably
// admin-triggered indexer runs) can call the same procedural API the
// catalog auto-loader exposes. Helpers live inside the plugin tree;
// no copies under DIR_FS_CATALOG/includes/functions/ are required.
$pluginCatalogFns = __DIR__ . '/../../../catalog/includes/functions/';
foreach ([
    'numinix_seekmodo_client.php',
    'numinix_seekmodo_search_lib.php',
    'numinix_seekmodo_indexer_lib.php',
    'numinix_seekmodo_events_lib.php',
] as $helper) {
    $path = $pluginCatalogFns . $helper;
    if (is_file($path)) {
        require_once $path;
    }
}
