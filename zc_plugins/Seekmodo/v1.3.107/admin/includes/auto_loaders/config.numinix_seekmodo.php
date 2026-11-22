<?php
/**
 * Admin-side auto-loader for the Numinix Seekmodo connector.
 *
 * Loads the SDK + helpers so admin tools (notably any future
 * seekmodo_dashboard.php) and admin-triggered indexer runs can call
 * the same procedural helpers the catalog side uses. The admin
 * uninstall path (zen_deregister_admin_pages) is in init_*; this
 * auto-loader registers that init script.
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

$autoLoadConfig[200][] = [
    'autoType' => 'init_script',
    'loadFile' => 'init_numinix_seekmodo.php',
];

// v1.3.0 — queue dirty products on admin save / plugin release.
$catalogObservers = __DIR__ . '/../../../catalog/includes/classes/observers/';
if (is_file($catalogObservers . 'NuminixSeekmodoIndexerObserver.php')) {
    require_once $catalogObservers . 'NuminixSeekmodoIndexerObserver.php';
    $autoLoadConfig[200][] = [
        'autoType'  => 'classInstantiate',
        'className' => 'NuminixSeekmodoIndexerObserver',
        'objectName' => 'numinixSeekmodoIndexerObserver',
    ];
}
