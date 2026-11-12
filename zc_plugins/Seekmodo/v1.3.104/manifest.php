<?php
/**
 * Seekmodo Search connector ???????? manifest.
 *
 * Routes Zen Cart's storefront search, indexer cron, and click beacon
 * through the Seekmodo platform at https://mcp.seekmodo.com. Settings
 * live on the tenant site at https://admin.seekmodo.com ???????? this plugin
 * is intentionally thin and pulls its operational policy down from the
 * gateway each request.
 *
 * The plugin is mode-aware (off / active / shadow / enforce) and
 * degrades gracefully ???????? if the gateway is unreachable while in
 * `enforce` mode, the storefront falls back to the native Zen Cart
 * `LIKE` search per the graceful-degradation contract in the Seekmodo
 * project plan. The default `active` mode hands control to an
 * internal state machine that auto-promotes shadow????????enforce based on
 * observed gateway health and auto-demotes on sustained failures, so
 * operators don't have to flip modes manually.
 */
return [
    'pluginVersion' => 'v1.3.104',
    'pluginName' => 'Seekmodo',
    'pluginDescription' => 'AI product search, typeahead, Seekmodo AI chatbot, live chat, and ticketing for Zen Cart. Faster, more relevant results with learned-to-rank scoring, plus an optional storefront AI Chatbot and cloud Inbox (plan-gated after pairing). One-click pairing via Admin &rarr; Tools &rarr; <b>Connect to Seekmodo</b>; settings on <b>admin.seekmodo.com</b>. Falls back to native Zen Cart search if the platform is unreachable.',
    'pluginAuthor' => 'Numinix',
    'pluginId' => 2441, // zen-cart.com downloads.php?do=file&id=2441
    'zcVersions' => ['v157', 'v158', 'v200'],
    'changelog' => 'v1.3.104 (2026-11-11): SSR SERP prefetch UX — set prefetch-serp on suggest autoboot; skip gateway search telemetry on Sec-Purpose: prefetch. See CHANGELOG.md.',
    'github_repo' => 'https://github.com/numinix/seekmodo-zen-cart-connector',
    'pluginGroups' => [],
];
