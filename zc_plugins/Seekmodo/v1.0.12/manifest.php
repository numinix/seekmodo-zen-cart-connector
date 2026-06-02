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
    'pluginDescription' => 'Replaces Zen Cart\'s built-in search with the Seekmodo platform — faster, more relevant results, learned-to-rank scoring, and zero-config integration with AI assistants like ChatGPT. One-click pairing via Admin &rarr; Tools &rarr; <b>Connect to Seekmodo</b>; ongoing settings (mode, indexer schedule, etc.) are managed on <b>admin.seekmodo.com</b>. Falls back to native Zen Cart search automatically if the platform is unreachable, so your storefront keeps working no matter what.',
    'pluginAuthor' => 'Numinix',
    'pluginId' => 0,
    'zcVersions' => ['v158', 'v200'],
    'changelog' => 'v1.0.12 (2026-06-02): public-MCP discovery now writes a real /.well-known/mcp.json file to disk (with a defensive .htaccess) at pair time, on every snapshot poll, and opportunistically once per hour; works on stock cPanel + subdirectory Zen Cart installs without any .htaccess rewrite. - v1.0.11 (2026-06-02): adds public-MCP (anonymous-tier) discovery so AI agents (ChatGPT, Claude, Cursor, Perplexity) can find this storefront\'s product-search endpoint without operator setup; emits a discovery head tag on every storefront page and answers /.well-known/mcp.json when the web server routes it to PHP. - v1.0.10 (2026-06-01): standalone catalog pusher script (numinix_seekmodo_push_catalog.php) lets unmodified storefronts index without a hand-rolled transfer_products.php cron; default filter-mapping registry now covers Zen Cart core SERP filters; ajax-search-suggest clicks are attributed separately as surface=suggest. - v1.0.9 (2026-05-30): zero-touch notifier observer wires NOTIFY_SEARCH_RESULTS, NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS, NOTIFY_HEADER_START_PRODUCT_INFO, NOTIFY_CART_ADD_CART_END, NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS so storefronts on stock Zen Cart 1.5.8 / 2.0+ work without core file edits. - v1.0.8 (2026-05-29): per-tenant storefront-domain lock (managed at admin.seekmodo.com) short-circuits the connector on non-matching hosts so dev / staging / preview clones can carry the plugin without polluting production. - v1.0.7 (2026-05-26): in-plugin auto-updater (Admin &rarr; Tools &rarr; Seekmodo Updates) with signed-zip verification. - v1.0.6 (2026-05-22): bot-check backend selector. - v1.0.5 (2026-05-18): tenant-wide default-mode + declarative indexer schedule pulled from admin.seekmodo.com.',
    'github_repo' => 'https://github.com/numinix/seekmodo-zen-cart-connector',
    'pluginGroups' => [],
];
