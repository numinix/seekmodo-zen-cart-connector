<?php
/**
 * Seekmodo Search connector — manifest.
 *
 * Routes Zen Cart's storefront search, indexer cron, and click beacon
 * through the Seekmodo platform at https://mcp.seekmodo.com. Settings
 * live on the tenant site at https://admin.seekmodo.com — this plugin
 * is intentionally thin and pulls its operational policy down from the
 * gateway each request.
 *
 * The plugin is mode-aware (off / active / shadow / enforce) and
 * degrades gracefully — if the gateway is unreachable while in
 * `enforce` mode, the storefront falls back to the native Zen Cart
 * `LIKE` search per the graceful-degradation contract in the Seekmodo
 * project plan. The default `active` mode hands control to an
 * internal state machine that auto-promotes shadow→enforce based on
 * observed gateway health and auto-demotes on sustained failures, so
 * operators don't have to flip modes manually.
 */
return [
    'pluginVersion' => 'v1.0.12',
    'pluginName' => 'Seekmodo',
    'pluginDescription' => 'Routes storefront search, indexer cron, and click beacon through the Seekmodo platform (mcp.seekmodo.com). One-click pairing — the admin Tools menu has a "Connect to Seekmodo" page that round-trips through seekmodo.com to populate tenant ID + HMAC secret automatically; no API keys to copy. Settings (mode, auto-promote, default mode, indexer schedule, timeouts, index batch, debug) are managed on admin.seekmodo.com; the local Zen Cart admin shows a read-only status snapshot. Mode-aware (off | active | shadow | enforce); the default `active` mode self-promotes from shadow to enforce based on gateway health, with native Zen Cart LIKE as the always-on fallback. Zero-touch integration: a Zen Cart notifier observer (NOTIFY_SEARCH_RESULTS / NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS / NOTIFY_HEADER_START_PRODUCT_INFO / NOTIFY_CART_ADD_CART_END / NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS) handles all hot-path swap-points so storefronts on stock Zen Cart 1.5.8 / 2.0+ work without core file edits. v1.0.10 adds a standalone catalog pusher (`numinix_seekmodo_push_catalog.php`) so unmodified storefronts can index without a hand-rolled `transfer_products.php` cron, extends the default filter-mapping registry to cover Zen Cart core SERP filters (manufacturers_id, categories_id, cPath, pfrom/pto), and recognises ajax-search-suggest clicks as a separate `surface=suggest` attribution path. v1.0.11 adds public-MCP (anonymous-tier) discovery so AI agents like ChatGPT can find the storefront\'s product-search endpoint without merchant intervention: a `<link rel="mcp-server">` head tag + a `/.well-known/mcp.json` JSON document, both advertising the gateway\'s `https://<tenant>.mcp.seekmodo.com/mcp` subdomain (anonymous tier — no auth, per-IP rate-limited, search-only). v1.0.12 makes that discovery work on stock cPanel / shared-hosting setups by physically writing `.well-known/mcp.json` (plus a defence-in-depth `.htaccess`) to disk at pair time, on every snapshot poll, and opportunistically once per hour from the head observer — no `.htaccess` rewrite required, and it works whether Zen Cart is installed at site root or in a `/catalog/` subdirectory (writes to both the catalog dir AND `$_SERVER[\'DOCUMENT_ROOT\']` when they differ).',
    'pluginAuthor' => 'Numinix',
    'pluginId' => 0,
    'zcVersions' => ['v158', 'v200'],
    'changelog' => '',
    'github_repo' => 'https://github.com/numinix/seekmodo-zen-cart-connector',
    'pluginGroups' => [],
];
