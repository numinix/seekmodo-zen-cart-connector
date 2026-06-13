# Seekmodo Zen Cart connector — v1.0.22 changelog

## Summary

v1.0.22 is an **asset-URL hotfix on top of v1.0.21**. The v1.0.21
release emitted the new `<seekmodo-suggest>` bundle as
`/catalog/includes/templates/template_default/jscript/seekmodo_suggest.bundle.js`
and the browser-token refresh URL as
`/catalog/numinix_seekmodo_suggest.php?action=browser-token`, but Zen
Cart 2.x's plugin loader merges PHP includes via `auto_loaders` only —
it does **not** merge static assets or catalog-root PHP shims into the
live catalog filesystem. The bundle URL therefore returned 404/406 on
every storefront and the refresh URL 302-redirected to the storefront
home before the route handler could run, so the universal suggest
widget never reached the browser and shoppers continued to see the
legacy search UX.

v1.0.22 fixes this without requiring any per-tenant deploy step:

- `NuminixSeekmodoSuggestObserver::bundleSrc()` now emits the bundle
  URL pointing at the plugin's own versioned directory
  (`/catalog/zc_plugins/Seekmodo/v1.0.22/catalog/includes/templates/template_default/jscript/seekmodo_suggest.bundle.js`),
  where the file actually lives.
- `NuminixSeekmodoSuggestObserver::refreshUrl()` likewise points at
  `/catalog/zc_plugins/Seekmodo/v1.0.22/catalog/numinix_seekmodo_suggest.php?action=browser-token`.
- The catalog-root PHP shims (`numinix_seekmodo_suggest.php`,
  `numinix_seekmodo_recommend.php`, `numinix_seekmodo_pair_callback.php`,
  `numinix_seekmodo_forget_me.php`) now resolve
  `includes/application_top.php` via a `__DIR__`-rooted candidate list
  so they work both at the live catalog root (legacy tenant deploys
  that hand-copied them there) and at the plugin's versioned dir (the
  v1.0.22+ URL the observer emits).

No configuration, schema, or behavioral changes beyond making the
v1.0.21 storefront UX reach the browser. Sites already on v1.0.21
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
