<?php
/**
 * Catalog-side auto-loader for the Numinix Seekmodo connector.
 *
 * Boots in three stages:
 *
 *   1. autoLoadConfig[60] — public-MCP discovery surface
 *      `/.well-known/mcp.json` interceptor. Loaded EARLIEST so the
 *      discovery doc serves before sessions, the messageStack, or
 *      any template / DB context that a normal page render needs.
 *      The interceptor is a no-op for any URI that doesn't match
 *      `.well-known/mcp.json` — it returns inside a single
 *      `preg_match` so the per-request cost on the hot path is
 *      sub-microsecond.
 *
 *   2. autoLoadConfig[80] — loads the SDK + procedural helpers
 *      (init_numinix_seekmodo.php) early enough that any storefront-
 *      level swap-points (a hand-patched `numinix_elastic_search_results`
 *      on legacy v1.5.x forks like redlinestands, an ajax_search_log.php
 *      that calls numinix_seekmodo_mirror_click, etc.) can resolve the
 *      helpers when their callers fire later in the same request.
 *
 *   3. autoLoadConfig[200] — registers the v1.0.9 hot-path notifier
 *      observer (NuminixSeekmodoObserver) and the v1.0.11 head-
 *      injecting discovery observer (NuminixSeekmodoMcpDiscoveryObserver).
 *      Loaded LATE so all dependencies (the procedural helper library,
 *      configure.php constants, $_SESSION, the messageStack, the
 *      database connection) are guaranteed initialised. Registering
 *      these in the `class_loaders` slot is what makes the connector
 *      zero-touch: an unmodified Zen Cart 1.5.8 / 2.0+ storefront
 *      now talks to the gateway with no edits to class.search.php /
 *      ajax_search.php / SERP templates / cart pages, AND every
 *      rendered page advertises its public-MCP endpoint via
 *      `<link rel="mcp-server">` in `<head>` for AI-agent discovery.
 */
$autoLoadConfig[60][] = [
    'autoType' => 'init_script',
    'loadFile' => 'init_numinix_seekmodo_well_known.php',
];

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

$autoLoadConfig[200][] = [
    'autoType' => 'class',
    'loadFile' => 'observers/NuminixSeekmodoMcpDiscoveryObserver.php',
    'classPath' => DIR_WS_CLASSES,
];

$autoLoadConfig[200][] = [
    'autoType'  => 'classInstantiate',
    'className' => 'NuminixSeekmodoMcpDiscoveryObserver',
    'objectName' => 'numinixSeekmodoMcpDiscoveryObserver',
];

// v1.0.21 (SM-606) — universal `<seekmodo-suggest>` head-injecting
// observer. Same slot 200 / `class_loaders` pattern as the MCP
// discovery observer (both hook `NOTIFY_HTML_HEAD_END` and both need
// `numinix_seekmodo_*` helpers + config constants resolved). The
// observer is internally gated on `numinix_seekmodo_enabled()` plus
// the `NUMINIX_SEEKMODO_SUGGEST_ENABLED` / `_USE_LEGACY` constants,
// so this registration is safe to make unconditionally — off-mode /
// unpaired / explicitly-disabled storefronts emit no extra bytes.
$autoLoadConfig[200][] = [
    'autoType' => 'class',
    'loadFile' => 'observers/NuminixSeekmodoSuggestObserver.php',
    'classPath' => DIR_WS_CLASSES,
];

$autoLoadConfig[200][] = [
    'autoType'  => 'classInstantiate',
    'className' => 'NuminixSeekmodoSuggestObserver',
    'objectName' => 'numinixSeekmodoSuggestObserver',
];

// v1.3.0 — delta indexer observer (admin product save + plugin release).
$autoLoadConfig[200][] = [
    'autoType' => 'class',
    'loadFile' => 'observers/NuminixSeekmodoIndexerObserver.php',
    'classPath' => DIR_WS_CLASSES,
];

$autoLoadConfig[200][] = [
    'autoType'  => 'classInstantiate',
    'className' => 'NuminixSeekmodoIndexerObserver',
    'objectName' => 'numinixSeekmodoIndexerObserver',
];
