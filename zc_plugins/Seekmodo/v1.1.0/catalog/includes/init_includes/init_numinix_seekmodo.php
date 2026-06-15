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
    // Two prefixes live under library/Numinix/:
    //
    //   - `Numinix\Seekmodo\*`    -> library/Numinix/Seekmodo/*
    //     The ZC-platform-coupled classes that have shipped since
    //     v1.0.0 (Client, Pairing, RemoteConfig, AutoPromoter,
    //     ApcuCircuitBreakerStore, EnvProbe, PromotionStore,
    //     UpdateApplier, UpdateClient, WellKnownWriter).
    //
    //   - `Numinix\SeekmodoSdk\*` -> library/Numinix/SeekmodoSdk/*
    //     The shared numinix/seekmodo-connector SDK (PSR-4 root
    //     Numinix\SeekmodoSdk\). Vendored at *build* time by
    //     tools/build_release.py via composer install --no-dev — at
    //     runtime the plugin is composer-free and the directory is
    //     just plain PHP files this autoloader picks up.
    //
    // Resolving paths relative to this init script keeps the plugin
    // self-contained — no copies under DIR_FS_CATALOG/includes/library/
    // are required (the earlier DIR_FS_CATALOG-rooted lookup silently
    // failed, leaving class_exists() false and short-circuiting every
    // gateway call).
    // Longest prefix first so `Numinix\SeekmodoSdk\` matches before
    // the shorter `Numinix\Seekmodo\` (which is also a prefix of it).
    // PHP doesn't allow `__DIR__ . '...'` in a static array initializer
    // on every 8.0 patch we support, so compute the table on first call.
    static $prefixes = null;
    if ($prefixes === null) {
        $libBase = __DIR__ . '/../library/Numinix/';
        $prefixes = [
            'Numinix\\SeekmodoSdk\\' => $libBase . 'SeekmodoSdk/',
            'Numinix\\Seekmodo\\'    => $libBase . 'Seekmodo/',
        ];
    }
    foreach ($prefixes as $prefix => $base) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $path = $base . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
        return;
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
    // Sprint 12 (v1.0.8+) — tenant domain lock. Load FIRST so
    // numinix_seekmodo_is_locked_out() is defined before any of the
    // hot-path entry helpers that call it. The file defines two
    // self-contained helpers (current_host + is_locked_out) and has
    // no side effects, so loading it on every storefront boot adds
    // negligible cost.
    'numinix_seekmodo_locked_domain.php',
    'numinix_seekmodo_client.php',
    // v1.0.16 (search-features-plan Sprint 5 PR 6) — shopper context
    // helpers (sm_pid cookie, Do-Not-Personalize header, customer_id
    // resolver). Loaded BEFORE the search / events libs so their
    // payload builders can call numinix_seekmodo_shopper_context()
    // unconditionally.
    'numinix_seekmodo_shopper_lib.php',
    'numinix_seekmodo_search_lib.php',
    'numinix_seekmodo_typeahead_lib.php',
    // v1.0.19 (search-features-plan Sprint 6 PR 1) -- category
    // landing-page redirect resolver. Loaded between the typeahead
    // and indexer libs because the observer that calls it (hooked
    // on NOTIFY_HEADER_START_ADVANCED_SEARCH_RESULTS) needs the
    // resolver defined before any storefront search request hits
    // the page. The file is internally gated on
    // NUMINIX_SEEKMODO_CATEGORY_REDIRECT_ENABLED so the eager-load
    // adds no measurable cost when the feature is off.
    'numinix_seekmodo_category_redirect_lib.php',
    'numinix_seekmodo_indexer_lib.php',
    'numinix_seekmodo_events_lib.php',
    // v1.0.6 (P1-14 Phase B) — vendored bot-check client. Reads
    // NUMINIX_BOT_CHECK_BACKEND (default 'legacy') and routes
    // classify / nonce.issue / nonce.verify either at the standalone
    // bot-check.numinix.com service or at the gateway's
    // BotCheck\* tools when set to 'gateway'. Every helper inside
    // is wrapped in `if (!function_exists(...))` so legacy tenants
    // that still ship a sibling copy under DIR_FS_CATALOG/includes/
    // functions/ keep working unchanged — the first copy loaded
    // wins. RemoteConfig::writeThrough mirrors the gateway snapshot's
    // `bot_check_backend` field into NUMINIX_BOT_CHECK_BACKEND so
    // operators can flip the backend from admin.seekmodo.com.
    'numinix_bot_check_client.php',
] as $helper) {
    $path = $pluginFns . $helper;
    if (is_file($path)) {
        require_once $path;
    }
}
