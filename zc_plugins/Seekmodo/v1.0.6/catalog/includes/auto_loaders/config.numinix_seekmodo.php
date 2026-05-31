<?php
/**
 * Catalog-side auto-loader for the Numinix Seekmodo connector.
 *
 * Boots the SDK + procedural helpers early enough that:
 *   - includes/classes/class.search.php's numinix_elastic_search_results()
 *     can call numinix_seekmodo_*() on the hot path
 *   - ajax/ajax_search_log.php can mirror the click beacon
 *   - transfer_products.php (CLI) can route the bulk import
 *
 * autoLoadConfig[80] runs after configure.php (so the NUMINIX_SEEKMODO_*
 * constants from TABLE_CONFIGURATION are defined) but before any of the
 * application code that reads them.
 */
$autoLoadConfig[80][] = [
    'autoType' => 'init_script',
    'loadFile' => 'init_numinix_seekmodo.php',
];
