# Seekmodo Zen Cart connector — v1.0.22 changelog

## Summary

v1.0.22 is an **SM-606 universal-suggest plumbing fixup on top of
v1.0.21**. v1.0.21 shipped the new `<seekmodo-suggest>` bundle but
three independent bugs in the plumbing meant the new dropdown never
actually rendered on any storefront, so shoppers continued to see
the legacy search UX (input box with no rich dropdown):

1. **Bundle URL pointed at the live template tree.** v1.0.21 emitted
   `/catalog/includes/templates/template_default/jscript/seekmodo_suggest.bundle.js`,
   but Zen Cart 2.x's plugin loader merges PHP includes via
   `auto_loaders` only — it does **not** merge static assets into the
   live catalog template tree. The bundle URL therefore returned
   404/406 on every storefront.
2. **`numinix_seekmodo_client()` public accessor was never written.**
   The v1.0.21 observer's inline browser-token mint
   (`NuminixSeekmodoSuggestObserver::browserToken()`) and the four
   catalog-root PHP shims (`numinix_seekmodo_suggest.php` etc.) all
   `function_exists('numinix_seekmodo_client')` then call the
   function — but the library only ever exposed
   `_numinix_seekmodo_client()` (underscore-prefixed). The
   function-exists guard meant the storefront never errored out — it
   just silently served `<meta name="seekmodo:token" content="">`
   (no inline token) and `{"error":"unpaired"}` from the refresh
   shim, so the `<seekmodo-suggest>` bundle had no way to reach the
   gateway even when the storefront was paired correctly.
3. **PHP shims lived only inside the plugin tree.** v1.0.21 assumed
   the catalog-root PHP shims would be reachable at
   `/catalog/numinix_seekmodo_suggest.php` etc., but Zen Cart's
   plugin loader doesn't route them and ZC ships a stock
   `zc_plugins/.htaccess` that denies direct HTTP access to `.php`
   files under `zc_plugins/` (intentional ZC hardening). The shims
   have to be deployed at the live catalog root via the tenant
   repo's rsync-from-git pipeline. v1.0.13 already did this for
   `numinix_seekmodo_pair_callback.php`; the other three shims were
   missed in the v1.0.21 release.

v1.0.22 fixes all three bugs without requiring any per-tenant copy
step beyond a single new catalog-root commit per tenant repo:

- `NuminixSeekmodoSuggestObserver::bundleSrc()` now emits the bundle
  URL pointing at the plugin's own versioned directory
  (`/catalog/zc_plugins/Seekmodo/v1.0.22/catalog/includes/templates/template_default/jscript/seekmodo_suggest.bundle.js`),
  where the file actually lives. ZC's stock `zc_plugins/.htaccess`
  allows direct HTTP access to `.js` files, so the bundle is
  reachable in-place — no rsync into the active template's `jscript/`
  folder needed. Version is derived from `__DIR__` via a new
  `pluginVersion()` helper so the observer stays self-consistent
  across future version bumps.
- `includes/functions/numinix_seekmodo_client.php` now exports a
  public `numinix_seekmodo_client(): ?\Numinix\Seekmodo\Client`
  wrapper that aliases the existing `_numinix_seekmodo_client()`
  internal helper. Storefront observer's inline browser-token mint
  and all four catalog-root PHP shims now resolve the SDK Client
  correctly and can mint a real `tenants/token` JWT (or call
  `recommend.*` / `tenant.shopper.forget` on the gateway). Without
  this fix the connector would emit the new `<seekmodo-suggest>`
  meta tags + bundle script tag fine, but every browser-token
  refresh attempt would 503 with `{"error":"unpaired"}` and the
  bundle would silently fall back to "no dropdown".

The browser-token refresh URL keeps the v1.0.21 path
(`/catalog/numinix_seekmodo_suggest.php?action=browser-token`)
because `zc_plugins/.htaccess` denies direct HTTP access to `.php`
files inside `zc_plugins/` — that's intentional Zen Cart hardening
and we don't override it. The shim file therefore still needs to
live at the live catalog root; tenant repos that ship through the
rsync-from-git deploy flow keep a committed copy of each
catalog-root shim alongside the rest of the storefront tree (same
posture as `numinix_seekmodo_pair_callback.php`, which has been
deployed this way since v1.0.13).

As defensive belt-and-suspenders, the four catalog-root PHP shims
(`numinix_seekmodo_suggest.php`, `numinix_seekmodo_recommend.php`,
`numinix_seekmodo_pair_callback.php`, `numinix_seekmodo_forget_me.php`)
gained a `__DIR__`-rooted `application_top.php` resolver plus a
`chdir()` to the catalog root before requiring it. This is a no-op
when the file is at the live catalog root (CWD already correct) and
makes the shim runnable from any nested location — useful for
operators who symlink or otherwise embed the shim inside the plugin
tree.

No configuration, schema, or behavioral changes beyond making the
v1.0.21 storefront bundle reach the browser. Sites already on v1.0.21
upgrade via the standard Zen Cart Plugin Manager "Upgrade" button (or
auto-promotion path); the upgrade is idempotent.

