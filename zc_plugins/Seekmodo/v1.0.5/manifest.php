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
    'pluginVersion' => 'v1.0.5',
    'pluginName' => 'Seekmodo',
    'pluginDescription' => 'Routes storefront search, indexer cron, and click beacon through the Seekmodo platform (mcp.seekmodo.com). One-click pairing — the admin Tools menu has a "Connect to Seekmodo" page that round-trips through seekmodo.com to populate tenant ID + HMAC secret automatically; no API keys to copy. Settings (mode, auto-promote, default mode, indexer schedule, timeouts, index batch, debug) are managed on admin.seekmodo.com; the local Zen Cart admin shows a read-only status snapshot. Mode-aware (off | active | shadow | enforce); the default `active` mode self-promotes from shadow to enforce based on gateway health, with native Zen Cart LIKE as the always-on fallback.',
    'pluginAuthor' => 'Numinix',
    'pluginId' => 0,
    'zcVersions' => ['v158'],
    'changelog' => '',
    'github_repo' => 'https://github.com/numinix/seekmodo-zen-cart-connector',
    'pluginGroups' => [],
];
