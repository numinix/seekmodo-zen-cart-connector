<?php

declare(strict_types=1);

/**
 * v1.0.21 (SM-606) — universal suggest-widget enqueue.
 * v1.0.22 (2026-06-14, fix-pack #4) — autoboot now wires a
 *   `seekmodo-suggest:row-click` listener that handles navigation. The
 *   web component is intentionally inert on click (it emits a
 *   CustomEvent and lets the host decide where to send the shopper);
 *   without this listener clicks on product rows visually highlighted
 *   the row but produced no navigation at all (the bug reported on
 *   redlinestands.com / poco-marine.com / numinix.com / numinix.ca
 *   after the v1.0.22 universal-suggest rollout).
 * v1.0.22 (2026-06-13) — bundle URL now points at the plugin's own
 *   on-disk location (`/catalog/zc_plugins/Seekmodo/v<version>/catalog/...`)
 *   instead of the live template tree. Zen Cart 2.x's plugin loader
 *   merges PHP includes via auto_loaders but does NOT merge static
 *   assets into the live catalog filesystem, so the v1.0.21 bundle URL
 *   returned 404/406 on every storefront. The `.js` extension is
 *   allowed by ZC's stock `zc_plugins/.htaccess` so the file is
 *   reachable in-place — no per-tenant copy step needed. Catalog-root
 *   PHP shims (`numinix_seekmodo_suggest.php` etc.) still need to be
 *   deployed at the live catalog root because the same `.htaccess`
 *   denies direct HTTP access to PHP files inside `zc_plugins/`.
 *
 * Hooks `NOTIFY_HTML_HEAD_END` and emits:
 *
 *   1. `<meta name="seekmodo:tenant|gateway|refresh|token">` so the
 *      bundled SDK inside `<seekmodo-suggest>` can resolve config on
 *      first access (token is mint-cached for ~5 min via APCu; refresh
 *      URL is `catalog/numinix_seekmodo_suggest.php?action=browser-token`
 *      so a long-running tab can mint a fresh JWT without a page
 *      reload).
 *   2. `<script src=".../seekmodo_suggest.bundle.js">` — the
 *      self-registering web-component bundle copied verbatim from
 *      `@seekmodo/web-components` (~7.25 KB gzip). Served from the
 *      plugin's versioned dir so the file is always reachable without
 *      a per-tenant deploy step that copies static assets into the
 *      active template's `jscript/` folder.
 *   3. A tiny inline autoboot script that walks the same
 *      `input[name="keyword"]`, `input#keyword`,
 *      `input[data-seekmodo-typeahead]` selectors the v1.0.20 dropdown
 *      auto-attached to, and inserts a `<seekmodo-suggest>` sibling
 *      after each match.
 *
 * Gate (in priority order):
 *
 *   - {@see numinix_seekmodo_enabled()} (the same gate the search /
 *     typeahead libraries use — off mode, unpaired, domain-locked,
 *     missing PHP extensions all return false).
 *   - `NUMINIX_SEEKMODO_SUGGEST_ENABLED` constant — defaults to true.
 *     Operators set this to false in a tenant overrides file to
 *     suppress the suggest UI site-wide regardless of pairing.
 *     v1.3.69 also installs this as a configuration row (true).
 *   - `NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY` constant — default false
 *     (the subscribed `<seekmodo-suggest>` split-rail widget). When
 *     true, emits the legacy v1.0.20 flat-row vanilla-JS dropdown
 *     (`seekmodo_typeahead.legacy.js`) instead of the new bundle.
 *     Both files are shipped; the choice is mutually exclusive.
 *     v1.3.69 installs the row as false and resets leftover true
 *     values from recovery stamps. Billing 402/cancelled still
 *     forces the same-origin legacy path via shouldPreferLocalSuggest().
 *
 * Failure semantics:
 *
 *   - Every code path is best-effort. A failure to mint the
 *     gateway-direct browser token degrades gracefully (the bundle
 *     hits the refresh URL on first call and re-tries).
 *   - All output is escaped with `htmlspecialchars(ENT_QUOTES)`. The
 *     bundle URL is constructed from a `DIR_WS_CATALOG`-rooted
 *     literal path, never from user input.
 */
final class NuminixSeekmodoSuggestObserver extends base
{
    /** @var string */
    private $cachedHeadHtml = '';
    /** @var bool */
    private $cachedHeadComputed = false;

    public function __construct()
    {
        $this->attach($this, ['NOTIFY_HTML_HEAD_END']);
    }

    public function update(&$class, $eventID, &$param1 = null, &$param2 = null, &$param3 = null): void
    {
        if ($eventID !== 'NOTIFY_HTML_HEAD_END') {
            return;
        }
        try {
            $html = $this->buildHeadHtml();
            if ($html !== '') {
                echo $html;
            }
        } catch (\Throwable $e) {
            // Storefront-grade: never let a head-injection issue 500
            // the page render. Search box still works (form submit
            // hits the real SERP) — we just lose the dropdown.
            return;
        }
    }

