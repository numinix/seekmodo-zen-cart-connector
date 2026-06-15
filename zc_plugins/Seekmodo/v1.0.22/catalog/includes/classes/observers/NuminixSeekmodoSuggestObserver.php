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
        }

        // Script tag — `defer` so the head-tag parse doesn't block; the
        // bundle is idempotent on `customElements.define` so repeated
        // loads (multiple iframes, AJAX-injected page sections) are
        // safe.
        $srcAttr = htmlspecialchars($bundleSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out .= '<script src="' . $srcAttr . '" defer></script>' . "\n";

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

        return $base . 'zc_plugins/Seekmodo/' . $version
            . '/catalog/includes/templates/template_default/jscript/' . $file;
    }

    /**
     * Derive the plugin's version directory name from `__DIR__`.
     *
     * Observer file lives at
     *   zc_plugins/Seekmodo/v<version>/catalog/includes/classes/observers/NuminixSeekmodoSuggestObserver.php
     * `__DIR__` is the `observers/` directory, so FOUR `dirname()` calls
     * land on `zc_plugins/Seekmodo/v<version>` (the version directory we
     * want). v1.0.22's original code used `dirname(__DIR__, 5)` which
     * walks one level too high and lands on `zc_plugins/Seekmodo`, so
     * `basename()` returned "Seekmodo", the guard rejected it, and the
     * method silently fell back to the hard-coded "v1.0.22" string.
     * The bug went undetected here because the fallback string matched
     * the plugin version; v1.1.0 / v1.1.1 inherited the same off-by-one
     * + the same "v1.0.22" fallback and ended up shipping the wrong
     * bundle URL on every page.
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

        return 'v1.0.22';
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

        return $base . 'numinix_seekmodo_suggest.php?action=browser-token';
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
            'extras'        => [
                'min-length'  => '2',
                'debounce-ms' => '150',
                'limit'       => '5',
                'cache-size'  => '32',
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
  }
  function scan(root) {
    try {
      var inputs = (root || document).querySelectorAll(SELECTOR);
      for (var i = 0; i < inputs.length; i++) attach(inputs[i]);
    } catch (_e) { /* malformed selector — bail silently */ }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(document); });
  } else {
    scan(document);
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
  //   - products / categories WITHOUT row.url    -> view-all-href with {q}
  //                                                 substituted by row.name
  //                                                 (defensive — the
  //                                                 indexer now stamps url,
  //                                                 but older docs may
  //                                                 lack it).
  document.addEventListener('seekmodo-suggest:row-click', function (ev) {
    var detail = (ev && ev.detail) || {};
    var block = detail.block;
    var row = detail.row || {};
    var q = String(detail.q || '');
    if ((block === 'products' || block === 'categories')
        && row && typeof row.url === 'string' && row.url) {
      window.location.href = row.url;
      return;
    }
    var keyword = '';
    if (block === 'did_you_mean') {
      keyword = String(detail.value || (row && row.value) || q);
    } else if (block === 'recent' || block === 'trending' || block === 'keywords') {
      keyword = String((row && row.keyword) || detail.value || q);
    } else if (block === 'products' || block === 'categories') {
      keyword = String((row && (row.name || row.title)) || detail.value || q);
    } else {
      keyword = String(detail.value || q);
    }
    if (!keyword) return;
    var viewAll = (CFG && CFG.view_all_href) || '/search?q={q}';
    window.location.href = viewAll.replace('{q}', encodeURIComponent(keyword));
  });
})();</script>
JS;

        return "\n" . str_replace('%CFG%', $json, $tpl) . "\n";
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
        // Zen Cart core SERP URL — same target the storefront form
        // submits to.
        if (!defined('DIR_WS_CATALOG')) {
            return '/?main_page=advanced_search_result&keyword={q}';
        }

        return ((string) constant('DIR_WS_CATALOG'))
            . 'index.php?main_page=advanced_search_result&keyword={q}';
    }
}
