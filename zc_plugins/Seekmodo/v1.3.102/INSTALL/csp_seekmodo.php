<?php
/**
 * Seekmodo Search connector -- Content-Security-Policy drop-in.
 *
 * COPY THIS FILE TO YOUR STOREFRONT'S `includes/extra_csp_policies/`
 * if your store emits a CSP header that does not already whitelist
 * `mcp.seekmodo.com`. (Numinix-managed Zen Cart storefronts do; most
 * vanilla Zen Cart installs do not emit a CSP at all and can ignore
 * this file.)
 *
 * Why this is required
 * --------------------
 * The `<seekmodo-suggest>` web component calls the production gateway
 * directly from the storefront for typeahead suggestions, the AI
 * search panel, image search, and event reporting. The gateway lives
 * at `https://mcp.seekmodo.com` and CORS-whitelists every storefront
 * paired with a tenant.
 *
 * Without these CSP entries the browser blocks the cross-origin
 * fetch with a generic `TypeError: Failed to fetch` -- the widget's
 * SDK then logs `[seekmodo-suggest] fetch failed Seekmodo network
 * failure: Failed to fetch` and the dropdown stays empty.
 *
 * Numinix CSP loader contract
 * ---------------------------
 * Numinix-style Zen Cart sites bootstrap their CSP by:
 *
 *   1. Loading `includes/csp_policy_config.php` to seed the
 *      `$csp_policy` array.
 *   2. Globbing `includes/extra_csp_policies/*.php` so plugins and
 *      payment modules can append origins to the directives.
 *
 * This file follows that drop-in convention -- it expects to be
 * `include`-d from `application_top.php` after `$csp_policy` is
 * defined.
 *
 * Directives
 * ----------
 *   connect-src   -- the SDK's POST to /v1/suggest, /v1/search,
 *                    /v1/recommend.*, /v1/events. Required for every
 *                    storefront feature the bundle exposes.
 *   script-src    -- in case a tenant ever loads the bundled SDK
 *                    from the gateway origin instead of the in-repo
 *                    plugin bundle (operator override via the
 *                    `seekmodo:bundle` meta).
 *   img-src       -- omitted on purpose; product / category images
 *                    are served from the storefront's own CDN, not
 *                    from the gateway.
 *
 * The wildcard `*.seekmodo.com` on connect-src covers any future
 * regional shards (`eu.seekmodo.com`, etc.) without another drop-in.
 *
 * Idempotent: origins are appended only when missing, so re-including
 * this drop-in (or stacking multiple copies under extra_csp_policies/)
 * cannot inflate the CSP header with duplicate Seekmodo hosts.
 */

if (!isset($csp_policy) || !is_array($csp_policy)) {
    return;
}

$seekmodoCspAppend = static function (array &$bucket, $origin) {
    if (!in_array($origin, $bucket, true)) {
        $bucket[] = $origin;
    }
};

if (!isset($csp_policy['script-src']) || !is_array($csp_policy['script-src'])) {
    $csp_policy['script-src'] = [];
}
if (!isset($csp_policy['connect-src']) || !is_array($csp_policy['connect-src'])) {
    $csp_policy['connect-src'] = [];
}

$seekmodoCspAppend($csp_policy['script-src'], 'mcp.seekmodo.com');
$seekmodoCspAppend($csp_policy['connect-src'], 'mcp.seekmodo.com');
$seekmodoCspAppend($csp_policy['connect-src'], '*.seekmodo.com');