    /**
     * Build (or return the cached) head-tag HTML. The observer can fire
     * more than once per request on some Zen Cart layouts (e.g. when a
     * popup template re-includes the header), so we memoize.
     */
    private function buildHeadHtml(): string
    {
        if ($this->cachedHeadComputed) {
            return $this->cachedHeadHtml;
        }
        $this->cachedHeadComputed = true;

        if (!$this->isActive()) {
            return $this->cachedHeadHtml; // empty
        }

        $useLegacy = $this->useLegacy();
        $bundleSrc = $this->bundleSrc($useLegacy);
        if ($bundleSrc === '') {
            return $this->cachedHeadHtml; // empty
        }

        $out = "\n";

        // Meta tags are only needed for the new web-component path —
        // the legacy v1.0.20 JS reads its config from inline localized
        // vars instead.
        if (!$useLegacy) {
            $out .= $this->metaTags();
            $corsSrc = $this->corsNoticeSrc();
            if ($corsSrc !== '') {
                $corsAttr = htmlspecialchars($corsSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $out .= '<script src="' . $corsAttr . '"></script>' . "\n";
            }
        }

        // Script tag — `defer` so the head-tag parse doesn't block; the
        // bundle is idempotent on `customElements.define` so repeated
        // loads (multiple iframes, AJAX-injected page sections) are
        // safe. `onerror` surfaces a CORS / load failure inline when
        // the bundle 404s or is blocked cross-origin.
        $srcAttr = htmlspecialchars($bundleSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $onerror = $useLegacy
            ? ''
            : ' onerror="window.seekmodoScriptLoadFailed&amp;&amp;window.seekmodoScriptLoadFailed()"';
        $out .= '<script src="' . $srcAttr . '" defer' . $onerror . '></script>' . "\n";

        // Inline autoboot for the new bundle. The legacy path's
        // file already wires itself to the inputs — no autoboot
        // needed.
        if (!$useLegacy) {
            $out .= $this->autobootScript();
        } else {
            // Legacy typeahead reads section headings from
            // window.SeekmodoSuggestLabels (same packs as the widget).
            $labelsJson = json_encode(
                $this->suggestLabels(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if (is_string($labelsJson) && $labelsJson !== '') {
                $out .= '<script>window.SeekmodoSuggestLabels='
                    . $labelsJson
                    . ';</script>' . "\n";
            }
            $shim = $this->suggestShimUrl();
            if ($shim !== '') {
                $out .= '<script>window.SeekmodoSuggestEndpoint='
                    . json_encode($shim, JSON_UNESCAPED_SLASHES)
                    . ';</script>' . "\n";
            }
        }

        $this->cachedHeadHtml = $out;

        return $this->cachedHeadHtml;
    }

    /**
     * Whether we should emit anything at all this request. Returns
     * false when the connector is off / unpaired / locked-out, when
     * the suggest UI has been explicitly disabled, or when we're not
     * in a public storefront context (admin / CLI).
     */
    private function isActive(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        // Enhanced Native (MODE=off / unpaired): still emit local suggest UI.
        $gatewayOn = function_exists('numinix_seekmodo_enabled') && numinix_seekmodo_enabled();
        $enOn = function_exists('numinix_seekmodo_enhanced_native_enabled')
            && numinix_seekmodo_enhanced_native_enabled();
        if (!$gatewayOn && !$enOn) {
            return false;
        }
        if (defined('NUMINIX_SEEKMODO_SUGGEST_ENABLED')) {
            $v = (string) constant('NUMINIX_SEEKMODO_SUGGEST_ENABLED');
            if (in_array(strtolower($v), ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return true;
    }

    private function useLegacy(): bool
    {
        // After a billing denial (402 trial_expired / over_quota) or
        // cancelled tenant, serve the PHP/EN typeahead path only —
        // no browser→gateway /v1/suggest calls until cloud suggest
        // succeeds again.
        if (
            class_exists('\\Numinix\\Seekmodo\\Client')
            && \Numinix\Seekmodo\Client::shouldPreferLocalSuggest()
        ) {
            return true;
        }
        // Subscribed / paired default is the split-rail widget.
        // Missing constant (and installer default) is false.
        if (!defined('NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY')) {
            return false;
        }
        $v = (string) constant('NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY');

        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Resolve the URL for whichever JS file should ship this request.
     * Returns '' when DIR_WS_CATALOG isn't defined (which never happens
     * in a real storefront page) so the caller can short-circuit.
     *
     * v1.0.22 fix: bundle is served from the plugin's versioned dir
     * (`/catalog/zc_plugins/Seekmodo/v<version>/catalog/...`). Zen Cart
     * 2.x plugin auto_loaders merge PHP includes but NOT static assets
     * into the live template tree, so the v1.0.21 URL
     * (`/catalog/includes/templates/template_default/jscript/...`) was
     * always a 404/406 on every storefront. Pointing at the plugin's
     * own on-disk path makes the bundle reachable without requiring
     * each tenant to manually copy `.js` files into the active template.
     */
    private function bundleSrc(bool $useLegacy): string
    {
        if (!defined('DIR_WS_CATALOG')) {
            return '';
        }
        $base = (string) constant('DIR_WS_CATALOG');
        $version = $this->pluginVersion();
        $file = $useLegacy
            ? 'seekmodo_typeahead.legacy.js'
            : 'seekmodo_suggest.bundle.js';

        $url = $base . 'zc_plugins/Seekmodo/' . $version
            . '/catalog/includes/templates/template_default/jscript/' . $file;
        // Bust Cloudflare / browser year-long cache on plugin JS updates
        // (legacy + modern).
        $disk = dirname(__DIR__, 4)
            . '/catalog/includes/templates/template_default/jscript/' . $file;
        if (is_readable($disk)) {
            $url .= '?v=' . (string) filemtime($disk);
        }

        return $url;
    }

    /**
     * Cache-buster token for suggest product thumbnails. Bumped on
     * every plugin JS rebuild so returning visitors don't keep stale
     * empty/broken CDN image responses in `_thumbHydrateCache`.
     */
    private function bundleImageVer(): string
    {
        if (!defined('DIR_WS_CATALOG')) {
            return $this->pluginVersion();
        }
        $disk = dirname(__DIR__, 4)
            . '/catalog/includes/templates/template_default/jscript/seekmodo_suggest.bundle.js';
        if (is_readable($disk)) {
            return (string) filemtime($disk);
        }
        return $this->pluginVersion();
    }

    /**
     * CORS-block notice helper — load before the suggest bundle so
     * script `onerror` and gateway fetch failures can paint an inline
     * message where the dropdown would appear.
     */
    private function corsNoticeSrc(): string
    {
        if (!defined('DIR_WS_CATALOG')) {
            return '';
        }
        $base = (string) constant('DIR_WS_CATALOG');
        $version = $this->pluginVersion();

        return $base . 'zc_plugins/Seekmodo/' . $version
            . '/catalog/includes/templates/template_default/jscript/seekmodo_cors_notice.js';
    }

    /**
     * Derive the plugin's version directory name from `__DIR__`.
     *
     * Observer file lives at
     *   zc_plugins/Seekmodo/v<version>/catalog/includes/classes/observers/NuminixSeekmodoSuggestObserver.php
     * `__DIR__` is the `observers/` directory, so FOUR `dirname()` calls
     * land on `zc_plugins/Seekmodo/v<version>` (the version directory we
     * want). Earlier v1.0.22 through v1.1.1 used `dirname(__DIR__, 5)`
     * which walks one level too high and lands on `zc_plugins/Seekmodo`
     * — `basename()` then returns the literal string "Seekmodo" (no
     * leading "v"), the guard rejects it as non-version, and the method
     * silently fell back to the hard-coded constant below. v1.0.22
     * happened to work because its fallback was "v1.0.22"; v1.1.0 /
     * v1.1.1 kept that same fallback string, so tenants who upgraded
     * the active version in `plugin_control` to v1.1.1 still got the
     * v1.0.22 (narrow, 320 px) suggest bundle URL on every page and
     * never saw the wider 480 px dropdown the new release was supposed
     * to ship.
     * Falls back to a sane on-disk constant when the layout is
     * unexpected (defensive — should never trigger in a real install).
     */
    private function pluginVersion(): string
    {
        $versionDir = dirname(__DIR__, 4);
        $version = basename($versionDir);
        if ($version !== '' && $version !== '/' && $version[0] === 'v') {
            return $version;
        }

        return 'v1.3.72';
    }

    /**
     * Emit the SDK meta-tag block. Tenant + gateway are always
     * emitted; refresh URL points at the existing
     * `numinix_seekmodo_suggest.php` PHP shim (which already speaks
     * `action=browser-token` per the install-time route hook); inline
     * token is best-effort (skipped when minting fails so the bundle
     * falls back to the refresh URL).
     */
    private function metaTags(): string
    {
        $tenant = $this->tenantId();
        if ($tenant === '') {
            return '';
        }
        $gateway = $this->gatewayBase();
        $refresh = $this->refreshUrl();
        $token   = $this->browserToken();

        $emit = static function (string $name, string $content): string {
            if ($content === '') {
                return '';
            }
            $n = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $c = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return '<meta name="' . $n . '" content="' . $c . '">' . "\n";
        };

        $out  = $emit('seekmodo:tenant', $tenant);
        $out .= $emit('seekmodo:gateway', $gateway);
        $out .= $emit('seekmodo:refresh', $refresh);
        $out .= $emit('seekmodo:token',   $token);

        return $out;
    }

    private function tenantId(): string
    {
        return defined('NUMINIX_SEEKMODO_TENANT_ID')
            ? trim((string) constant('NUMINIX_SEEKMODO_TENANT_ID'))
            : '';
    }

    private function gatewayBase(): string
    {
        if (defined('NUMINIX_SEEKMODO_URL')) {
            $u = trim((string) constant('NUMINIX_SEEKMODO_URL'));
            if ($u !== '') {
                return rtrim($u, '/');
            }
        }

        return 'https://mcp.seekmodo.com';
    }

    /**
     * Browser-token refresh URL. The PHP shim lives at the live
     * catalog root (`/catalog/numinix_seekmodo_suggest.php`) and
     * routes `?action=browser-token` through `Client::tenantToken`,
     * returning `{token, expires_at, session_id}` in the shape the
     * `<seekmodo-suggest>` SDK expects.
     *
     * v1.0.22 deploys the shim file via the tenant repo's rsync
     * pipeline (it has to land at the live catalog root because Zen
     * Cart ships a `zc_plugins/.htaccess` that denies direct HTTP
     * access to PHP files under `zc_plugins/`). The shim itself is
     * `__DIR__`-rooted (see `numinix_seekmodo_suggest.php`) so if a
     * future deploy ever does land it inside the plugin dir, it'll
     * still resolve `includes/application_top.php` correctly — that
     * patch is defensive, not load-bearing.
     */
    private function refreshUrl(): string
    {
        if (!defined('DIR_WS_CATALOG')) {
            return '';
        }
        $base = (string) constant('DIR_WS_CATALOG');

        return $base . 'numinix_seekmodo_suggest.php?seekmodo_action=browser-token';
    }

    /**
     * Catalog-root typeahead shim (keywords + products + categories).
     * The web component normally calls the gateway directly; this URL
     * supplies storefront-enriched product rows (image_url from Zen
     * Cart helpers) for fallback merge and open-event hydration.
     */
    private function suggestShimUrl(): string
    {
        if (!defined('DIR_WS_CATALOG')) {
            return '';
        }

        return (string) constant('DIR_WS_CATALOG') . 'numinix_seekmodo_suggest.php';
    }

    /**
     * Fire-and-forget click beacon for `<seekmodo-suggest>` product rows.
     * Uses the catalog-root shim so sendBeacon POSTs are not swallowed
     * by Zen Cart's products_id redirect guard in init_sanitize.php.
     */
    private function clickEndpoint(): string
    {
        if (!defined('DIR_WS_CATALOG')) {
            return '';
        }

        return (string) constant('DIR_WS_CATALOG') . 'numinix_seekmodo_click.php';
    }

    /**
     * Product-row click fallback when the gateway index omits `url`.
     * JS replaces `__PID__` with the clicked row's products_id.
     */
    private function productInfoHrefTemplate(): string
    {
        if (!function_exists('numinix_seekmodo_href_link_raw')) {
            return '';
        }
        $page = defined('FILENAME_PRODUCT_INFO') ? (string) constant('FILENAME_PRODUCT_INFO') : 'product_info';
        if (function_exists('zen_get_info_page')) {
            try {
                $page = (string) zen_get_info_page(1);
            } catch (\Throwable $e) {
                // keep default product_info page name
            }
        }
        $sample = numinix_seekmodo_href_link_raw($page, 'products_id=1');
        if ($sample === '') {
            return '';
        }

        return (string) preg_replace('/products_id=\d+/i', 'products_id=__PID__', $sample, 1);
    }

    /**
     * Mint (or fetch the APCu-cached) browser-token for inline embed.
     * Empty string on any failure mode — the bundle then falls back to
     * the refresh URL.
     */
    private function browserToken(): string
    {
        if (!function_exists('numinix_seekmodo_client')) {
            return '';
        }
        try {
            // APCu-cache the mint so a flood of page renders coalesces
            // to ~1 mint / 4 min. Same posture as the WP connector's
            // BrowserToken transient cache.
            $cacheKey = 'seekmodo:browser_token:' . $this->tenantId();
            $now = time();
            if (function_exists('apcu_fetch')) {
                $ok = false;
                $cached = apcu_fetch($cacheKey, $ok);
                if ($ok && is_array($cached) && isset($cached['token'], $cached['expires_at'])
                    && (int) $cached['expires_at'] - $now > 60
                ) {
                    return (string) $cached['token'];
                }
            }
            $client = numinix_seekmodo_client();
            if (!is_object($client) || !method_exists($client, 'mintBrowserToken')) {
                return '';
            }
            // v1.0.22 fixup: was callTool('tenants/token', …) which
            // tripped the dot-only regex on Client::callTool() and
            // returned null. mintBrowserToken() POSTs the gateway's
            // /v1/tenants/token endpoint directly via the same HMAC
            // envelope.
            $resp = $client->mintBrowserToken(300);
            if (!is_array($resp) || !isset($resp['token'], $resp['expires_at'])) {
                return '';
            }
            $token = (string) $resp['token'];
            if ($token === '') {
                return '';
            }
            if (function_exists('apcu_store')) {
                $cacheFor = max(30, min(240, ((int) $resp['expires_at']) - $now - 60));
                apcu_store($cacheKey, [
                    'token'      => $token,
                    'expires_at' => (int) $resp['expires_at'],
                ], $cacheFor);
            }

            return $token;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Inline autoboot. Walks the same selectors the legacy
     * `jscript_seekmodo_typeahead.js` auto-attached to and inserts a
     * `<seekmodo-suggest input="…">` sibling per matching input.
     */
    private function autobootScript(): string
    {
        $blocks  = $this->blocks();
        $viewAll = $this->viewAllHref();
        $shopperCurrency = function_exists('numinix_seekmodo_shopper_currency')
            ? numinix_seekmodo_shopper_currency()
            : '';
        $serpPassthrough = function_exists('_numinix_seekmodo_build_serp_passthrough')
            ? _numinix_seekmodo_build_serp_passthrough()
            : (function_exists('_numinix_seekmodo_typesense_tuning_params')
                ? _numinix_seekmodo_typesense_tuning_params(true)
                : []);
        if (!is_array($serpPassthrough)) {
            $serpPassthrough = [];
        }
        if ($shopperCurrency !== '') {
            $existingShopper = isset($serpPassthrough['shopper_context'])
                && is_array($serpPassthrough['shopper_context'])
                ? $serpPassthrough['shopper_context']
                : [];
            $existingShopper['currency'] = $shopperCurrency;
            $serpPassthrough['shopper_context'] = $existingShopper;
        }
        $shimUrl = $this->suggestShimUrl();
        $cfg = [
            'selectors'     => [
                'input[name="keyword"]',
                'input#keyword',
                'input[data-seekmodo-typeahead]',
                'input[data-seekmodo-suggest]',
            ],
            'blocks'        => $blocks,
            'view_all_href' => $viewAll,
            'product_info_href' => $this->productInfoHrefTemplate(),
            'layout'         => $this->suggestLayout(),
            'show_branding'  => $this->suggestShowBranding(),
            'click_endpoint' => $this->clickEndpoint(),
            'suggest_hydrate_url' => $shimUrl,
            'mark_cloud_denied_url' => $shimUrl !== ''
                ? $shimUrl . '?seekmodo_action=stamp-cloud-denied'
                : '',
            'legacy_typeahead_src' => $this->bundleSrc(true),
            'serp_parity_submit' => function_exists('numinix_seekmodo_mode')
                && numinix_seekmodo_mode() === 'enforce',
            'serp_passthrough' => $serpPassthrough,
            'img_ver'          => $this->bundleImageVer(),
            // Multi-language storefront labels (EN/DE/ES/FR packs under
            // catalog/includes/languages/{lang}/extra_definitions/).
            'lang'             => $this->suggestLangCode(),
            'labels'           => $this->suggestLabels(),
            'extras'         => [
                'min-length'  => '2',
                'debounce-ms' => '150',
                // Klevu-style two-phase typeahead: text suggestions on
                // the fast debounce; product thumbnails after idle.
                'product-debounce-ms' => '400',
                'limit'       => '15',
                'cache-size'  => '32',
                'currency'    => $shopperCurrency,
                // Split-rail mobile: draggable divider + full product titles.
                'split-mobile-resize' => 'true',
                'product-title-tooltip' => 'true',
                // Storefront PHP shim enriches product rows with image_url
                // (optimized thumbs) when the gateway index predates that
                // field — used by mergeTypeaheadFallback when the gateway
                // response is empty and by the open-event hydrator below
                // when products render without thumbnails.
                'typeahead-fallback-url' => $this->suggestShimUrl(),
                // SM-606 follow-up: the bundle's `suppress-legacy`
                // attribute tears down sibling typeahead widgets bound
                // to the same input on first focus. Zen Cart catalogs
                // commonly ship jQuery UI autocomplete (the stock
                // search box uses it on some templates) and a few
                // bespoke storefronts (KIP, for one) still keep the
                // Sprint-7 `<seekmodo-typeahead>` enqueued during the
                // v1.0.20 → v1.0.21 cutover. Suppress both by default
                // so the rich dropdown doesn't get shadowed.
                'suppress-legacy' => 'jquery-ui,seekmodo-typeahead',
            ],
        ];
        $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '';
        }

        $tpl = <<<'JS'
<script>(function () {
  var CFG = %CFG%;
  if (!CFG || !Array.isArray(CFG.selectors) || CFG.selectors.length === 0) return;
  var SELECTOR = CFG.selectors.join(',');
  function ensureId(input) {
    if (input.id && input.id.length > 0) return input.id;
    var rnd = 'seekmodo-suggest-input-' + Math.random().toString(36).slice(2, 10);
    input.id = rnd;
    return rnd;
  }
  function attach(input) {
    if (!input || input.dataset.seekmodoSuggest === '1') return;
    if (input.closest && input.closest('seekmodo-suggest')) return;
    var id = ensureId(input);
    var el = document.createElement('seekmodo-suggest');
    el.setAttribute('input', id);
    if (CFG.blocks) el.setAttribute('blocks', CFG.blocks);
    if (CFG.view_all_href) el.setAttribute('view-all-href', CFG.view_all_href);
    if (CFG.serp_passthrough && typeof CFG.serp_passthrough === 'object') {
      el.setAttribute('serp-passthrough', JSON.stringify(CFG.serp_passthrough));
    }
    if (CFG.img_ver) el.setAttribute('img-ver', String(CFG.img_ver));
    if (CFG.lang) el.setAttribute('lang', String(CFG.lang));
    if (CFG.labels && typeof CFG.labels === 'object') {
      try { window.SeekmodoSuggestLabels = CFG.labels; } catch (e) {}
      el.setAttribute('labels', JSON.stringify(CFG.labels));
    }
    el.setAttribute('layout', CFG.layout || 'split-rail');
    el.setAttribute('show-branding', CFG.show_branding ? 'true' : 'false');
    if (CFG.extras && typeof CFG.extras === 'object') {
      for (var k in CFG.extras) {
        if (Object.prototype.hasOwnProperty.call(CFG.extras, k)) {
          var v = CFG.extras[k];
          if (v === null || v === undefined || v === '') continue;
          el.setAttribute(k, String(v));
        }
      }
    }
    input.parentNode.insertBefore(el, input.nextSibling);
    input.dataset.seekmodoSuggest = '1';
    input.setAttribute('autocomplete', 'off');
    syncSuggestVehicleFilter();
  }
  var VEHICLE_KEY = 'seekmodo.vehicle';
  function escapeTypesenseToken(value) {
    if (/[\s&|!():`]/.test(value)) {
      return '`' + value.replace(/`/g, '\\`') + '`';
    }
    return value;
  }
  function ymmClause(make, model) {
    var mk = (make || '').trim();
    var md = (model || '').trim();
    if (!mk || !md) return null;
    return 'name:' + escapeTypesenseToken(mk) + ' && name:' + escapeTypesenseToken(md);
  }
  function readGarageVehicleId() {
    var input = document.querySelector('input[name="garage_vehicle_id"]');
    if (!input) return 0;
    var id = parseInt(String(input.value || '0'), 10);
    return Number.isFinite(id) && id > 0 ? id : 0;
  }
  function readYmmFromSelects() {
    var makeEl = document.querySelector('#makeSelect, select[name="make"], [data-seekmodo-make]');
    var modelEl = document.querySelector('#modelSelect, select[name="model"], [data-seekmodo-model]');
    var yearEl = document.querySelector('#yearSelect, select[name="year"], [data-seekmodo-year]');
    var make = makeEl && makeEl.value ? String(makeEl.value).trim() : '';
    var model = modelEl && modelEl.value ? String(modelEl.value).trim() : '';
    var year = yearEl && yearEl.value ? String(yearEl.value).trim() : '';
    if (!make || !model || make === 'Select' || model === 'Select') return null;
    return { make: make, model: model, year: year };
  }
  function readStoredVehicle() {
    try {
      var raw = localStorage.getItem(VEHICLE_KEY);
      if (!raw) return null;
      var v = JSON.parse(raw);
      return v && typeof v === 'object' ? v : null;
    } catch (_e) { return null; }
  }
  function resolveVehicleContext() {
    var stored = readStoredVehicle();
    if (stored) return stored;
    var gid = readGarageVehicleId();
    if (gid > 0 && window.seekmodoGarage && typeof window.seekmodoGarage.list === 'function') {
      var items = window.seekmodoGarage.list();
      for (var i = 0; i < items.length; i++) {
        if (Number(items[i].vehicle_id) === gid) {
          return {
            vehicle_id: gid,
            make: items[i].make || '',
            model: items[i].model || '',
            year: items[i].year || '',
            label: items[i].label || '',
            fitment_product_count: items[i].product_count
          };
        }
      }
      return { vehicle_id: gid };
    }
    if (gid > 0) return { vehicle_id: gid };
    var ymm = readYmmFromSelects();
    if (ymm) {
      return {
        vehicle_id: 0,
        make: ymm.make,
        model: ymm.model,
        year: ymm.year,
        fitment_product_count: 0
      };
    }
    return null;
  }
  function buildVehicleFilterPassthrough(v) {
    if (!v || typeof v !== 'object') return null;
    var vid = Number(v.vehicle_id);
    var make = (v.make || '').trim();
    var model = (v.model || '').trim();
    var count = v.fitment_product_count;
    var pt = {
      vehicle_hard_filter: true,
      shopper_context: {}
    };
    if (make) pt.shopper_context.vehicle_make = make;
    if (model) pt.shopper_context.vehicle_model = model;
    if (v.label) pt.shopper_context.vehicle_label = String(v.label);
    if (v.year) pt.shopper_context.vehicle_year = String(v.year);
    if (Number.isFinite(vid) && vid > 0 && !(typeof count === 'number' && count === 0)) {
      pt.filter_by = 'fits_vehicles:=' + vid;
      pt.vehicle_filter_mode = 'fitment';
      pt.vehicle_id = vid;
      pt.shopper_context.vehicle_id = String(vid);
      return pt;
    }
    if (make && model && (!Number.isFinite(vid) || vid === 0 || (typeof count === 'number' && count === 0))) {
      var ymm = ymmClause(make, model);
      if (ymm) {
        pt.filter_by = ymm;
        pt.vehicle_filter_mode = 'ymm';
        pt.vehicle_id = 0;
        return pt;
      }
    }
    return null;
  }
  var OPT_OUT_PARAM = 'seekmodo_no_vehicle_filter';
  var OPT_OUT_STORAGE_KEY = 'seekmodo.vehicle_filter_opted_out';
  // Exact part-token queries must not promote prior-search keywords /
  // trending into a SERP that exact-filters model/sku to zero hits
  // (AKS 1.7.6 / e.g. 4-6340-20). Product + category blocks only.
  var EXACT_PART_SUGGEST_BLOCKS = 'products,categories';
  var DEFAULT_SUGGEST_BLOCKS = (CFG && CFG.blocks) || 'recent,did_you_mean,keywords,trending,products,categories';
  function looksLikeExactPartToken(q) {
    q = String(q || '').trim();
    if (q.indexOf('-') < 0) return false;
    if (/^[A-Z0-9]+-\d{3,16}$/i.test(q)) return true;
    if (/^\d{3,}[A-Z0-9]*-[A-Z0-9]{2,}(?:-[A-Z0-9]{2,})*$/i.test(q)) return true;
    if (/^\d+(?:-\d+){2,}$/.test(q)) return true;
    return /^[A-Z]{2,}-[A-Z0-9\-]*\d[A-Z0-9\-]*$/i.test(q);
  }
  function applySuggestExactPartUi(nodes, exactPartMode) {
    var viewAll = CFG.view_all_href || '/index.php?main_page=advanced_search_result&keyword={q}&pfrom={price_from}&pto={price_to}';
    for (var i = 0; i < nodes.length; i++) {
      if (exactPartMode) {
        if (!nodes[i].getAttribute('data-seekmodo-default-blocks')) {
          nodes[i].setAttribute(
            'data-seekmodo-default-blocks',
            nodes[i].getAttribute('blocks') || DEFAULT_SUGGEST_BLOCKS
          );
        }
        nodes[i].setAttribute('blocks', EXACT_PART_SUGGEST_BLOCKS);
        nodes[i].removeAttribute('view-all-href');
      } else {
        var restore = nodes[i].getAttribute('data-seekmodo-default-blocks');
        if (restore) {
          nodes[i].setAttribute('blocks', restore);
          nodes[i].removeAttribute('data-seekmodo-default-blocks');
        }
        if (!nodes[i].getAttribute('view-all-href')) {
          nodes[i].setAttribute('view-all-href', viewAll);
        }
      }
    }
  }
  function activeSuggestSearchQuery() {
    var inputs = document.querySelectorAll(SELECTOR);
    for (var i = 0; i < inputs.length; i++) {
      var v = String(inputs[i].value || '').trim();
      if (v) return v;
    }
    return '';
  }
  function syncSuggestExactPartBlocks() {
    var exact = looksLikeExactPartToken(activeSuggestSearchQuery());
    applySuggestExactPartUi(document.querySelectorAll('seekmodo-suggest'), exact);
  }
  function persistVehicleFilterOptOut() {
    try { localStorage.setItem(OPT_OUT_STORAGE_KEY, '1'); } catch (_e) {}
  }
  function isVehicleFilterOptedOut() {
    try {
      var flag = new URLSearchParams(window.location.search).get(OPT_OUT_PARAM);
      if (flag === '1' || flag === 'true') {
        persistVehicleFilterOptOut();
        return true;
      }
      return localStorage.getItem(OPT_OUT_STORAGE_KEY) === '1';
    } catch (_e) { return false; }
  }
  function syncSuggestVehicleFilter() {
    if (isVehicleFilterOptedOut()) {
      var viewAllOptOut = CFG.view_all_href || '/index.php?main_page=advanced_search_result&keyword={q}&pfrom={price_from}&pto={price_to}';
      var nodesOptOut = document.querySelectorAll('seekmodo-suggest');
      for (var o = 0; o < nodesOptOut.length; o++) {
        nodesOptOut[o].removeAttribute('vehicle-id');
        nodesOptOut[o].removeAttribute('serp-passthrough');
        nodesOptOut[o].setAttribute('view-all-href', viewAllOptOut);
      }
      syncSuggestExactPartBlocks();
      return;
    }
    var ctx = resolveVehicleContext();
    var pt = buildVehicleFilterPassthrough(ctx);
    var vid = ctx && Number(ctx.vehicle_id) > 0 ? Number(ctx.vehicle_id) : 0;
    var viewAll = CFG.view_all_href || '/index.php?main_page=advanced_search_result&keyword={q}&pfrom={price_from}&pto={price_to}';
    if (vid > 0) {
      viewAll = viewAll + (viewAll.indexOf('?') >= 0 ? '&' : '?') + 'garage_vehicle_id=' + encodeURIComponent(String(vid));
    } else if (ctx && ctx.make && ctx.model) {
      viewAll = viewAll + (viewAll.indexOf('?') >= 0 ? '&' : '?')
        + 'make=' + encodeURIComponent(ctx.make)
        + '&model=' + encodeURIComponent(ctx.model);
      if (ctx.year) viewAll += '&year=' + encodeURIComponent(String(ctx.year));
    }
    var nodes = document.querySelectorAll('seekmodo-suggest');
    for (var i = 0; i < nodes.length; i++) {
      if (vid > 0) {
        nodes[i].setAttribute('vehicle-id', String(vid));
      } else {
        nodes[i].removeAttribute('vehicle-id');
      }
      if (pt) {
        nodes[i].setAttribute('serp-passthrough', JSON.stringify(pt));
      } else {
        nodes[i].removeAttribute('serp-passthrough');
      }
      nodes[i].setAttribute('view-all-href', viewAll);
    }
    // Exact-part gating wins over vehicle view-all when active.
    syncSuggestExactPartBlocks();
  }
  window.addEventListener('seekmodo:vehicle:selected', syncSuggestVehicleFilter);
  window.addEventListener('seekmodo:vehicle:cleared', syncSuggestVehicleFilter);
  document.addEventListener('change', function (e) {
    if (!e || !e.target || !e.target.matches) return;
    if (e.target.matches('#yearSelect, #makeSelect, #modelSelect, select[name="year"], select[name="make"], select[name="model"]')) {
      syncSuggestVehicleFilter();
    }
  }, true);
  document.addEventListener('input', function (e) {
    if (!e || !e.target || !e.target.matches) return;
    if (e.target.matches(SELECTOR)) syncSuggestExactPartBlocks();
  }, true);
  function scan(root) {
    try {
      var inputs = (root || document).querySelectorAll(SELECTOR);
      for (var i = 0; i < inputs.length; i++) attach(inputs[i]);
    } catch (_e) { /* malformed selector — bail silently */ }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(document); syncSuggestVehicleFilter(); syncSuggestExactPartBlocks(); });
  } else {
    scan(document);
    syncSuggestVehicleFilter();
    syncSuggestExactPartBlocks();
  }
  if ('MutationObserver' in window) {
    var mo = new MutationObserver(function (list) {
      for (var i = 0; i < list.length; i++) {
        var m = list[i];
        for (var j = 0; j < m.addedNodes.length; j++) {
          var n = m.addedNodes[j];
          if (n && n.nodeType === 1) {
            if (n.matches && n.matches(SELECTOR)) attach(n);
            scan(n);
          }
        }
      }
    });
    mo.observe(document.documentElement, { childList: true, subtree: true });
  }
  // 402 trial_expired / over_quota: widget emits seekmodo-suggest:empty
  // with reason=quota. Stamp PHP sticky + flip this input onto the
  // same-origin Enhanced Native legacy typeahead so the shopper is
  // not left with a blank loading dropdown. Transient 5xx does not
  // emit empty, so we do not permanently stick to EN while subscribed.
  if (CFG.legacy_typeahead_src || CFG.mark_cloud_denied_url) {
    document.addEventListener('seekmodo-suggest:empty', function (ev) {
      var detail = (ev && ev.detail) || {};
      if (String(detail.reason || '') !== 'quota') return;
      var inputEl = detail.input;
      if (!inputEl || inputEl.dataset.seekmodoEnFallback === '1') return;
      inputEl.dataset.seekmodoEnFallback = '1';
      try {
        if (CFG.mark_cloud_denied_url) {
          // Daily unpaid-recovery plan (2026-08): pass through the widget's
          // real denial code (over_quota vs trial_expired) when the event
          // carries one, so the server-side sticky isn't mislabeled and
          // wrongly cleared by an active-billing recheck while the tenant
          // is still over its metered quota. Falls back to the PHP
          // endpoint's own default when the widget doesn't send one yet.
          var denialCode = detail && typeof detail.code === 'string' ? detail.code : '';
          var stampUrl = CFG.mark_cloud_denied_url
            + (denialCode ? '&code=' + encodeURIComponent(denialCode) : '');
          if (navigator.sendBeacon) {
            navigator.sendBeacon(stampUrl);
          } else {
            fetch(stampUrl, { credentials: 'same-origin', keepalive: true }).catch(function () {});
          }
        }
      } catch (_e) {}
      try {
        var widgets = document.querySelectorAll('seekmodo-suggest');
        for (var i = 0; i < widgets.length; i++) {
          if (widgets[i].getAttribute('input') === inputEl.id && widgets[i].parentNode) {
            widgets[i].parentNode.removeChild(widgets[i]);
          }
        }
      } catch (_e2) {}
      if (!CFG.legacy_typeahead_src || window.__seekmodoLegacyTypeaheadLoaded) return;
      window.__seekmodoLegacyTypeaheadLoaded = true;
      var s = document.createElement('script');
      s.src = CFG.legacy_typeahead_src;
      s.async = true;
      document.head.appendChild(s);
    });
  }
  // ---- Row-click navigation (v1.0.22 fix-pack #4 for SM-606).
  //
  // The `<seekmodo-suggest>` web component intentionally does NOT
  // navigate on row click; it just emits a `seekmodo-suggest:row-click`
  // CustomEvent (`composed: true`, bubbles to document) and leaves
  // the connector to decide where to send the shopper. Without this
  // listener the dropdown felt completely inert: clicks visually
  // highlighted the row, the input briefly stole focus back, then
  // nothing happened.
  //
  // Behaviour, mirroring the implicit Zen Cart legacy typeahead:
  //   - products / categories with `row.url`     -> location.href = row.url
  //   - keyword-style blocks (recent, trending, keywords, did_you_mean)
  //                                              -> view-all-href with {q}
  //                                                 substituted by the
  //                                                 row's keyword text
  //                                              +  seekmodo_skip_category_redirect=1
  //                                                 (v1.1.1 fix-pack #2 --
  //                                                  see below)
  //   - categories WITHOUT row.url               -> view-all-href with {q}
  //                                                 substituted by the leaf
  //                                                 category name. NO skip
  //                                                 marker -- we WANT the
  //                                                 connector's resolver to
  //                                                 302 the shopper to the
  //                                                 matching category
  //                                                 landing page (the
  //                                                 leaf-name should match
  //                                                 the category at score
  //                                                 1.00).
  //   - products WITHOUT row.url                 -> product_info_href with
  //                                                 __PID__ replaced by the
  //                                                 row's products_id when
  //                                                 available (v1.3.76 --
  //                                                 RED-1880); otherwise
  //                                                 view-all-href with {q}
  //                                                 substituted by row.name
  //                                                 +  seekmodo_skip_category_redirect=1
  //
  // v1.1.1 fix-pack #2 -- skip the connector's `category_redirect`
  // 302 when the shopper explicitly picked a keyword-style row.
  //
  // The connector's `NuminixSeekmodoObserver::onAdvancedSearchStart`
  // hook (v1.0.19, Klevu/Algolia parity) 302-redirects an
  // advanced_search_result URL to a single matching category landing
  // page when the bare query string maps to that category with high
  // similarity. That's the right call for URL-bar / bookmark /
  // form-submit entries -- the shopper hasn't expressed any other
  // intent yet -- but it overrides the explicit choice a shopper made
  // when they clicked a "keywords" / "trending" / "recent" /
  // "did_you_mean" row in a multi-section dropdown that ALSO showed
  // a separate (or empty) Categories section. The keyword section's
  // search-count badge ("Wheel vise · 37") tells the shopper "this
  // will search the whole catalog for this phrase"; bouncing them to
  // a single category subtree silently drops the other matching
  // products. We tag the navigation with
  // `seekmodo_skip_category_redirect=1` so onAdvancedSearchStart()
  // bails before resolving the category and the SERP renders.
  //
  // RED-SUGGEST follow-up (this revision) -- treat the `categories`
  // block as the OPPOSITE intent: the shopper EXPLICITLY picked a
  // category row, so let the resolver 302 redirect to that landing
  // page. The gateway's per-doc breadcrumb walk emits the leaf-only
  // `name` (e.g. "Motorcycle Lift Wheel Vise") precisely so the
  // resolver's exact-normalised-match path matches at score 1.00.
  // We DO NOT stamp the skip marker on category clicks; the parent
  // category block's behaviour (Klevu / Algolia parity) is what the
  // shopper wants.
  function appendParam(url, key, value) {
    var hashIdx = url.indexOf('#');
    var base = hashIdx >= 0 ? url.slice(0, hashIdx) : url;
    var hash = hashIdx >= 0 ? url.slice(hashIdx) : '';
    var sep  = base.indexOf('?') >= 0 ? '&' : '?';
    return base + sep + encodeURIComponent(key) + '=' + encodeURIComponent(value) + hash;
  }
  // Mirror product-row clicks to the gateway before navigation. The
  // web-component typeahead path bypasses the PHP session position-map
  // that legacy ajax suggest used for product-page referer attribution.
  function mirrorSuggestClick(detail) {
    var ep = CFG.click_endpoint;
    if (!ep) return;
    if (detail.block !== 'products') return;
    var productsId = parseInt(
      detail.id || (detail.row && detail.row.id) || '0',
      10
    );
    if (!productsId) return;
    var keyword = String(detail.q || '');
    if (!keyword) return;
    var position = parseInt(detail.position, 10) || 1;
    try {
      var body = 'keyword=' + encodeURIComponent(keyword)
        + '&products_id=' + encodeURIComponent(String(productsId))
        + '&position=' + encodeURIComponent(String(position))
        + '&surface=' + encodeURIComponent('typeahead');
      var seid = parseInt(detail.search_event_id, 10);
      if (seid > 0) {
        body += '&search_event_id=' + encodeURIComponent(String(seid));
      }
      if (navigator.sendBeacon) {
        navigator.sendBeacon(
          ep,
          new Blob([body], { type: 'application/x-www-form-urlencoded' })
        );
        return;
      }
      fetch(ep, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
        keepalive: true,
        credentials: 'same-origin'
      }).catch(function () {});
    } catch (_e) {}
  }
  document.addEventListener('seekmodo-suggest:row-click', function (ev) {
    var detail = (ev && ev.detail) || {};
    mirrorSuggestClick(detail);
    var block = detail.block;
    var row = detail.row || {};
    var q = String(detail.q || '');
    if ((block === 'products' || block === 'categories' || block === 'redirects')
        && row && typeof row.url === 'string' && row.url) {
      try {
        var u = new URL(row.url, window.location.origin);
        window.location.href = u.pathname + u.search + u.hash;
      } catch (_navErr) {
        window.location.href = row.url;
      }
      return;
    }
    if (block === 'redirects' && row && typeof row.target_url === 'string' && row.target_url) {
      try {
        var tu = new URL(row.target_url, window.location.origin);
        window.location.href = tu.pathname + tu.search + tu.hash;
      } catch (_navErr2) {
        window.location.href = row.target_url;
      }
      return;
    }
    if (block === 'price_range') {
      return;
    }
    if (block === 'products') {
      var productsId = parseInt(
        detail.id || (row && row.id) || (row && row.products_id) || '0',
        10
      );
      var productTpl = CFG && CFG.product_info_href;
      if (productsId > 0 && productTpl && typeof productTpl === 'string') {
        window.location.href = productTpl.replace('__PID__', String(productsId));
        return;
      }
    }
    var keyword = '';
    // Default to "skip the resolver" -- safe for every keyword-style
    // row (recent / trending / keywords / did_you_mean) and for any
    // defensive product fallback. Only the explicit category branch
    // below opts back in.
    var skipCategoryRedirect = true;
    if (block === 'did_you_mean') {
      keyword = String(detail.value || (row && row.value) || q);
    } else if (block === 'recent' || block === 'trending' || block === 'keywords') {
      keyword = String((row && row.keyword) || detail.value || q);
    } else if (block === 'categories') {
      keyword = String((row && (row.name || row.title)) || detail.value || q);
      // Let `NuminixSeekmodoObserver::onAdvancedSearchStart` 302 to
      // the category landing page when the leaf matches.
      skipCategoryRedirect = false;
    } else if (block === 'products') {
      keyword = String((row && (row.name || row.title)) || detail.value || q);
    } else {
      keyword = String(detail.value || q);
    }
    if (!keyword) return;
    var viewAll = (CFG && CFG.view_all_href) || '/search?q={q}';
    var nav = viewAll.replace('{q}', encodeURIComponent(keyword))
      .replace('{price_from}', '')
      .replace('{price_to}', '');
    if (skipCategoryRedirect) {
      nav = appendParam(nav, 'seekmodo_skip_category_redirect', '1');
    }
    window.location.href = nav;
  });
  document.addEventListener('seekmodo-suggest:view-all', function (ev) {
    var detail = (ev && ev.detail) || {};
    var q = String(detail.q || '').trim();
    if (!q) return;
    var viewAll = (CFG && CFG.view_all_href) || '/search?q={q}';
    var from = detail.price_from != null && detail.price_from !== '' ? String(detail.price_from) : '';
    var to = detail.price_to != null && detail.price_to !== '' ? String(detail.price_to) : '';
    var nav = viewAll.replace('{q}', encodeURIComponent(q))
      .replace('{price_from}', encodeURIComponent(from))
      .replace('{price_to}', encodeURIComponent(to));
    nav = appendParam(nav, 'seekmodo_skip_category_redirect', '1');
    window.location.href = nav;
  });
  document.addEventListener('seekmodo-suggest:cors-blocked', function (ev) {
    var detail = (ev && ev.detail) || {};
    var inputEl = detail.input;
    if (inputEl && typeof window.seekmodoShowCorsNotice === 'function') {
      window.seekmodoShowCorsNotice(inputEl, detail.message);
    }
  });
  // v1.2.6 — Enter / form submit lands on the same SERP as View all
  // when Seekmodo serves results (enforce). Category redirect stays
  // enabled for shadow/beacon tenants.
  if (CFG && CFG.serp_parity_submit) {
    document.addEventListener('submit', function (ev) {
      var form = ev.target;
      if (!form || form.nodeName !== 'FORM') return;
      var kw = form.querySelector('input[name="keyword"], input[name="search_query"]');
      if (!kw) return;
      var main = form.querySelector('input[name="main_page"]');
      var page = main ? String(main.value || '') : '';
      var act = String(form.getAttribute('action') || '').toLowerCase();
      var isSearch = page === 'advanced_search_result' || page === 'search_result'
        || act.indexOf('advanced_search') >= 0 || act.indexOf('search_result') >= 0;
      if (!isSearch) return;
      if (form.querySelector('input[name="seekmodo_skip_category_redirect"]')) return;
      var hid = document.createElement('input');
      hid.type = 'hidden';
      hid.name = 'seekmodo_skip_category_redirect';
      hid.value = '1';
      form.appendChild(hid);
    }, true);
  }
  // v1.3.7 — always upgrade suggest thumbs from the storefront PHP shim
  // at 240px (Image Handler / optimizer). Gateway image_url values are
  // often too small for split-rail mobile grids and looked pixelated when
  // upscaled; hydrate every visible row, not only empty placeholders.
  // v1.3.4 KIP — hydrate suggest thumbnails from the storefront PHP
  // shim. `<seekmodo-suggest>` fetches /v1/suggest via the gateway;
  // older KIP catalog pushes omitted image_url, so productImageSrc()
  // renders empty thumb placeholders even though numinix_seekmodo_suggest.php
  // can enrich rows from zen_get_products_image(). Match rows by
  // data-seekmodo-id after the dropdown opens.
  var _thumbHydrateCache = Object.create(null);
  var _thumbHydrateInflight = null;
  try {
    var _storedImgVer = sessionStorage.getItem('seekmodo_suggest_img_ver') || '';
    if (CFG.img_ver && _storedImgVer && _storedImgVer !== String(CFG.img_ver)) {
      _thumbHydrateCache = Object.create(null);
    }
    if (CFG.img_ver) sessionStorage.setItem('seekmodo_suggest_img_ver', String(CFG.img_ver));
  } catch (e) {}
  function _thumbSrcWithVer(u) {
    if (!u || typeof u !== 'string') return '';
    u = u.trim();
    if (!u) return '';
    var ver = CFG && CFG.img_ver ? String(CFG.img_ver) : '';
    if (!ver) return _absSuggestImageUrl(u);
    try {
      var abs = /^https?:\/\//i.test(u) ? u : _absSuggestImageUrl(u);
      var parsed = new URL(abs, window.location.origin);
      parsed.searchParams.set('_smv', ver);
      return parsed.toString();
    } catch (e) {
      var join = u.indexOf('?') >= 0 ? '&' : '?';
      return _absSuggestImageUrl(u) + join + '_smv=' + encodeURIComponent(ver);
    }
  }
  function _absSuggestImageUrl(u) {
    if (!u || typeof u !== 'string') return '';
    u = u.trim();
    if (!u) return '';
    if (/^https?:\/\//i.test(u)) return u;
    if (u.charAt(0) === '/') return window.location.origin + u;
    return window.location.origin + '/' + u.replace(/^\//, '');
  }
  // Zen spacer / no_picture 200s as a real image, so onerror cannot
  // roll back to the gateway thumb. Never paint these (KIP no_picture,
  // STRIN template x.gif). Image Handler cache URLs are *not* included
  // — PHP prefer_catalog already swaps those for catalog originals.
  function _isPlaceholderSuggestThumbUrl(u) {
    if (!u || typeof u !== 'string') return true;
    if (/no_picture\.(?:gif|png|jpe?g|webp)(?:$|[?#])/i.test(u)) return true;
    if (/\/includes\/templates\//i.test(u)) return true;
    if (/(?:^|\/)x\.gif(?:$|[?#])/i.test(u)) return true;
    return false;
  }
  function _parseSuggestProductImage(p) {
    if (!p || typeof p !== 'object') return '';
    if (typeof p.image_url === 'string' && p.image_url.trim() !== '') {
      return _absSuggestImageUrl(p.image_url);
    }
    if (typeof p.image === 'string' && p.image.indexOf('<') >= 0) {
      var m = p.image.match(/\ssrc=(["'])([^"']+)\1/i);
      if (m && m[2]) return _absSuggestImageUrl(m[2]);
    }
    return '';
  }
  function _reloadSuggestThumbImg(img, src, prior) {
    var parent = img.parentNode;
    if (!parent) return;
    var ni = document.createElement('img');
    ni.className = img.className || 'thumb';
    ni.setAttribute('part', img.getAttribute('part') || 'thumb');
    ni.loading = 'eager';
    ni.decoding = 'async';
    ni.alt = img.alt || '';
    ni.setAttribute('data-src', src);
    if (prior && prior !== src) {
      ni.onerror = function () {
        ni.onerror = null;
        ni.src = prior;
        ni.setAttribute('data-src', prior);
      };
    }
    ni.src = src;
    parent.replaceChild(ni, img);
  }
  function _suggestThumbNeedsHydrate(img, force) {
    if (force) return true;
    if (!img) return false;
    var cur = img.getAttribute('src') || '';
    if (cur === '') return true;
    // Zen placeholder is a valid image file, so naturalWidth > 0 — still
    // force upgrade when products_image exists on the storefront.
    if (/no_picture\.(?:gif|png|jpe?g|webp)(?:$|[?#])/i.test(cur)) return true;
    if (img.complete && img.naturalWidth === 0) return true;
    return false;
  }
  function _paintSuggestThumbs(map, force) {
    if (!map) return;
    force = !!force || !!(CFG && CFG.img_ver);
    var hosts = document.querySelectorAll('seekmodo-suggest');
    for (var h = 0; h < hosts.length; h++) {
      var root = hosts[h].shadowRoot;
      if (!root) continue;
      var rows = root.querySelectorAll('[data-seekmodo-id]');
      for (var r = 0; r < rows.length; r++) {
        var id = rows[r].getAttribute('data-seekmodo-id');
        var src = id && map[id];
        if (!src) continue;
        src = _thumbSrcWithVer(src);
        if (_isPlaceholderSuggestThumbUrl(src)) continue;
        var img = rows[r].querySelector('img.thumb');
        var empty = rows[r].querySelector('.thumb-empty');
        if (img) {
          if (!_suggestThumbNeedsHydrate(img, force)) continue;
          if (force || img.getAttribute('src') !== src) {
            var prior = img.getAttribute('data-src') || img.getAttribute('src') || '';
            // Never permanently replace a working gateway/catalog thumb with
            // a broken Image Handler cache URL (403 / missing resized file).
            if (force) {
              _reloadSuggestThumbImg(img, src, prior);
            } else {
              img.loading = 'eager';
              img.decoding = 'async';
              img.onerror = function () {
                if (prior && prior !== src) {
                  img.onerror = null;
                  img.src = prior;
                }
              };
              img.src = src;
            }
          }
          continue;
        }
        if (empty) {
          var ni = document.createElement('img');
          ni.className = 'thumb';
          ni.setAttribute('part', 'thumb');
          ni.loading = 'eager';
          ni.decoding = 'async';
          ni.alt = '';
          ni.src = src;
          empty.replaceWith(ni);
        }
      }
    }
  }
  function _collectSuggestProductIdsForImages() {
    var ids = [];
    var seen = Object.create(null);
    var hosts = document.querySelectorAll('seekmodo-suggest');
    for (var h = 0; h < hosts.length; h++) {
      var root = hosts[h].shadowRoot;
      if (!root) continue;
      var rows = root.querySelectorAll('[data-seekmodo-id]');
      for (var r = 0; r < rows.length; r++) {
        var id = rows[r].getAttribute('data-seekmodo-id');
        if (!id || seen[id]) continue;
        seen[id] = true;
        ids.push(id);
      }
    }
    return ids;
  }
  function _hydrateSuggestThumbsFromIds(ids) {
    if (!ids || !ids.length) return;
    var cacheKey = 'ids:' + ids.join(',');
    if (_thumbHydrateCache[cacheKey]) {
      _paintSuggestThumbs(_thumbHydrateCache[cacheKey]);
      return;
    }
    if (_thumbHydrateInflight === cacheKey) return;
    var base = (CFG && CFG.suggest_hydrate_url) || '/numinix_seekmodo_suggest.php';
    var sep = base.indexOf('?') >= 0 ? '&' : '?';
    _thumbHydrateInflight = cacheKey;
    fetch(base + sep + 'seekmodo_action=images&ids=' + encodeURIComponent(ids.join(',')), { credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        _thumbHydrateInflight = null;
        if (!data || !data.ok || !data.images || typeof data.images !== 'object') return;
        var keys = Object.keys(data.images);
        if (keys.length === 0) return;
        _thumbHydrateCache[cacheKey] = data.images;
        _paintSuggestThumbs(data.images);
      })
      .catch(function () { _thumbHydrateInflight = null; });
  }
  function _hydrateSuggestThumbs(q) {
    q = String(q || '').trim();
    var ids = _collectSuggestProductIdsForImages();
    if (ids.length > 0) {
      _hydrateSuggestThumbsFromIds(ids);
      return;
    }
    // img-ver mode defers to the 240px images action — never paint 60px q-shim thumbs.
    if (CFG && CFG.img_ver) return;
    if (q.length < 2) return;
    if (_thumbHydrateCache[q]) {
      _paintSuggestThumbs(_thumbHydrateCache[q]);
      return;
    }
    if (_thumbHydrateInflight === q) return;
    var base = (CFG && CFG.suggest_hydrate_url) || '/numinix_seekmodo_suggest.php';
    var sep = base.indexOf('?') >= 0 ? '&' : '?';
    _thumbHydrateInflight = q;
    fetch(base + sep + 'q=' + encodeURIComponent(q) + '&max=15', { credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        _thumbHydrateInflight = null;
        if (!data || !data.ok || !Array.isArray(data.products)) return;
        var map = Object.create(null);
        for (var i = 0; i < data.products.length; i++) {
          var p = data.products[i];
          var id = p && p.products_id != null ? String(p.products_id) : '';
          var url = _parseSuggestProductImage(p);
          if (id && url && !_isPlaceholderSuggestThumbUrl(url)) map[id] = url;
        }
        if (Object.keys(map).length === 0) return;
        _thumbHydrateCache[q] = map;
        _paintSuggestThumbs(map);
      })
      .catch(function () { _thumbHydrateInflight = null; });
  }
  function _scheduleSuggestThumbHydrate(q) {
    // Prefer seekmodo-suggest:rendered (post-paint). open/render may fire
    // before scheduleRender's rAF replaces product cards — mirror the
    // stock-reorder double-rAF so we hydrate against real DOM.
    var delays = [0, 50, 150, 350, 800];
    for (var i = 0; i < delays.length; i++) {
      (function (delay) {
        setTimeout(function () {
          var run = function () {
            var ids = _collectSuggestProductIdsForImages();
            if (ids.length > 0) {
              _hydrateSuggestThumbsFromIds(ids);
              return;
            }
            _hydrateSuggestThumbs(q);
          };
          if (delay === 0) {
            requestAnimationFrame(function () {
              requestAnimationFrame(run);
            });
            return;
          }
          run();
        }, delay);
      })(delays[i]);
    }
  }
  // v1.3.6 — hydrate suggest prices in the shopper's session currency.
  var _priceHydrateCache = Object.create(null);
  var _priceHydrateInflight = null;
  function _collectSuggestProductIdsForPrices() {
    var ids = [];
    var seen = Object.create(null);
    var hosts = document.querySelectorAll('seekmodo-suggest');
    for (var h = 0; h < hosts.length; h++) {
      var root = hosts[h].shadowRoot;
      if (!root) continue;
      var rows = root.querySelectorAll('[data-seekmodo-id]');
      for (var r = 0; r < rows.length; r++) {
        var id = rows[r].getAttribute('data-seekmodo-id');
        if (!id || seen[id]) continue;
        seen[id] = true;
        ids.push(id);
      }
    }
    return ids;
  }
  function _paintSuggestPrices(map) {
    if (!map) return;
    var hosts = document.querySelectorAll('seekmodo-suggest');
    for (var h = 0; h < hosts.length; h++) {
      var root = hosts[h].shadowRoot;
      if (!root) continue;
      var rows = root.querySelectorAll('[data-seekmodo-id]');
      for (var r = 0; r < rows.length; r++) {
        var id = rows[r].getAttribute('data-seekmodo-id');
        var row = id && map[id];
        if (!row) continue;
        var priceEl = rows[r].querySelector('.price');
        if (!priceEl) continue;
        if (row.display) {
          priceEl.textContent = row.display;
        }
      }
    }
  }
  function _hydrateSuggestPricesFromIds(ids) {
    if (!ids || !ids.length) return;
    var cacheKey = 'ids:' + ids.join(',');
    if (_priceHydrateCache[cacheKey]) {
      _paintSuggestPrices(_priceHydrateCache[cacheKey]);
      return;
    }
    if (_priceHydrateInflight === cacheKey) return;
    var base = (CFG && CFG.suggest_hydrate_url) || '/numinix_seekmodo_suggest.php';
    var sep = base.indexOf('?') >= 0 ? '&' : '?';
    _priceHydrateInflight = cacheKey;
    fetch(base + sep + 'seekmodo_action=prices&ids=' + encodeURIComponent(ids.join(',')), { credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        _priceHydrateInflight = null;
        if (!data || !data.ok || !data.prices || typeof data.prices !== 'object') return;
        _priceHydrateCache[cacheKey] = data.prices;
        _paintSuggestPrices(data.prices);
      })
      .catch(function () { _priceHydrateInflight = null; });
  }
  function _hydrateSuggestPrices() {
    _hydrateSuggestPricesFromIds(_collectSuggestProductIdsForPrices());
  }
  function _repaintCachedSuggestThumbs(force) {
    var merged = null;
    for (var k in _thumbHydrateCache) {
      if (!Object.prototype.hasOwnProperty.call(_thumbHydrateCache, k)) continue;
      merged = merged || Object.create(null);
      var m = _thumbHydrateCache[k];
      for (var id in m) {
        if (Object.prototype.hasOwnProperty.call(m, id)) merged[id] = m[id];
      }
    }
    if (merged) {
      _paintSuggestThumbs(merged, !!force);
      return;
    }
    if (!force) return;
    var hosts = document.querySelectorAll('seekmodo-suggest');
    for (var h = 0; h < hosts.length; h++) {
      var root = hosts[h].shadowRoot;
      if (!root) continue;
      var imgs = root.querySelectorAll('img.thumb');
      for (var i = 0; i < imgs.length; i++) {
        var src = imgs[i].getAttribute('data-src') || imgs[i].getAttribute('src');
        if (src) {
          var prior = imgs[i].getAttribute('data-src') || imgs[i].getAttribute('src') || '';
          _reloadSuggestThumbImg(imgs[i], src, prior);
        }
      }
    }
  }
  function _onSuggestTabVisible(q) {
    requestAnimationFrame(function () {
      _repaintCachedSuggestThumbs(true);
      if (q) _scheduleSuggestThumbHydrate(q);
    });
    setTimeout(function () {
      _repaintCachedSuggestThumbs(true);
    }, 50);
  }
  document.addEventListener('seekmodo-suggest:open', function (ev) {
    var q = ev && ev.detail && ev.detail.q;
    if (!q) return;
    _scheduleSuggestThumbHydrate(q);
    requestAnimationFrame(function () {
      _hydrateSuggestPrices();
    });
    setTimeout(function () {
      _hydrateSuggestPrices();
    }, 50);
    _scheduleSuggestStockReorder();
  });
  document.addEventListener('seekmodo-suggest:render', function (ev) {
    var q = ev && ev.detail && ev.detail.q;
    if (!q) return;
    _scheduleSuggestThumbHydrate(q);
    _scheduleSuggestStockReorder();
  });
  // Fired after product DOM is painted (web-components >= 0.3.13).
  document.addEventListener('seekmodo-suggest:rendered', function (ev) {
    var q = ev && ev.detail && ev.detail.q;
    if (!q) return;
    _hydrateSuggestThumbs(q);
    _hydrateSuggestPrices();
    _scheduleSuggestStockReorder();
  });
  var _stockOrderInflight = null;
  function _collectSuggestProductIdsForStock() {
    var ids = [];
    var seen = Object.create(null);
    var hosts = document.querySelectorAll('seekmodo-suggest');
    for (var h = 0; h < hosts.length; h++) {
      var root = hosts[h].shadowRoot;
      if (!root) continue;
      var nodes = root.querySelectorAll('[data-seekmodo-id]');
      for (var n = 0; n < nodes.length; n++) {
        var block = nodes[n].closest('[data-block="products"]')
          || nodes[n].closest('.product-grid');
        if (!block) continue;
        var id = nodes[n].getAttribute('data-seekmodo-id');
        if (!id || seen[id]) continue;
        seen[id] = true;
        ids.push(id);
      }
    }
    return ids;
  }
  function _reorderSuggestProductsLiveStock() {
    var ids = _collectSuggestProductIdsForStock();
    if (!ids.length) return;
    var cacheKey = ids.join(',');
    if (_stockOrderInflight === cacheKey) return;
    var base = (CFG && CFG.suggest_hydrate_url) || '/numinix_seekmodo_suggest.php';
    var sep = base.indexOf('?') >= 0 ? '&' : '?';
    _stockOrderInflight = cacheKey;
    fetch(base + sep + 'seekmodo_action=stock-order&ids=' + encodeURIComponent(ids.join(',')), { credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        _stockOrderInflight = null;
        if (!data || !data.ok || !Array.isArray(data.order)) return;
        var hosts = document.querySelectorAll('seekmodo-suggest');
        for (var h = 0; h < hosts.length; h++) {
          var root = hosts[h].shadowRoot;
          if (!root) continue;
          var grid = root.querySelector('.product-grid');
          if (!grid) continue;
          var cards = grid.querySelectorAll('[data-seekmodo-id]');
          if (!cards.length) continue;
          var byId = Object.create(null);
          for (var c = 0; c < cards.length; c++) {
            var cardId = cards[c].getAttribute('data-seekmodo-id');
            if (cardId) byId[cardId] = cards[c];
          }
          for (var i = 0; i < data.order.length; i++) {
            var el = byId[String(data.order[i])];
            if (el) grid.appendChild(el);
          }
        }
      })
      .catch(function () { _stockOrderInflight = null; });
  }
  function _scheduleSuggestStockReorder() {
    // seekmodo-suggest:render fires before scheduleRender()'s rAF paints
    // product cards — mirror thumb hydration's delayed retries.
    var delays = [0, 50, 150, 350, 800];
    for (var i = 0; i < delays.length; i++) {
      (function (delay) {
        setTimeout(function () {
          if (delay === 0) {
            requestAnimationFrame(function () {
              requestAnimationFrame(function () {
                _reorderSuggestProductsLiveStock();
              });
            });
            return;
          }
          _reorderSuggestProductsLiveStock();
        }, delay);
      })(delays[i]);
    }
  }
  document.addEventListener('seekmodo-suggest:tab-visible', function (ev) {
    var q = ev && ev.detail && ev.detail.q;
    _onSuggestTabVisible(q);
  });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) return;
    _onSuggestTabVisible('');
  });
  window.addEventListener('focus', function () {
    if (document.hidden) return;
    _onSuggestTabVisible('');
  });
})();</script>
JS;

        return "\n" . str_replace('%CFG%', $json, $tpl) . "\n";
    }

    /**
     * BCP-47-ish language code for `<seekmodo-suggest lang="…">`.
     * Derived from the active Zen Cart language directory name.
     */
    private function suggestLangCode(): string
    {
        $dir = '';
        if (isset($_SESSION['language']) && is_string($_SESSION['language'])) {
            $dir = strtolower(trim($_SESSION['language']));
        }
        if ($dir === '' && defined('DEFAULT_LANGUAGE')) {
            $dir = strtolower(trim((string) constant('DEFAULT_LANGUAGE')));
        }

        $map = [
            'english' => 'en',
            'german'  => 'de',
            'deutsch' => 'de',
            'spanish' => 'es',
            'espanol' => 'es',
            'french'  => 'fr',
            'francais'=> 'fr',
        ];

        return $map[$dir] ?? ($dir !== '' ? substr($dir, 0, 2) : 'en');
    }

    /**
     * Active Zen Cart language directory (english / german / deutsch / …).
     * Same resolution order as suggestLangCode() / init_numinix_seekmodo.php.
     */
    private function suggestLanguageDirectory(): string
    {
        $dir = '';
        if (isset($_SESSION['language']) && is_string($_SESSION['language'])) {
            $dir = strtolower(trim($_SESSION['language']));
        }
        if ($dir === '' && defined('DEFAULT_LANGUAGE')) {
            $dir = strtolower(trim((string) constant('DEFAULT_LANGUAGE')));
        }

        return $dir !== '' ? $dir : 'english';
    }

    /**
     * Load suggest label strings from the plugin language pack for $dir.
     * Prefer the array-returning lang.*.php form so values are not trapped
     * behind if (!defined()) when an earlier English pack already ran
     * (init can fire before the session language is known — NS-26042).
     *
     * @return array<string, string>
     */
    private function loadSuggestLabelPack(string $dir): array
    {
        $dirs = [$dir];
        if ($dir === 'german') {
            $dirs[] = 'deutsch';
        } elseif ($dir === 'deutsch') {
            $dirs[] = 'german';
        }

        $langRoot = dirname(__DIR__, 2) . '/languages/';
        foreach ($dirs as $candidateDir) {
            $base = $langRoot . $candidateDir . '/extra_definitions/';
            foreach (['lang.numinix_seekmodo.php', 'numinix_seekmodo.php'] as $file) {
                $path = $base . $file;
                if (!is_file($path)) {
                    continue;
                }
                // Discard incidental output (UTF-8 BOM) from include —
                // a BOM mid-<head> is not HTML whitespace and closes
                // </head> early, moving seekmodo:* metas into <body>
                // where document.head.querySelector cannot find them.
                ob_start();
                $loaded = include $path;
                ob_end_clean();
                if (is_array($loaded)) {
                    $out = [];
                    foreach ($loaded as $key => $value) {
                        if (is_string($key) && is_string($value) && $value !== '') {
                            $out[$key] = $value;
                        }
                    }

                    return $out;
                }
            }
        }

        return [];
    }

    /**
     * Suggest dropdown chrome labels from catalog language packs
     * (catalog/includes/languages/{lang}/extra_definitions/numinix_seekmodo.php).
     * Falls back to English defaults when a constant is undefined.
     *
     * @return array<string, string>
     */
    private function suggestLabels(): array
    {
        $defaults = [
            'recent'               => 'Recently searched',
            'trending'             => 'Trending',
            'keywords'             => 'Suggestions',
            'products'             => 'Products',
            'categories'           => 'Categories',
            'redirects'            => 'Redirects',
            'did_you_mean'         => 'Did you mean',
            'view_all'             => 'View all {total} results',
            'view_all_short'       => 'View all →',
            'results_for'          => '{total} results for ',
            'showing_results_for'  => 'Showing results for "{query}". Search instead for ',
            'products_count'       => '{count} products',
            'products_pending'     => 'Matching products appear when you pause typing…',
            'empty'                => 'No matches yet — keep typing.',
            'powered_by'           => 'Powered by ',
            'cors_blocked'         => "Search suggestions couldn't load because this site is blocked from reaching Seekmodo (CORS). Ask your store administrator to allowlist this domain on the Seekmodo gateway, or enable the connector's same-origin suggest proxy.",
        ];
        $map = [
            'recent'               => 'TEXT_SEEKMODO_SUGGEST_RECENT',
            'trending'             => 'TEXT_SEEKMODO_SUGGEST_TRENDING',
            'keywords'             => 'TEXT_SEEKMODO_SUGGEST_KEYWORDS',
            'products'             => 'TEXT_SEEKMODO_SUGGEST_PRODUCTS',
            'categories'           => 'TEXT_SEEKMODO_SUGGEST_CATEGORIES',
            'redirects'            => 'TEXT_SEEKMODO_SUGGEST_REDIRECTS',
            'did_you_mean'         => 'TEXT_SEEKMODO_SUGGEST_DID_YOU_MEAN',
            'view_all'             => 'TEXT_SEEKMODO_SUGGEST_VIEW_ALL',
            'view_all_short'       => 'TEXT_SEEKMODO_SUGGEST_VIEW_ALL_SHORT',
            'results_for'          => 'TEXT_SEEKMODO_SUGGEST_RESULTS_FOR',
            'showing_results_for'  => 'TEXT_SEEKMODO_SUGGEST_SHOWING_RESULTS_FOR',
            'products_count'       => 'TEXT_SEEKMODO_SUGGEST_PRODUCTS_COUNT',
            'products_pending'     => 'TEXT_SEEKMODO_SUGGEST_PRODUCTS_PENDING',
            'empty'                => 'TEXT_SEEKMODO_SUGGEST_EMPTY',
            'powered_by'           => 'TEXT_SEEKMODO_SUGGEST_POWERED_BY',
            'cors_blocked'         => 'TEXT_SEEKMODO_CORS_BLOCKED',
        ];

        // Authoritative pack for the active language (avoids English
        // constants stuck from an early init under the default locale).
        $pack = $this->loadSuggestLabelPack($this->suggestLanguageDirectory());

        $out = [];
        foreach ($map as $key => $const) {
            if (isset($pack[$const]) && is_string($pack[$const]) && $pack[$const] !== '') {
                $out[$key] = $pack[$const];
                continue;
            }
            if (defined($const)) {
                $val = (string) constant($const);
                if ($val !== '') {
                    $out[$key] = $val;
                    continue;
                }
            }
            $out[$key] = $defaults[$key];
        }

        return $out;
    }

    private function suggestLayout(): string
    {
        $allowed = ['cinema-grid', 'split-rail', 'command-bar', 'magazine', 'classic'];
        if (defined('NUMINIX_SEEKMODO_SUGGEST_LAYOUT')) {
            $v = strtolower(trim((string) constant('NUMINIX_SEEKMODO_SUGGEST_LAYOUT')));
            if ($v !== '' && in_array($v, $allowed, true)) {
                return $v;
            }
        }

        return 'split-rail';
    }

    private function suggestShowBranding(): bool
    {
        if (defined('NUMINIX_SEEKMODO_SUGGEST_SHOW_BRANDING')) {
            $v = strtolower(trim((string) constant('NUMINIX_SEEKMODO_SUGGEST_SHOW_BRANDING')));

            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function blocks(): string
    {
        if (defined('NUMINIX_SEEKMODO_SUGGEST_BLOCKS')) {
            $v = trim((string) constant('NUMINIX_SEEKMODO_SUGGEST_BLOCKS'));
            if ($v !== '') {
                return $v;
            }
        }

        return 'recent,did_you_mean,keywords,trending,products,categories';
    }

    private function viewAllHref(): string
    {
        if (defined('NUMINIX_SEEKMODO_SUGGEST_VIEW_ALL_HREF')) {
            $v = trim((string) constant('NUMINIX_SEEKMODO_SUGGEST_VIEW_ALL_HREF'));
            if ($v !== '') {
                return $v;
            }
        }

        // Match the storefront's native SERP route. ZC 1.5.8+ stores expose
        // pages/search_result; legacy forks (KIP, older Numinix) only ship
        // advanced_search_result. Linking to a missing search_result page
        // 301s to index.html?keyword=… and renders the homepage.
        $mainPage = $this->serpMainPage();
        $suffix = $this->serpViewAllQuerySuffix($mainPage);

        if (!defined('DIR_WS_CATALOG')) {
            return '/?main_page=' . $mainPage . '&keyword={q}&pfrom={price_from}&pto={price_to}' . $suffix;
        }

        return ((string) constant('DIR_WS_CATALOG'))
            . 'index.php?main_page=' . $mainPage . '&keyword={q}&pfrom={price_from}&pto={price_to}' . $suffix;
    }

    /**
     * Which main_page renders the full search-results template on this host.
     */
    private function serpMainPage(): string
    {
        $catalog = defined('DIR_FS_CATALOG') ? (string) DIR_FS_CATALOG : '';
        if ($catalog !== '' && is_file($catalog . 'includes/modules/pages/search_result/header_php.php')) {
            return defined('FILENAME_SEARCH_RESULT')
                ? (string) FILENAME_SEARCH_RESULT
                : 'search_result';
        }
        if ($catalog !== '' && is_file($catalog . 'includes/modules/pages/advanced_search_result/header_php.php')) {
            return defined('FILENAME_ADVANCED_SEARCH_RESULT')
                ? (string) FILENAME_ADVANCED_SEARCH_RESULT
                : 'advanced_search_result';
        }

        return defined('FILENAME_SEARCH_RESULT')
            ? (string) FILENAME_SEARCH_RESULT
            : 'search_result';
    }

    /**
     * Extra query params the header search form sends with the SERP target.
     */
    private function serpViewAllQuerySuffix(string $mainPage): string
    {
        $advanced = defined('FILENAME_ADVANCED_SEARCH_RESULT')
            ? (string) FILENAME_ADVANCED_SEARCH_RESULT
            : 'advanced_search_result';
        if ($mainPage === $advanced) {
            return '&search_in_description=1';
        }

        return '';
    }
}
