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

// Load admin language pack from the plugin tree BEFORE English
// fallbacks below. Works for Plugin Manager installs and file-only
// rsyncs (language files under zc_plugins/ are not always merged into
// DIR_FS_ADMIN/includes/languages/). english / german / deutsch /
// spanish / french packs ship in admin/includes/languages/*/extra_definitions/.
(static function (): void {
    $dir = '';
    if (isset($_SESSION['language']) && is_string($_SESSION['language'])) {
        $dir = strtolower(trim($_SESSION['language']));
    }
    if ($dir === '' && defined('DEFAULT_LANGUAGE')) {
        $dir = strtolower(trim((string) constant('DEFAULT_LANGUAGE')));
    }
    if ($dir === '') {
        $dir = 'english';
    }
    $base = __DIR__ . '/../languages/' . $dir . '/extra_definitions/';
    $candidates = [
        $base . 'lang.numinix_seekmodo.php',
        $base . 'numinix_seekmodo.php',
    ];
    // German Zen Cart Pro packs sometimes use deutsch instead of german.
    if ($dir === 'german') {
        array_push(
            $candidates,
            __DIR__ . '/../languages/deutsch/extra_definitions/lang.numinix_seekmodo.php',
            __DIR__ . '/../languages/deutsch/extra_definitions/numinix_seekmodo.php'
        );
    }
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        // Swallow UTF-8 BOM / stray whitespace from language packs.
        ob_start();
        $loaded = include $path;
        ob_end_clean();
        if (is_array($loaded)) {
            foreach ($loaded as $key => $value) {
                if (is_string($key) && is_string($value) && !defined($key)) {
                    define($key, $value);
                }
            }
        }
        break;
    }
})();

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
    'numinix_seekmodo_catalog_doc_lib.php',
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

// Soft + sitewide billing notices (unpaid / over_quota / cancelled).
// Soft banner lives on the Connect page only. Sitewide messageStack
// notice is once-per-admin-session until dismissed; dismissals clear
// when cloud recovers (Client::clearCloudSuggestDenial).
if (class_exists('\\Numinix\\Seekmodo\\BillingAdminNotice')
    && class_exists('\\Numinix\\Seekmodo\\Client')
) {
    $seekmodoTenantId = defined('NUMINIX_SEEKMODO_TENANT_ID') ? (string) NUMINIX_SEEKMODO_TENANT_ID : '';
    $seekmodoPaired = (bool) preg_match('~^[a-z0-9][a-z0-9_\-]{1,63}$~', $seekmodoTenantId);
    $seekmodoBillingReason = \Numinix\Seekmodo\BillingAdminNotice::resolveReasonCode();

    if ($seekmodoPaired && $seekmodoBillingReason !== null
        && isset($_GET['seekmodo_dismiss_billing'])
        && (string) $_GET['seekmodo_dismiss_billing'] === '1'
    ) {
        $tokenOk = true;
        if (!empty($_GET['securityToken'])) {
            $tokenOk = isset($_SESSION['securityToken'])
                && hash_equals((string) $_SESSION['securityToken'], (string) $_GET['securityToken']);
        }
        if ($tokenOk) {
            \Numinix\Seekmodo\BillingAdminNotice::markDismissed($seekmodoBillingReason);
        }
    }

    $seekmodoCmd = isset($_GET['cmd']) ? (string) $_GET['cmd'] : '';
    $seekmodoOnConnect = ($seekmodoCmd === \Numinix\Seekmodo\BillingAdminNotice::connectPagePath()
        || $seekmodoCmd === 'numinix_seekmodo_connect');
    $seekmodoOnHome = ($seekmodoCmd === ''
        || $seekmodoCmd === 'index'
        || (defined('FILENAME_DEFAULT') && $seekmodoCmd === (string) constant('FILENAME_DEFAULT')));

    if ($seekmodoPaired
        && !$seekmodoOnConnect
        && $seekmodoOnHome
        && \Numinix\Seekmodo\BillingAdminNotice::shouldShowSitewide(true)
        && isset($messageStack)
        && is_object($messageStack)
        && method_exists($messageStack, 'add')
    ) {
        $copy = \Numinix\Seekmodo\BillingAdminNotice::softCopy($seekmodoBillingReason);
        $connectHref = function_exists('zen_href_link')
            ? zen_href_link(\Numinix\Seekmodo\BillingAdminNotice::connectPagePath())
            : 'index.php?cmd=numinix_seekmodo_connect';
        $dismissQs = [
            'seekmodo_dismiss_billing' => '1',
        ];
        if (!empty($_SESSION['securityToken'])) {
            $dismissQs['securityToken'] = (string) $_SESSION['securityToken'];
        }
        $dismissHref = 'index.php?' . http_build_query($dismissQs);
        $messageStack->add(
            htmlspecialchars($copy, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'utf-8')
            . ' <a href="' . htmlspecialchars($connectHref, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'utf-8') . '">Seekmodo Connect</a>'
            . ' · <a href="' . htmlspecialchars($dismissHref, ENT_QUOTES, defined('CHARSET') ? CHARSET : 'utf-8') . '">Dismiss</a>',
            'caution'
        );
    }
}
