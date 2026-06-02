<?php
/**
 * Catalog-side auto-loader for the Numinix Seekmodo connector.
 *
 * Boots in two stages:
 *
 *   1. autoLoadConfig[80] — loads the SDK + procedural helpers
 *      (init_numinix_seekmodo.php) early enough that any storefront-
 *      level swap-points (a hand-patched `numinix_elastic_search_results`
 *      on legacy v1.5.x forks like redlinestands, an ajax_search_log.php
 *      that calls numinix_seekmodo_mirror_click, etc.) can resolve the
 *      helpers when their callers fire later in the same request.
 *
 *   2. autoLoadConfig[200] — registers the v1.0.9 notifier observer.
 *      Loaded LATE so all dependencies (the procedural helper library,
 *      configure.php constants, $_SESSION, the messageStack, the
 *      database connection) are guaranteed initialised. Registering
 *      this in the `class_loaders` slot is what makes the connector
 *      zero-touch: an unmodified Zen Cart 1.5.8 / 2.0+ storefront
 *      now talks to the gateway with no edits to class.search.php /
 *      ajax_search.php / SERP templates / cart pages.
 */
$autoLoadConfig[80][] = [
    'autoType' => 'init_script',
    'loadFile' => 'init_numinix_seekmodo.php',
];

$autoLoadConfig[200][] = [
    'autoType' => 'class',
    'loadFile' => 'observers/NuminixSeekmodoObserver.php',
    'classPath' => DIR_WS_CLASSES,
];

$autoLoadConfig[200][] = [
    'autoType'  => 'classInstantiate',
    'className' => 'NuminixSeekmodoObserver',
    'objectName' => 'numinixSeekmodoObserver',
];
