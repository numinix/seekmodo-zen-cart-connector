# Seekmodo Zen Cart connector — v1.1.1 changelog

## Fix-pack #3 -- category rows trigger resolver redirect (2026-06-15)

Pairs with the seekmodo gateway's per-doc breadcrumb walk (shipped
in the same day's `fix/suggest-categories-walk-*` PRs) that finally
gets the `<seekmodo-suggest>` Categories block populated on Zen Cart
tenants. The block had been empty in practice because the gateway's
prior facet-only path required `facet=true` on the breadcrumb field,
which the indexer didn't flag.

This fix-pack updates the inline `seekmodo-suggest:row-click`
handler in `NuminixSeekmodoSuggestObserver::renderRowClickHandler()`
so a click on a `categories` row WITHOUT an explicit `row.url`
navigates to the view-all URL with the leaf category name as the
keyword AND WITHOUT the `seekmodo_skip_category_redirect=1` marker
that fix-pack #2 added. With no skip marker,
`NuminixSeekmodoObserver::onAdvancedSearchStart` runs its resolver;
since the gateway now ships the LEAF as `row.name` (e.g.
`"Motorcycle Lift Wheel Vise"`, not the full breadcrumb path), the
resolver scores an exact-normalised match at 1.00 and 302's the
shopper straight to the matching category landing page -- Klevu /
Algolia parity for the explicit-category-click case.

Other block kinds keep the fix-pack #2 behaviour: `recent`,
`trending`, `keywords`, `did_you_mean`, and the defensive
products-without-url fallback all still stamp the skip marker so
the resolver doesn't override the shopper's explicit choice.

## Suggest dropdown widens to 480 px default (2026-06-15)

Refreshes the vendored
`catalog/includes/templates/template_default/jscript/seekmodo_suggest.bundle.js`
to `@seekmodo/web-components@0.2.1`. The bundle now anchors with a
480 px default `anchor-min-width` instead of the previous 320.

Catalog-grade product names like `Handy Standard SBC990 Snowmobile
Lift` and `Redline TR1500 Trailer` were truncating at ~15 chars in
the typeahead dropdown on storefronts where the search input is the
typical ~280 px width (the previous 320 min-width barely cleared the
input itself, leaving no room for long product names). 480 px now
comfortably fits ~35 chars of name + the price column + thumbnail
without wrapping.

A new viewport-right clamp keeps the dropdown from painting
off-screen when a narrow mobile viewport can't accommodate 480 px —
the rendered width caps at `viewport.right - input.left - 8 px
gutter` (e.g. a 360 px mobile viewport with a search input at
left=16 produces a 336 px dropdown, not 480).

### What's intentionally NOT changing in v1.1.1

- The PHP SDK pin (`numinix/seekmodo-connector ^0.2`) and the
  vendored copy under `catalog/includes/library/Numinix/SeekmodoSdk/`
  stay at the same version as v1.1.0.
- The legacy `Numinix\Seekmodo\*` adapter classes and the catalog-
  root PHP shims are byte-identical to v1.1.0.
- No schema, behaviour, or operator-policy changes — only the
  bundled JS asset shifts.

---

# Seekmodo Zen Cart connector — v1.1.0 changelog

## PHP SDK + connector migration, phase 3 (2026-06-14)