## v1.0.21 changelog (unchanged below)

## Summary

v1.0.21 ships **SM-606 — the Universal Suggest Widget**. Storefront
typeahead now renders the new `<seekmodo-suggest>` web component (the
same custom element the WordPress / BigCommerce / AKS connectors
enqueue), reading the rich `/v1/suggest` envelope: recent +
did_you_mean + keywords + trending + products + categories +
"View all N results" CTA, all from one server round-trip. The legacy
v1.0.14-era three-section vanilla-JS dropdown is preserved on disk
and selectable via a constant for one major-version compat window.

This is **phase E** of the connector typeahead spec at
`seekmodo/docs/CONNECTOR_TYPEAHEAD_SPEC.md` — the same component now
ships across every Seekmodo connector platform so a tenant with a
mixed-platform footprint (e.g. a Zen Cart B2B site + a WordPress
content site + an AKS storefront) gets a single suggestions UX.

## What changed

- **New observer** `NuminixSeekmodoSuggestObserver` (slot 200 / class
  loaders, hooks `NOTIFY_HTML_HEAD_END`) emits the SDK meta tags
  (`seekmodo:tenant`, `seekmodo:gateway`, `seekmodo:refresh`,
  `seekmodo:token`), injects the bundle `<script>`, and ships an
  inline autoboot that walks the same selectors the v1.0.14-era
  dropdown auto-attached to (`input[name="keyword"]`, `input#keyword`,
  `input[data-seekmodo-typeahead]`) and inserts a
  `<seekmodo-suggest input="…">` sibling after each match.
- **New asset** `catalog/includes/templates/template_default/jscript/
  seekmodo_suggest.bundle.js` — the self-registering IIFE bundle
  copied from `@seekmodo/web-components` (~7.25 KB gzip; under the
  12 KB plan target). Loaded via explicit `<script src>` injected by
  the observer (not via Zen Cart's `jscript_*` auto-include) so the
  legacy vs. new choice is mutually exclusive at the source.
- **Renamed legacy asset** `jscript_seekmodo_typeahead.js` →
  `seekmodo_typeahead.legacy.js`. The non-`jscript_` prefix means
  Zen Cart's html-header loader no longer auto-includes it; the
  observer emits it explicitly when the operator opts back in.
- **Updated PHP endpoint** `catalog/numinix_seekmodo_suggest.php`
  gains a new `?action=browser-token` route returning
  `{token, expires_at, session_id}` so a long-running tab can refresh
  the gateway-direct JWT without a page reload (5-min TTL). Same
  shape the bundled SDK's `seekmodo:refresh` URL expects. APCu-cached
  per-tenant so a flood of refresh calls coalesces to ~1 mint / 4 min.
  The existing `?q=...` suggest route is unchanged — it stays as the
  REST fallback the bundle uses when the gateway-direct path is
  unavailable (cold cache, no browser token minted, breaker open).

## Operator overrides

All constants — flip them in a tenant overrides file (per Zen Cart
convention), nothing to wire up in admin.

| Constant | Default | Effect |
| -------- | ------- | ------ |
| `NUMINIX_SEEKMODO_SUGGEST_ENABLED` | true | Master toggle. Setting to false suppresses the suggest UI site-wide (legacy + new). |
| `NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY` | false | When true, emits `seekmodo_typeahead.legacy.js` (the v1.0.20 three-section dropdown) instead of the new bundle. Mutually exclusive — never both. |
| `NUMINIX_SEEKMODO_SUGGEST_BLOCKS` | `recent,did_you_mean,keywords,trending,products,categories` | CSV controlling which blocks the widget renders, in order. Drop a block by leaving it out. |
| `NUMINIX_SEEKMODO_SUGGEST_VIEW_ALL_HREF` | `index.php?main_page=advanced_search_result&keyword={q}` | URL template for the "View all N results" CTA at the bottom of the dropdown. |

## KIP impact

KIP's `numinix_seekmodo_suggest.php` catalog-root override (the
per-token multi-recall blend that joined `beer mug → Beer Tankards`
into a single dropdown) is now **redundant**. WS-2 of the
universal-suggest-widget plan absorbed the same interleave logic into
`SuggestTool::loadTypesenseBlocks` on the gateway side, so the
bespoke per-tenant shim can be deleted on the next KIP push without a
behavior regression. The KIP storefront will then read the same
universal envelope every other Seekmodo tenant reads.

## Performance

Matches the AKS-3801e baseline (sub-300 ms p95 cold cache, <16 ms
warm-cache render) via the bundle's built-in 150 ms keystroke
debounce, AbortController cancel on next keystroke, 32-entry LRU
cache, single rAF-batched render, and a skeleton loader masking the
first-render latency on a cold cache.

## Spec reference

- Gateway-side rich envelope: see WS-1 / WS-2 of
  `seekmodo/.cursor/plans/universal_suggest_widget_*.plan.md`.
- Web-component customization surface: see `@seekmodo/web-components`
  `src/components/suggest.ts` header.
- Connector spec: `seekmodo/docs/CONNECTOR_TYPEAHEAD_SPEC.md` phase E.
