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
    'pluginVersion' => 'v1.3.42',
    'pluginName' => 'Seekmodo',
    'pluginDescription' => 'Replaces Zen Cart\'s built-in search with the Seekmodo platform — faster, more relevant results, learned-to-rank scoring, and zero-config integration with AI assistants like ChatGPT. One-click pairing via Admin &rarr; Tools &rarr; <b>Connect to Seekmodo</b>; ongoing settings (mode, indexer schedule, etc.) are managed on <b>admin.seekmodo.com</b>. Falls back to native Zen Cart search automatically if the platform is unreachable, so your storefront keeps working no matter what.',
    'pluginAuthor' => 'Numinix',
    'pluginId' => 2441, // zen-cart.com downloads.php?do=file&id=2441
    'zcVersions' => ['v157', 'v158', 'v200'],
    'changelog' => 'v1.3.42 (2026-08-02): Drop wget/curl/CLI conversion mirrors; sm_ltr cookie keeps search_event_id across login for buy-now LTR labels. - v1.3.41 (2026-07-31): Define $categories_filter_title on advanced search SERP for Winchester/PHP8 themes (NS-26042). See CHANGELOG.md for earlier releases.',
    'github_repo' => 'https://github.com/numinix/seekmodo-zen-cart-connector',
    'pluginGroups' => [],
];
