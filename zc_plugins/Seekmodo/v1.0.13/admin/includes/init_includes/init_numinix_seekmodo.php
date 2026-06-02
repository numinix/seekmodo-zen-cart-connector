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
// Sprint 4 PR 2 — sibling Updates page constants.
if (!defined('FILENAME_NUMINIX_SEEKMODO_UPDATES')) {
    define('FILENAME_NUMINIX_SEEKMODO_UPDATES', 'numinix_seekmodo_updates');
}
if (!defined('BOX_TOOLS_NUMINIX_SEEKMODO_UPDATES')) {
    define('BOX_TOOLS_NUMINIX_SEEKMODO_UPDATES', 'Seekmodo Updates');
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

// Sprint 4 PR 4 — finalise pending in-place upgrades.  When the
// admin Updates page applies a new version it drops a
// `.pending-upgrade` sentinel in the plugin root because the live
// page is already running off the OLD version's bytes.  The next
// admin page-load (i.e. this init script) sees the sentinel,
// re-runs the new version's ScriptedInstaller upgrade entry-point
// under the proper Zen Cart globals, and removes the sentinel.
// Keeping the indirection out of the Updates page itself avoids
// re-entrancy bugs in opcache when the .php files under us get
// swapped mid-request.
$pendingUpgradePath = __DIR__ . '/../../../../.pending-upgrade';
if (is_file($pendingUpgradePath)) {
    $rawPending = trim((string)file_get_contents($pendingUpgradePath));
    if ($rawPending !== '' && preg_match('~^v\d+\.\d+\.\d+$~', $rawPending) === 1) {
        $newInstaller = __DIR__ . '/../../../../' . $rawPending . '/Installer/ScriptedInstaller.php';
        if (is_file($newInstaller)) {
            // ScriptedInstaller relies on $pluginManager being
            // present; if it's not, we silently skip — the next
            // Plugin Manager refresh will pick the new version up
            // anyway (Zen Cart 1.5.8 detects manifest.php on every
            // admin login).
            if (isset($pluginManager) && is_object($pluginManager)) {
                require_once $newInstaller;
                if (class_exists('\\Numinix\\Seekmodo\\Installer\\ScriptedInstaller')) {
                    try {
                        $installer = new \Numinix\Seekmodo\Installer\ScriptedInstaller($pluginManager, $db);
                        if (method_exists($installer, 'executeUpgrade')) {
                            $installer->executeUpgrade();
                        } elseif (method_exists($installer, 'executeInstall')) {
                            $installer->executeInstall();
                        }
                    } catch (\Throwable $e) {
                        error_log('numinix-seekmodo: pending-upgrade for ' . $rawPending . ' failed: ' . $e->getMessage());
                    }
                }
            }
        }
    }
    @unlink($pendingUpgradePath);
}
