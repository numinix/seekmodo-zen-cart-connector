<?php
/**
 * Seekmodo Search connector ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â manifest.
 *
 * Routes Zen Cart's storefront search, indexer cron, and click beacon
 * through the Seekmodo platform at https://mcp.seekmodo.com. Settings
 * live on the tenant site at https://admin.seekmodo.com ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â this plugin
 * is intentionally thin and pulls its operational policy down from the
 * gateway each request.
 *
 * The plugin is mode-aware (off / active / shadow / enforce) and
 * degrades gracefully ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â if the gateway is unreachable while in
 * `enforce` mode, the storefront falls back to the native Zen Cart
 * `LIKE` search per the graceful-degradation contract in the Seekmodo
 * project plan. The default `active` mode hands control to an
 * internal state machine that auto-promotes shadowÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢enforce based on
 * observed gateway health and auto-demotes on sustained failures, so
 * operators don't have to flip modes manually.
 */
return [
    'pluginVersion' => 'v1.3.65',
    'pluginName' => 'Seekmodo',
    'pluginDescription' => 'Replaces Zen Cart\'s built-in search with the Seekmodo platform ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â faster, more relevant results, learned-to-rank scoring, and zero-config integration with AI assistants like ChatGPT. One-click pairing via Admin &rarr; Tools &rarr; <b>Connect to Seekmodo</b>; ongoing settings (mode, indexer schedule, etc.) are managed on <b>admin.seekmodo.com</b>. Falls back to native Zen Cart search automatically if the platform is unreachable, so your storefront keeps working no matter what.',
    'pluginAuthor' => 'Numinix',
    'pluginId' => 2441, // zen-cart.com downloads.php?do=file&id=2441
    'zcVersions' => ['v157', 'v158', 'v200'],
    'changelog' => 'v1.3.65 (2026-08-14): Daily unpaid-recovery recheck - shouldPreferLocalSuggest() now self-heals a stuck trial_expired/over_quota/cancelled sticky at most once/day via a forced tenant.snapshot pull, so resubscribe or an operator trial extension restores cloud suggest without the merchant needing to hit Refresh snapshot. See CHANGELOG.md.',
    'github_repo' => 'https://github.com/numinix/seekmodo-zen-cart-connector',
    'pluginGroups' => [],
];