Internal refactor — the shared transport / breaker / mode-FSM / pairing
/ events code lifted out into a new Composer package,
`numinix/seekmodo-connector` (PSR-4 root `Numinix\SeekmodoSdk\`), and
is now vendored into this plugin tree at build time by
`tools/build_release.py`. The same SDK now powers the WordPress and
AKS connectors so a single bug fix lands everywhere on the next
release.

### What's in the box

- New PSR-4 prefix `Numinix\SeekmodoSdk\` →
  `catalog/includes/library/Numinix/SeekmodoSdk/`, registered by
  `init_numinix_seekmodo.php`.
- `tools/build_release.py vendor_sdk()` runs `composer install --no-dev`
  and copies `vendor/numinix/seekmodo-connector/src/*` into the per-
  version plugin tree right before zipping.
- Root `composer.json` requiring `numinix/seekmodo-connector ^0.2`.

### What's intentionally NOT changing in v1.1.0

- The connector's own `Numinix\Seekmodo\*` classes (`Client`, `Pairing`,
  `RemoteConfig`, `AutoPromoter`, `ApcuCircuitBreakerStore`,
  `EnvProbe`, `PromotionStore`, `UpdateApplier`, `UpdateClient`,
  `WellKnownWriter`) are present unchanged.
- The procedural shims (`numinix_seekmodo_client.php`,
  `numinix_seekmodo_search_lib.php`, `numinix_seekmodo_indexer_lib.php`,
  `numinix_seekmodo_events_lib.php`, etc.) keep calling
  `\Numinix\Seekmodo\Client::fromConfiguration()` and friends.
- The storefront hot path is byte-identical to v1.0.22.

See [`../../MIGRATION.md`](../../MIGRATION.md) for the v1.1 → v1.2 →
v2.0 collapse-to-adapter plan.

### Runtime composer dependency

None. Composer is used **only** at build time by the operator-local
`tools/build_release.py`. The shipped plugin zip carries the SDK as
plain PHP files; the plugin's manual PSR-4 autoloader resolves them
without composer being installed on the storefront host.

## Past versions

### In-place refresh #6 — CSP drop-in template (2026-06-14)

Adds `zc_plugins/Seekmodo/v1.0.22/INSTALL/csp_seekmodo.php` — a
ready-to-deploy CSP rule the operator copies into the storefront's
`includes/extra_csp_policies/` on Zen Cart sites that emit a strict
Content-Security-Policy header (the Numinix CSP loader at
`includes/csp_policy_config.php` + glob of
`includes/extra_csp_policies/*.php`).

**Why this is needed.** With fix-pack #5 in place the
`<seekmodo-suggest>` widget can mint its browser token against the
same-origin shim, but the very next step — POSTing
`/v1/suggest` against `https://mcp.seekmodo.com` — is blocked at
the browser by the storefront's CSP unless `mcp.seekmodo.com`
appears on `connect-src`. The SDK surfaces the block as
`[seekmodo-suggest] fetch failed Seekmodo network failure: Failed
to fetch` and the dropdown stays empty.

Symptom reproduced on `numinix.com` and `numinix.ca` after
fix-pack #5 landed. `redlinestands.com/catalog/` does not emit a
CSP header and is unaffected; vanilla Zen Cart installs ship
without CSP and likewise need nothing.

The drop-in adds `mcp.seekmodo.com` to `script-src` and
`connect-src`, plus the `*.seekmodo.com` wildcard on `connect-src`
so any future regional gateway shards are pre-authorised. No DB
schema or observer change — this is a single PHP file the
operator copies on deploy.

## Summary

v1.0.22 is an **SM-606 universal-suggest plumbing fixup on top of
v1.0.21**. v1.0.21 shipped the new `<seekmodo-suggest>` bundle but
four independent bugs in the plumbing meant the new dropdown never
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
4. **`callTool('tenants/token', ...)` short-circuited on a regex
   check.** The browser-token mint path called
   `Client::callTool('tenants/token', ['ttl_seconds' => 300])`, but
   `callTool()`'s tool-name regex
   (`^[A-Za-z0-9_.\-]{2,128}$`) intentionally rejects slashes — the
   gateway's tool catalog is dot-separated (`tenant.shopper.forget`,
   `recommend.related`, etc.) and `tenants/token` is not actually
   a tool but a non-tool admin endpoint (Sprint 7 PR 2 in the
   gateway repo). The regex rejection meant every browser-token
   mint silently returned null and surfaced as
   `{"error":"mint_failed"}` from the refresh-URL shim and an
   empty inline `<meta name="seekmodo:token">` from the head
   observer — even after bugs 1, 2, and 3 above were fixed.

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
- `Client::mintBrowserToken(int $ttlSeconds = 300, string $sid = ''): ?array`
  is a new public method that POSTs `/v1/tenants/token` directly
  (bypassing `callTool()`'s dot-only regex). The two callsites
  that used to pass `'tenants/token'` to `callTool()` (the head
  observer's `browserToken()` and the suggest shim's
  `?action=browser-token` branch) now call `mintBrowserToken()`
  instead. Wire shape matches the gateway's
  `{token, expires_at, issued_at, scope, session_id, token_type}`
  envelope — callers typically only need `token` + `expires_at`
  but the rest is passed through so the WP-equivalent
  `Frontend\TypeaheadUI` payload can be served identically.

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
