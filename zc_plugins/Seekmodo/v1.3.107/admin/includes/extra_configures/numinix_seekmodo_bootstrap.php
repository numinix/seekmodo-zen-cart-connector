<?php
/**
 * Seekmodo — load admin helpers from the plugin tree.
 *
 * Zen Cart's admin bootstrap only globs extra_functions from the core
 * admin directory. Plugin copies under
 * zc_plugins/…/admin/includes/functions/extra_functions/ are not
 * picked up automatically; extra_configures from installed plugins are
 * loaded on every admin request (application_bootstrap.php).
 */
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

$seekmodoExtraFunctionsDir = dirname(__DIR__) . '/functions/extra_functions/';
$seekmodoAdminPages = $seekmodoExtraFunctionsDir . 'numinix_seekmodo_admin_pages.php';
if (is_file($seekmodoAdminPages)) {
    require_once $seekmodoAdminPages;
    if (function_exists('numinix_seekmodo_ensure_admin_pages')) {
        numinix_seekmodo_ensure_admin_pages();
    }
}
