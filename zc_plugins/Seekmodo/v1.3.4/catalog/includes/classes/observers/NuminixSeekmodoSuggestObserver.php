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
 *   - `NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY` constant — when true,
 *     emits the legacy v1.0.20 flat-row vanilla-JS dropdown
 *     (`seekmodo_typeahead.legacy.js`) instead of the new bundle. Both
 *     files are shipped in the install but the choice is mutually
 *     exclusive — never both at once.
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
        if (!function_exists('numinix_seekmodo_enabled')) {
            return false;
        }
        if (!numinix_seekmodo_enabled()) {
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
        // Bust Cloudflare / browser year-long cache on plugin JS updates.
        if (!$useLegacy) {
            $disk = dirname(__DIR__, 4)
                . '/catalog/includes/templates/template_default/jscript/' . $file;
            if (is_readable($disk)) {
                $url .= '?v=' . (string) filemtime($disk);
            }
        }

        return $url;
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

        return 'v1.1.1';
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
        $cfg = [
            'selectors'     => [
                'input[name="keyword"]',
                'input#keyword',
                'input[data-seekmodo-typeahead]',
                'input[data-seekmodo-suggest]',
            ],
            'blocks'        => $blocks,
            'view_all_href' => $viewAll,
            'layout'         => $this->suggestLayout(),
            'show_branding'  => $this->suggestShowBranding(),
            'click_endpoint' => $this->clickEndpoint(),
            'suggest_hydrate_url' => $this->suggestShimUrl(),
            'serp_parity_submit' => function_exists('numinix_seekmodo_mode')
                && numinix_seekmodo_mode() === 'enforce',
            'serp_passthrough' => function_exists('_numinix_seekmodo_typesense_tuning_params')
                ? _numinix_seekmodo_typesense_tuning_params(true)
                : new \stdClass(),
            'extras'         => [
                'min-length'  => '2',
                'debounce-ms' => '150',
                'limit'       => '15',
                'cache-size'  => '32',
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
      var viewAllOptOut = CFG.view_all_href || '/index.php?main_page=advanced_search_result&keyword={q}';
      var nodesOptOut = document.querySelectorAll('seekmodo-suggest');
      for (var o = 0; o < nodesOptOut.length; o++) {
        nodesOptOut[o].removeAttribute('vehicle-id');
        nodesOptOut[o].removeAttribute('serp-passthrough');
        nodesOptOut[o].setAttribute('view-all-href', viewAllOptOut);
      }
      return;
    }
    var ctx = resolveVehicleContext();
    var pt = buildVehicleFilterPassthrough(ctx);
    var vid = ctx && Number(ctx.vehicle_id) > 0 ? Number(ctx.vehicle_id) : 0;
    var viewAll = CFG.view_all_href || '/index.php?main_page=advanced_search_result&keyword={q}';
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
  }
  window.addEventListener('seekmodo:vehicle:selected', syncSuggestVehicleFilter);
  window.addEventListener('seekmodo:vehicle:cleared', syncSuggestVehicleFilter);
  document.addEventListener('change', function (e) {
    if (!e || !e.target || !e.target.matches) return;
    if (e.target.matches('#yearSelect, #makeSelect, #modelSelect, select[name="year"], select[name="make"], select[name="model"]')) {
      syncSuggestVehicleFilter();
    }
  }, true);
  function scan(root) {
    try {
      var inputs = (root || document).querySelectorAll(SELECTOR);
      for (var i = 0; i < inputs.length; i++) attach(inputs[i]);
    } catch (_e) { /* malformed selector — bail silently */ }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(document); syncSuggestVehicleFilter(); });
  } else {
    scan(document);
    syncSuggestVehicleFilter();
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
  //   - products WITHOUT row.url                 -> view-all-href with {q}
  //                                                 substituted by row.name
  //                                                 +  seekmodo_skip_category_redirect=1
  //                                                 (defensive -- shopper
  //                                                 picked a specific
  //                                                 product, mis-routing to
  //                                                 a category subtree
  //                                                 would drop their
  //                                                 intent.)
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
    if ((block === 'products' || block === 'categories')
        && row && typeof row.url === 'string' && row.url) {
      window.location.href = row.url;
      return;
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
    var nav = viewAll.replace('{q}', encodeURIComponent(keyword));
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
    var nav = viewAll.replace('{q}', encodeURIComponent(q));
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
  // v1.3.4 KIP — hydrate suggest thumbnails from the storefront PHP
  // shim. `<seekmodo-suggest>` fetches /v1/suggest via the gateway;
  // older KIP catalog pushes omitted image_url, so productImageSrc()
  // renders empty thumb placeholders even though numinix_seekmodo_suggest.php
  // can enrich rows from zen_get_products_image(). Match rows by
  // data-seekmodo-id after the dropdown opens.
  var _thumbHydrateCache = Object.create(null);
  var _thumbHydrateInflight = null;
  function _absSuggestImageUrl(u) {
    if (!u || typeof u !== 'string') return '';
    u = u.trim();
    if (!u) return '';
    if (/^https?:\/\//i.test(u)) return u;
    if (u.charAt(0) === '/') return window.location.origin + u;
    return window.location.origin + '/' + u.replace(/^\//, '');
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
  function _paintSuggestThumbs(map) {
    if (!map) return;
    var hosts = document.querySelectorAll('seekmodo-suggest');
    for (var h = 0; h < hosts.length; h++) {
      var root = hosts[h].shadowRoot;
      if (!root) continue;
      var rows = root.querySelectorAll('[data-seekmodo-id]');
      for (var r = 0; r < rows.length; r++) {
        var id = rows[r].getAttribute('data-seekmodo-id');
        var src = id && map[id];
        if (!src) continue;
        var img = rows[r].querySelector('img.thumb');
        if (img && img.getAttribute('src') && img.complete && img.naturalWidth > 0) continue;
        var empty = rows[r].querySelector('.thumb-empty');
        if (empty) {
          var ni = document.createElement('img');
          ni.className = 'thumb';
          ni.setAttribute('part', 'thumb');
          ni.loading = 'eager';
          ni.decoding = 'async';
          ni.alt = '';
          ni.src = src;
          empty.replaceWith(ni);
        } else if (img) {
          img.src = src;
        }
      }
    }
  }
  function _collectSuggestProductIdsNeedingImages() {
    var ids = [];
    var seen = Object.create(null);
    var hosts = document.querySelectorAll('seekmodo-suggest');
    for (var h = 0; h < hosts.length; h++) {
      var root = hosts[h].shadowRoot;
      if (!root) continue;
      var rows = root.querySelectorAll('[data-seekmodo-id]');
      for (var r = 0; r < rows.length; r++) {
        if (!rows[r].querySelector('.thumb-empty')) continue;
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
        _thumbHydrateCache[cacheKey] = data.images;
        _paintSuggestThumbs(data.images);
      })
      .catch(function () { _thumbHydrateInflight = null; });
  }
  function _hydrateSuggestThumbs(q) {
    q = String(q || '').trim();
    var ids = _collectSuggestProductIdsNeedingImages();
    if (ids.length > 0) {
      _hydrateSuggestThumbsFromIds(ids);
      return;
    }
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
          if (id && url) map[id] = url;
        }
        _thumbHydrateCache[q] = map;
        _paintSuggestThumbs(map);
      })
      .catch(function () { _thumbHydrateInflight = null; });
  }
  document.addEventListener('seekmodo-suggest:open', function (ev) {
    var q = ev && ev.detail && ev.detail.q;
    if (!q) return;
    requestAnimationFrame(function () { _hydrateSuggestThumbs(q); });
    setTimeout(function () { _hydrateSuggestThumbs(q); }, 50);
  });
})();</script>
JS;

        return "\n" . str_replace('%CFG%', $json, $tpl) . "\n";
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
            return '/?main_page=' . $mainPage . '&keyword={q}' . $suffix;
        }

        return ((string) constant('DIR_WS_CATALOG'))
            . 'index.php?main_page=' . $mainPage . '&keyword={q}' . $suffix;
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
