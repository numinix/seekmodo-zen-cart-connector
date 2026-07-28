# Seekmodo for Zen Cart - top-level changelog

This file tracks what's in the **latest** released zip. The full
per-version detail lives next to the source under
zc_plugins/Seekmodo/v<X.Y.Z>/CHANGELOG.md.

## v1.3.34 - 2026-07-28 (Push catalog PHP CLI discovery)

- **Push catalog now on PHP 8.3 / EasyApache** - Connect -> Push catalog
  now resolves CLI PHP on hosts where FPM $PATH is empty and the
  previous candidate list stopped at ea-php82. Also derives CLI from
  PHP_BINARY, supports NUMINIX_SEEKMODO_PHP_BINARY, and passes
  --ack-quota on admin-forked pushes. Fixes
  
o php binary found in $PATH` on PHP 8.3 storefronts (NS-26042).

## v1.3.33 - 2026-07-27 (events referer parity)

- **Shopper-context parity (Rec 3)** - click / impression / conversion
  event builders in 
uminix_seekmodo_events_lib.php now stamp
  HTTP_REFERER onto the event when present (same as search /
  typeahead payloads).

## v1.3.32 - 2026-07-27 (HMAC auth fallback classification)

- **Auth misconfig native fallback** - gateway `auth_fail` /
  `signature_mismatch` 4xx responses are logged as `auth_misconfig`
  with `fallback_reason = auth_misconfig` (storefront still falls back
  to native Zen Cart search via `null` return). Fixes contradictory
  docblocks that claimed signature mismatches must not degrade; pairing
  drift is now documented and classified like AKS connector
  `KIND_AUTH_MISCONFIG`. `rate_limited` / malformed remain `caller_error`.

## v1.3.31 - 2026-07-24 (Connect Push catalog now + cart support_count)

- **Push catalog now** - Tools ? Connect can fork a full catalog push in
  the background so a freshly paired store recovers from an empty
  Seekmodo index without SSH/CLI. Set Mode to Active (or Learning) on
  admin.seekmodo.com, click Refresh snapshot, then Push catalog now.
  Watch `logs/numinix_seekmodo_indexer.log`.
- **Cart cascade** - multi-line carts rank recommendations by
  `support_count` (anchors returning each doc), then score and source
  priority; hard cap 10 anchors per request (`meta.anchor_cap`).
  `rejectColdStartSources` on also_bought for cart and PDP bought.
## v1.3.30 - 2026-07-24 (PDP/cart recommendation cascades)


- **PDP/cart cascades** GÇö `pdp-cascade` and `cart` placements compose
  `also_bought` / `related` / `also_viewed` / `bundle.suggest` with
  cross-section de-dupe and in-cart excludes (AKS
  `RecommendationsAdapter` parity). Observer injects one cascade
  container on product_info and shopping_cart; JS renders multi-section
  strips. See `docs/connectors/recommendations-pdp-cart.md` on seekmodo.

## v1.3.29 - 2026-07-22 (suggest observer docblock parse fix)

- **SuggestObserver parse error** - `suggestLabels()` docblock used
  `languages/*/extra_definitions` inside a `/**` comment, which closed
  the comment early and caused
  `Parse error: unexpected identifier "extra_definitions"` on line 1453
  (storefront HTTP 500). Path now uses `languages/{lang}/extra_definitions`.

## v1.3.28 - 2026-07-20 (delta indexer CLI parse fix)

- **Delta indexer parse error** GÇö `numinix_seekmodo_index_delta.php`
  file-header cron example used `*/15` inside a `/**` docblock, which
  prematurely closed the comment and caused
  `Parse error: syntax error, unexpected token "*"` on line 10.
  Example now uses an equivalent `0,15,30,45` minute list.

## v1.3.27 - 2026-07-19 (multi-language packs EN/DE/ES/FR)

- **Language files** GÇö ships english / german / deutsch / spanish /
  french packs for admin Tools menu labels and suggest dropdown
  chrome. Active language is loaded from the plugin tree at runtime
  (works on Zen Cart 1.5.7 file-only installs). Suggest widget
  receives translated labels automatically from the shopper's
  session language.

## v1.3.26 - 2026-07-15 (exact suggest redirect auto-nav)

- **Exact category / keyword redirects** GÇö vendored
  `@seekmodo/web-components` v0.3.14 restores client auto-nav on
  gateway top-level `redirect` (exact + unambiguous only). Fixes
  regression after the v1.3.25 thumb deploy.

## v1.3.25 - 2026-07-15 (suggest thumb paint race)

- **Suggest thumbnails** +óGé¼GÇ¥ vendored `@seekmodo/web-components` v0.3.13
  paints gateway `image_url` eagerly (no empty placeholders while
  `img-ver` hydration races). Emits `seekmodo-suggest:rendered` after
  DOM paint; observer upgrades to 240px from that event + double-rAF.
  Fixes grey boxes after `pin` +óGÇáGÇÖ `pint` / clear+retype.

## v1.3.24 - 2026-07-15 (1.5.7 shim bootstrap)

- **Catalog-root shim self-bootstrap** - pair_callback / suggest / click / recommend / forget-me / CLI push scripts load `init_numinix_seekmodo.php` from `zc_plugins/Seekmodo/v*` when Plugin Manager auto_loaders did not merge (fixes `Pairing` class not found and `connector_unavailable` on Zen Cart 1.5.7 file-only installs).
- **Installer shim deploy** - Install/Upgrade copies the eight flat `numinix_seekmodo_*.php` files next to catalog `index.php`.

## v1.3.23 - 2026-07-12 (fleet-health SERP click tracking)

- **Typed product-info notifiers** - listen for `NOTIFY_HEADER_START_SERVICE_PRODUCT_INFO` (and download/document/music/free-shipping variants) so SERP?PDP click mirroring works on Numinix `serviceproductinfoBody` SEO URLs (www.numinix.ca).
- Retains the v1.3.22 SEO slug `pidFromHref` in the SERP sendBeacon (`/product-name-902`).
## v1.3.22 +â-ó+óGÇÜ-¼" 2026-07-09 (1.5.7 install docs + slug regex fix)

- **Install docs** +â-ó+óGÇÜ-¼" `docs/INSTALL.md` +âGÇÜ+é-º2a covers Zen Cart 1.5.7, subdirectory catalogs, file-only/rsync installs, catalog-root shim deployment, and pair-callback verification.
- **Slug URL fix** +â-ó+óGÇÜ-¼" `NuminixSeekmodoObserver::productsIdFromRequest()` no longer fatals on SEO product URLs.

## v1.3.20 +â-ó+óGÇÜ-¼" 2026-07-08 (view-all SERP redirect parity)

- **View-all SERP parity** +â-ó+óGÇÜ-¼" `seekmodo_skip_category_redirect=1` now forwards `skip_merchandising_redirect=true` to the gateway so suggest "View all N results" matches the ranked SERP for keyword-redirect terms (KIP `pint`).

## v1.3.19 +â-ó+óGÇÜ-¼" 2026-07-07 (Zen Cart 1.5.7 admin + fleet head)

- **1.5.7 Tools menu fix** +â-ó+óGÇÜ-¼" self-healing admin page registration via `zen_register_admin_page()` (singular) on ZC 1.5.7; earlier releases only called the 1.5.8+ plural API.
- **`extra_configures` bootstrap** +â-ó+óGÇÜ-¼" Connect to Seekmodo + Seekmodo Updates appear after file-only installs without Plugin Manager +â-ó+óGé¼-á' Install.
- **`zcVersions`** +â-ó+óGÇÜ-¼" manifest now includes `v157` for official 1.5.7 compatibility.
- **Fleet head** +â-ó+óGÇÜ-¼" includes KIP v1.3.17+â-ó+óGÇÜ-¼"v1.3.18 suggest/SERP live-stock parity.

## v1.3.17 +â-ó+óGÇÜ-¼" 2026-07-07 (Zen Cart 1.5.7 admin + manifest)

- **1.5.7 Tools menu fix** +â-ó+óGÇÜ-¼" self-healing admin page registration via `zen_register_admin_page()` (singular) on ZC 1.5.7; earlier releases only called the 1.5.8+ plural API.
- **`zcVersions`** +â-ó+óGÇÜ-¼" manifest now includes `v157` for official 1.5.7 compatibility.

## v1.3.13 +â-ó+óGÇÜ-¼" 2026-07-05 (occasion + sales index fields)

- **Occasion metadata** +â-ó+óGÇÜ-¼" `occasion_tags` and `occasion_peak_month` on catalog docs (UK gift-store lexicon from title, description, category breadcrumbs).
- **Sales signal** +â-ó+óGÇÜ-¼" `units_sold_lifetime` from `products.products_ordered` for gateway popularity percentile and LTR features.
- **Push catalog** +â-ó+óGÇÜ-¼" `numinix_seekmodo_push_catalog.php` reuses `numinix_seekmodo_catalog_doc_from_row()` for parity with delta indexing.

## v1.3.9 +â-ó+óGÇÜ-¼" 2026-07-03 (suggest tab-switch thumbnail fix)

- **Suggest tab-switch thumbnails** +â-ó+óGÇÜ-¼" vendored `@seekmodo/web-components` v0.3.7
  with eager product thumbs, forced recovery on tab return, and
  `seekmodo-suggest:tab-visible` event; `NuminixSeekmodoSuggestObserver`
  force-repaints hydrated thumbs when `img.src` already matches (Chrome/Windows).
- **Keyword merchandising redirects** +â-ó+óGÇÜ-¼" server 302 via
  `numinix_seekmodo_redirect_lib.php` before auto category redirect.

## v1.3.8 +â-ó+óGÇÜ-¼" 2026-07-03 (suggest tab-switch thumbnail fix)

- **Suggest tab-switch thumbnails** +â-ó+óGÇÜ-¼" vendored `@seekmodo/web-components` v0.3.3
  loads product thumbnails eagerly and reloads any stalled images when the
  browser tab becomes visible again, fixing blank gray thumb slots after
  switching away and back while the suggest dropdown stays open.

## v1.3.7 +â-ó+óGÇÜ-¼" 2026-07-03 (suggest high-DPI thumbnail hydration)

- **Suggest image quality** +â-ó+óGÇÜ-¼" hydrates all product thumbnails at 240px via
  `zen_get_products_image()` (Image Handler / Numinix optimizer) and replaces
  low-res gateway `image_url` values that looked pixelated in split-rail grids.

## v1.3.6 +â-ó+óGÇÜ-¼" 2026-07-03 (suggest session-currency prices)

- **Suggest price currency** +â-ó+óGÇÜ-¼" vendored web-components bundle resolves
  `meta.region.currency` instead of defaulting to USD; connector stamps
  `currency` on indexed docs and hydrates session-aware display prices via
  `seekmodo_action=prices` on the suggest shim (multicurrency storefronts).

## v1.3.5 +â-ó+óGÇÜ-¼" 2026-07-02 (suggest thumbnail hydration + ZC route fix)

- **Suggest product thumbnails** +â-ó+óGÇÜ-¼" `<seekmodo-suggest>` fetches gateway
  `/v1/suggest` in-browser; when indexed docs lack `image_url`, the
  observer hydrates empty thumb slots via
  `numinix_seekmodo_suggest.php?seekmodo_action=images&ids=+â-ó+óGÇÜ-¼+é-ª` (batch
  lookup from `zen_get_products_image()`).
- **Zen Cart cart-handler collision fix** +â-ó+óGÇÜ-¼" shim routes use
  `seekmodo_action=` instead of bare `action=` so `init_cart_handler.php`
  does not 302 to `cookie_usage` or run cart actions before the shim
  handler (regression on any storefront with `DISPLAY_CART`).
- **Optimized thumb URLs** +â-ó+óGÇÜ-¼" `numinix_seekmodo_catalog_doc_image_url()`
  no longer prefixes `cache/optimized_images/` paths with `DIR_WS_IMAGES`.
- **Catalog-root shim sync tool** +â-ó+óGÇÜ-¼" `tools/sync_catalog_shims.php` copies
  the five HTTP shims from the active plugin version to the catalog root
  after deploy (required because `zc_plugins/.htaccess` blocks direct PHP
  access under the plugin tree).
- **SERP listing SQL helper** +â-ó+óGÇÜ-¼" `numinix_seekmodo_build_listing_sql()`
  preserves `products_image` in enforce-mode SERP swaps (v1.3.4 carry-over).

## v1.3.4 +â-ó+óGÇÜ-¼" 2026-06-27 (view-all SERP route + suggest vehicle filter)

- **View-all SERP route detection** +â-ó+óGÇÜ-¼" suggest `view_all_href` now picks
  `search_result` when the storefront ships that page, otherwise falls back to
  `advanced_search_result` with `search_in_description=1`. Fixes legacy forks
  (KIP-style) where linking to a missing `search_result` 301'd to the homepage.
- **Suggest vehicle filter sync** +â-ó+óGÇÜ-¼" autoboot stamps garage/YMM fitment context on
  the suggest web component and refreshes the vendored bundle so gateway queries
  and view-all URLs carry active vehicle filters.

## v1.3.3 +â-ó+óGÇÜ-¼" 2026-06-27 (purchase telemetry zero-arg notify fallback)

- **Purchase observer fallback** +â-ó+óGÇÜ-¼" when
  `NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS` fires with no
  notifier args (PayPal saved-card recurring on Numinix forks), the observer
  reads line items from the global `$order` and session order id so purchase
  events are not silently dropped.

## v1.3.2 +â-ó+óGÇÜ-¼" 2026-06-27 (add-to-cart telemetry on fork cart paths)

- **Add-to-cart observer fix** +â-ó+óGÇÜ-¼" `NOTIFY_CART_ADD_CART_END` now reads the
  products_id and qty the cart notifier passes (same bug class as v1.2.7
  purchase telemetry). Numinix forks that add via `?pid=`, multi-add POST
  arrays, or AJAX wallet paths no longer silently drop add_to_cart events.

## v1.2.9 +â-ó+óGÇÜ-¼" 2026-06-24 (search/click session_id parity)

- **PHP session first + stashed search session** +â-ó+óGÇÜ-¼" gateway search, SERP clicks,
  and checkout purchases now share the same `session_id` within a visit so
  session-aware linkage and search-attributed revenue rollups work end-to-end.

## v1.2.8 +â-ó+óGÇÜ-¼" 2026-06-24 (search-click session attribution)

- **Session id parity** +â-ó+óGÇÜ-¼" click, impression, add-to-cart, and purchase events
  now share the same `session_id` resolution as gateway search calls, fixing
  session-aware click linkage and search-attributed revenue rollups when the
  click-log cookie was not yet set.

## v1.2.7 +â-ó+óGÇÜ-¼" 2026-06-24 (purchase telemetry on forked checkout)

- **Purchase observer fix** +â-ó+óGÇÜ-¼" checkout purchase mirroring now handles Redline /
  Numinix fork notifier arity (single `$order` notify) and cart-style product
  `id` keys, restoring purchase + revenue analytics on enforce storefronts.

## v1.2.5 +â-ó+óGÇÜ-¼" 2026-06-23 (mobile split-rail slider fix)

- **Suggest bundle:** Mobile split-rail draggable divider works again +â-ó+óGÇÜ-¼" the
  `7.5rem` rail cap is scoped to the static stack only; resize mode uses flex
  growth so both keyword and product panels expand/contract when dragging.

## v1.2.4 +â-ó+óGÇÜ-¼" 2026-06-23 (catalog orphan prune + suggest bundle refresh)

- **Catalog orphan prune:** After a successful full push,
  `numinix_seekmodo_push_catalog.php` calls gateway `catalog.prune` with
  the run-start cutoff so hard-deleted SKUs drop out of suggest/typeahead
  without manual Typesense ops. Adds pending-delete queue helpers for
  near-real-time tombstone flushes between full runs.
- **Suggest bundle (@seekmodo/web-components v0.3.0):** Split-rail mobile
  layout with draggable divider (default ~28% keyword rail, scroll for
  overflow). Native `title`/`alt` for product names; rAF-throttled
  dropdown anchor; `bundleSrc()` filemtime cache-bust for Cloudflare.

## v1.2.3 +â-ó+óGÇÜ-¼" 2026-06-16 (typeahead search_event_id + SEO SERP clicks)

- **Typeahead LTR linkage:** `/v1/suggest` `meta.search_event_id` threads
  through product-row click beacons (`surface=typeahead`).
- **SERP click attribution:** Product-info clicks resolve `products_id`
  from SEO slug URLs and referer `search_query`/`q`.

## v1.2.2 +â-ó+óGÇÜ-¼" 2026-06-23 (SERP click beacon for SEO product URLs)

- **SERP click beacon:** Recognises Numinix-style SEO slugs
  (`/product-name-921`) in addition to `?products_id=` links. Stamps
  rank from the session position-map and tags `surface=results` when
  the clicked SKU is in the Seekmodo swap set.

## v1.2.1 +â-ó+óGÇÜ-¼" 2026-06-22 (typeahead product-row click attribution)

- **Suggest click beacon:** `<seekmodo-suggest>` product-row clicks now
  fire a `sendBeacon` to `numinix_seekmodo_click.php` with
  `surface=typeahead` before navigation +â-ó+óGÇÜ-¼" parity with WordPress
  connector v0.8.2. Fixes silent LTR click gaps when shoppers pick a
  product directly from the dropdown instead of the full SERP.

## v1.1.7 +â-ó+óGÇÜ-¼" 2026-06-20 (CORS-block UX + purchasable indexing)

- **CORS-block UX:** When gateway script loads or suggest fetches are
  blocked by the browser, storefronts show an inline notice where the
  dropdown would appear. Ships `seekmodo_cors_notice.js` before the
  suggest bundle, adds script `onerror` handling, and listens for
  `seekmodo-suggest:cors-blocked`.
- Refreshes the vendored `@seekmodo/web-components` suggest bundle to
  v0.2.2.
- **Purchasable indexing:** `numinix_seekmodo_push_catalog.php` indexes
  `purchasable` alongside `in_stock` (backorder-eligible OOS stays
  purchasable; discontinued / call-for-price SKUs do not).

## v1.1.6 +â-ó+óGÇÜ-¼" 2026-06-20 (Enhanced Native layer)

- **Enhanced Native** +â-ó+óGÇÜ-¼" connector-owned multi-field SQL search, popularity
  ranking, and local typeahead when the gateway is off or unavailable.
- **Gate split** +â-ó+óGÇÜ-¼" `numinix_seekmodo_gateway_enabled()` vs
  `numinix_seekmodo_enhanced_native_enabled()` so unpaired installs still
  get improved search.
- **Hotfix (2026-06-21)** +â-ó+óGÇÜ-¼" Enhanced Native `ORDER BY` probes for
  `products_viewed` on `products_description` (Numinix) or `products` (core
  ZC) instead of assuming `p.products_viewed` exists.

## v1.1.3 +â-ó+óGÇÜ-¼" 2026-06-17 (in-plugin Update test release)

- Test release to verify the Connect page **Update** button and signed apply path on git-enabled tenants (numinix.com). No functional connector changes beyond version bump.

## v1.1.2 +â-ó+óGÇÜ-¼" 2026-06-17 (Connect Update + git auto-sync)

- **Connect page Update button** when a newer signed release is published (same apply path as Seekmodo Updates).
- **`GitSyncTrigger`** runs `cron/sync-to-git.sh` immediately after a successful in-plugin apply on git-enabled hosts; admin UI surfaces sync status. Branch propagation cherry-picks are documented in `zencart_git`.

## v1.1.1 +â-ó+óGÇÜ-¼" 2026-06-15 (suggest dropdown widens to 480 px default)

### v1.1.1 fix-pack #3 +â-ó+óGÇÜ-¼" 2026-06-15 (category rows -> resolver redirect)

- **Categories block now leads shoppers to category landing pages.**
  The gateway's per-doc breadcrumb walk landed earlier today, so the
  `<seekmodo-suggest>` dropdown now actually shows a Categories
  section on Zen Cart tenants (it had been silently empty before
  because the facet-only path needed `facet=true` on the breadcrumb
  field). When a shopper clicks a category row WITHOUT an explicit
  `row.url`, the inline click handler now passes the leaf name as
  the search keyword WITHOUT the `seekmodo_skip_category_redirect`
  marker -- so the connector's `onAdvancedSearchStart` resolver
  matches the leaf at score 1.00 and 302's to the matching category
  landing page (Klevu / Algolia parity). Keyword-style rows
  (`recent`, `trending`, `keywords`, `did_you_mean`) keep the
  v1.1.1 fix-pack #2 behaviour: skip the resolver and render the
  full SERP. Product rows without a url keep the defensive
  skip-marker so they don't get mis-routed to a category subtree.



- **`<seekmodo-suggest>` bundle refresh.** Vendors
  `@seekmodo/web-components@0.2.1` into
  `zc_plugins/Seekmodo/v1.1.1/catalog/includes/templates/template_default/jscript/seekmodo_suggest.bundle.js`.
  The bundle's default `anchor-min-width` raises from 320 +â-ó+óGé¼-á' 480 px so
  catalog-grade product names (`Handy Standard SBC990 Snowmobile
  Lift`, `Redline TR1500 Trailer`) stop truncating at ~15 chars in
  the typeahead dropdown on storefronts where the bound search input
  is the typical ~280 px width.
- **Mobile-safe viewport clamp.** A new runtime clamp caps the
  rendered dropdown width at
  `viewport.right - input.left - 8 px gutter`, so a 360 px mobile
  viewport with a 280 px input at `left=16` renders the dropdown at
  336 px instead of overflowing to 480 px and painting a horizontal
  scrollbar.
- **No PHP / behaviour change.** The vendored SDK pin
  (`numinix/seekmodo-connector ^0.2`) stays at the v1.1.0 version
  and every other catalog-side file is byte-identical to v1.1.0.
- **Plugin Manager swap is non-destructive.** The v1.1.0 row in
  Admin +â-ó+óGé¼-á' Plugin Manager remains installed; the operator picks
  v1.1.1 from the dropdown and clicks `Update`. Persistent settings
  (mode, indexer schedule, tenant ID, paired-gateway URL) carry over
  unchanged.

## v1.1.0 +â-ó+óGÇÜ-¼" 2026-06-14 (PHP SDK + connector migration, phase 3)

- **Internal refactor +â-ó+óGÇÜ-¼" shared SDK extraction.** The shared transport
  / breaker / mode-FSM / pairing / events code lifted out into a new
  Composer package, `numinix/seekmodo-connector` (PSR-4 root
  `Numinix\SeekmodoSdk\`), and is now vendored into the plugin tree
  at build time by `tools/build_release.py`. Same shared code now
  powers the WordPress and AKS connectors too, so a single bug fix
  lands everywhere on the next release.
- **Runtime is unchanged for the storefront.** All v1.0.22 procedural
  swap-points (`numinix_seekmodo_run_search`,
  `numinix_seekmodo_run_typeahead`, `numinix_seekmodo_run_bulk_upsert`,
  `numinix_seekmodo_mirror_*`) work exactly as before; the connector's
  own `Numinix\Seekmodo\Client` / `RemoteConfig` / `Pairing` /
  `AutoPromoter` classes are intentionally still present so the Zen
  Cart-flavoured config readers + option-store writes don't have to
  move in this release. See [MIGRATION.md](MIGRATION.md) for the
  full back-out path.
- **New plugin autoloader prefix.** `init_numinix_seekmodo.php` now
  registers a second PSR-4 prefix (`Numinix\SeekmodoSdk\` +â-ó+óGé¼-á'
  `catalog/includes/library/Numinix/SeekmodoSdk/`) so the vendored
  SDK is reachable without touching composer at runtime.
- **No runtime composer dependency.** The plugin zip still installs
  cleanly on a vanilla cPanel Zen Cart host +â-ó+óGÇÜ-¼" composer is only used
  by `tools/build_release.py` on the operator's workstation.

## v1.0.22 +â-ó+óGÇÜ-¼" 2026-06-14 (in-place refresh #6 +â-ó+óGÇÜ-¼" CSP drop-in template)

- **Storefronts with a strict Content-Security-Policy need to allow
  `mcp.seekmodo.com`** or the `<seekmodo-suggest>` widget mints a
  browser token fine (via the same-origin shim) but every follow-up
  POST to `/v1/suggest` is blocked by the browser before it leaves
  the page +â-ó+óGÇÜ-¼" the SDK surfaces the block as
  `[seekmodo-suggest] fetch failed Seekmodo network failure: Failed
  to fetch` and the dropdown's `current` envelope stays null, which
  the shopper experiences as "no suggestions at all".

  Reproduced live on `www.numinix.com` and `www.numinix.ca` (both
  carry a hand-built Numinix CSP via `includes/csp_policy_config.php`
  + `includes/extra_csp_policies/`). Stores without a CSP header
  (e.g. `redlinestands.com/catalog/`) are unaffected and need no
  change.

  Fix shipped: drop a single file at
  `includes/extra_csp_policies/csp_seekmodo.php` that appends
  `mcp.seekmodo.com` to `script-src` and `connect-src`, plus the
  `*.seekmodo.com` wildcard on `connect-src` to cover any future
  regional shards. Reference template lives next to the connector
  at `zc_plugins/Seekmodo/v1.0.22/INSTALL/csp_seekmodo.php`;
  operators on Numinix-style CSP storefronts should copy it into
  the storefront's `includes/extra_csp_policies/` (Numinix.com and
  Numinix.ca were patched by hand on 2026-06-14). No DB schema or
  observer change.

## v1.0.22 +â-ó+óGÇÜ-¼" 2026-06-14 (in-place refresh #5 +â-ó+óGÇÜ-¼" browser-token POST refresh)

- **Suggest dropdown now refreshes its JWT cleanly under the new
  web-component SDK.** The `<seekmodo-suggest>` bundle's
  `SeekmodoClient` POSTs the `seekmodo:refresh` URL on every keystroke
  whose cached token is within 10s of expiry. Our refresh shim
  (`numinix_seekmodo_suggest.php?action=browser-token`) used to accept
  GET fine, but the SDK's POST hit Zen Cart's
  `init_includes/init_sanitize.php` CSRF gate -- pulled in by
  `application_top.php` -- and 302-redirected to `/time-out` before
  the route handler could run. The SDK followed the redirect, found
  /time-out doesn't return JSON, and surfaced the failure as
  `seekmodo:refresh route returned HTTP 404`. The user-visible
  symptom on `numinix.com`, `numinix.ca`, and any other ZC tenant on
  v1.0.22 was that *no suggestions appeared at all* -- the dropdown's
  shadow root stayed empty because `current` never advanced from
  null.
- Fix is a 7-line early guard in the shim: when the request is a
  POST whose `?action=` is `browser-token`, downgrade
  `$_SERVER['REQUEST_METHOD']` to `GET` and clear `$_POST` *before*
  requiring `application_top.php`. The shim never reads the body
  anyway (the action is a `$_GET` lookup) so the downgrade is
  semantically transparent for this route. ZC's CSRF gate then sees
  a GET and skips its token check, the route handler runs, and the
  SDK gets the `{ token, expires_at, session_id }` envelope it
  needed.
- This is the **fifth in-place refresh of v1.0.22**. We deliberately
  keep bumping fix-packs rather than rolling the version because
  zen-cart admins install plugin updates by hand and a fix-pack ships
  as "copy the same v1.0.22 zip on top, no DB migration", whereas a
  version bump triggers their "install plugin" workflow which is
  heavier. Tenant repos (Redline Stands, Numinix.com, Numinix.ca)
  pick this up by syncing the file in their
  `catalog/zc_plugins/Seekmodo/v1.0.22/catalog/` tree.

## v1.0.22 +â-ó+óGÇÜ-¼" 2026-06-14 (in-place refresh #4 +â-ó+óGÇÜ-¼" row-click navigation)

- **Suggest dropdown clicks now navigate.** The
  `<seekmodo-suggest>` web component is intentionally inert on click:
  it emits a `seekmodo-suggest:row-click` CustomEvent
  (`composed: true`, bubbles to `document`) and leaves the connector
  to decide where to send the shopper. The v1.0.22 universal-suggest
  rollout wired the autoboot script that *attaches* the element but
  forgot the listener that *navigates* on the event, so every click
  on a product row felt completely dead +â-ó+óGÇÜ-¼" visually the row
  highlighted, the input briefly stole focus back, then nothing
  happened. (Reported on `redlinestands.com/catalog/`,
  `poco-marine.com`, `numinix.com`, and `numinix.ca`.)
  - `NuminixSeekmodoSuggestObserver::autobootScript()` now appends a
    `document.addEventListener('seekmodo-suggest:row-click', +â-ó+óGÇÜ-¼+é-ª)`
    handler inside the same IIFE so it has the `CFG` view-all
    template in scope.
  - Behaviour: products / categories with `row.url` navigate to
    that URL. Keyword-style blocks (`recent`, `trending`,
    `keywords`, `did_you_mean`) and products / categories that
    happen to lack `row.url` substitute the row's keyword (or name)
    into `CFG.view_all_href` and navigate to the SERP.
  - Pure additive change to the inline autoboot template +â-ó+óGÇÜ-¼" no other
    files touched, no plugin schema or DB change.

## v1.0.22 +â-ó+óGÇÜ-¼" 2026-06-14 (in-place refresh +â-ó+óGÇÜ-¼" index `image_url`)

- **Catalog pusher now indexes product thumbnails.**
  `numinix_seekmodo_push_catalog.php` previously emitted documents with
  `id / name / model / sku / description / brand / category_id /
  p_type / category_breadcrumbs / price / in_stock / url` +â-ó+óGÇÜ-¼" no image
  reference. The `<seekmodo-suggest>` bundle's product-row template
  reads `o.image_url ?? o.image` and renders an empty
  `<div class="thumb">` placeholder when neither is present, which is
  exactly the symptom shoppers saw on `redlinestands.com/catalog/`
  after the v1.0.22 universal-suggest rollout: rich layout, but every
  product row had a blank square where the thumbnail belongs.
  - The SELECT now pulls `p.products_image`, and a new
    `_push_image_url($raw)` helper composes the absolute URL using
    the standard Zen Cart catalog base
    (`HTTPS_SERVER + DIR_WS_HTTPS_CATALOG` when SSL is on,
    `HTTP_SERVER + DIR_WS_CATALOG` otherwise) plus `DIR_WS_IMAGES`.
  - Already-absolute `https://+â-ó+óGÇÜ-¼+é-ª` values in `products_image` (a few
    legacy storefronts pre-bake CDN URLs there) pass through
    unchanged.
  - Empty / missing image rows omit the `image_url` field so the
    Typesense doc stays compact; the bundle's `??` fallback still
    yields the empty-thumbnail placeholder.
- After deploying this file, operators must re-run
  `numinix_seekmodo_push_catalog.php` once per paired tenant to
  populate `image_url` on existing Typesense documents +â-ó+óGÇÜ-¼" the connector
  upserts whole docs per batch, so the next normal cron pass picks
  up the new field automatically. The fix lands as an in-place
  refresh of `v1.0.22` (no plugin schema or behaviour change beyond
  the cron payload shape).

## v1.0.21 +â-ó+óGÇÜ-¼" 2026-06-13 (in-place refresh #2, signing-key rotation)

- **Release-signing key rotation to `seekmodo-2026-06-r2`.** The
  original `seekmodo-2026-06` ed25519 private key was unrecoverable
  from accessible storage (per the JWKS rotation notes at
  `https://seekmodo.com/.well-known/release-signing-keys.json`), so
  Zen Cart releases now mint under the same `seekmodo-2026-06-r2`
  keypair the WordPress connector adopted on 2026-06-10. Updates:
  - `tools/build_release.py` declares `_RELEASE_SIGNING_KID =
    "seekmodo-2026-06-r2"` (the on-disk file at
    `/etc/numinix/release-signing-seekmodo-2026-06-r2.key` is the
    only release-signing key present on seek-api01).
  - `zc_plugins/Seekmodo/v1.0.21/admin/release-signing.pub` is
    re-vendored with the r2 public key (`x =
    ozNs5QQUhP6YNjE_KffhJqYtDQL8m2mHzWNivlhgoPA`).
  - The marketing-site JWKS now lists `seekmodo-2026-06-r2.signs`
    as `["wordpress", "zen_cart"]` and demotes `seekmodo-2026-06`
    to `accepted` with empty `signs` (kept in the JWKS for audit
    of v1.0.18..v1.0.20 artefacts).
  - **Upgrade friction:** sites on v1.0.18..v1.0.20 with auto-
    update enabled cannot apply v1.0.21 through the in-plugin
    verifier; `UpdateClient::verifySignature()` will refuse with
    "manifest sig_kid (seekmodo-2026-06-r2) != vendored kid
    (seekmodo-2026-06); manual upgrade required to rotate keys".
    Operators reinstall via Zen Cart's Plugin Manager and the
    next auto-update (v1.0.21 -> v1.0.22+) will succeed because
    v1.0.21 vendors the r2 trust root.

## v1.0.21 +â-ó+óGÇÜ-¼" 2026-06-12 (in-place refresh, SM-606 follow-up)

- **Self-anchoring suggest bundle.** Refreshed the pinned
  `seekmodo_suggest.bundle.js` to the build that ships SM-606's
  self-anchoring + legacy-widget suppression behavior
  (numinix/seekmodo commit e5f6090). The dropdown now pins itself
  as a `position:fixed` overlay anchored to its bound input, tracks
  scroll/resize/orientationchange/visualViewport/focusin, and
  exposes `anchor`, `anchor-offset`, `anchor-min-width`, and
  `suppress-legacy` attributes. Bundle is 8.4 KB gzipped (up ~1.15
  KB; still under the 12 KB SDK budget).
- **Default `suppress-legacy`.** The autoboot config now stamps
  `suppress-legacy="jquery-ui,seekmodo-typeahead"` on every
  `<seekmodo-suggest>` it spawns. On first focus of the bound
  input, the widget calls `$(input).autocomplete('destroy')` (if
  jQuery UI is bound) and hides any sibling
  `<seekmodo-typeahead input="...">` element, so the rich dropdown
  doesn't get shadowed by either legacy widget. Unrelated forms
  (KIP / Numinix dropdown-cart suggest, wishlist suggest) keep
  their legacy widget because we only target inputs by id.

## v1.0.21 +â-ó+óGÇÜ-¼" 2026-06-12

- **SM-606 Universal Suggest Widget.** Storefront typeahead now ships
  the new `<seekmodo-suggest>` web component (the same custom element
  the WordPress / BigCommerce / AKS connectors enqueue) and renders
  the rich `/v1/suggest` envelope +â-ó+óGÇÜ-¼" recent + did-you-mean + keywords
  + trending + products + categories + "View all N results" CTA +â-ó+óGÇÜ-¼"
  all from one server round-trip. The legacy v1.0.14-era three-section
  vanilla-JS dropdown is preserved on disk at
  `seekmodo_typeahead.legacy.js` and enabled via the
  `NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY` constant (default false) for
  one major-version cycle so a site with bespoke CSS tied to the old
  dropdown markup can defer the swap.

  Wiring:

  - New `NuminixSeekmodoSuggestObserver` (`NOTIFY_HTML_HEAD_END`)
    emits `<meta name="seekmodo:tenant|gateway|refresh|token">` so
    the bundled SDK can resolve config on first access, then injects
    `<script src=".../seekmodo_suggest.bundle.js" defer></script>`
    plus a tiny inline autoboot that walks the same
    `input[name="keyword"]`, `input#keyword`,
    `input[data-seekmodo-typeahead]` selectors the legacy JS
    auto-attached to.
  - `catalog/numinix_seekmodo_suggest.php` keeps its existing
    JSON-suggest route AND adds a new `?action=browser-token` route
    that returns `{token, expires_at, session_id}` so a long-running
    tab can refresh the gateway-direct JWT without a page reload.
  - Browser-token mint is APCu-cached per-tenant (~1 mint / 4 min
    regardless of keystroke volume) +â-ó+óGÇÜ-¼" same posture as the WP
    connector's transient cache.

  KIP's `numinix_seekmodo_suggest.php` catalog-root override (the
  per-token multi-recall blend) is now redundant: WS-2 absorbed the
  same interleave logic into `SuggestTool::loadTypesenseBlocks`, so
  the bespoke shim can be deleted on the next KIP push.

  Operator overrides (all constants):

  - `NUMINIX_SEEKMODO_SUGGEST_ENABLED` (default true)
  - `NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY` (default false)
  - `NUMINIX_SEEKMODO_SUGGEST_BLOCKS` +â-ó+óGÇÜ-¼" CSV of blocks in render
    order; default `recent,did_you_mean,keywords,trending,products,
    categories`.
  - `NUMINIX_SEEKMODO_SUGGEST_VIEW_ALL_HREF` +â-ó+óGÇÜ-¼" URL template for the
    "View all N results" CTA. Default: Zen Cart core SERP URL.

  Bundle size: 22.6 KB raw / 7.25 KB gzip +â-ó+óGÇÜ-¼" under the 12 KB gzip
  plan target.

  Spec: `seekmodo/docs/CONNECTOR_TYPEAHEAD_SPEC.md` Phase E.

## v1.0.20 +â-ó+óGÇÜ-¼" 2026-06-11

- **Typeahead-perf parity with the WordPress connector v0.5.0
  (SM-602 phase B).** The storefront-side typeahead JS now keeps a
  32-entry LRU cache keyed on `(max, normalized q)` so backspacing
  `boats -> boat` and retyping back to `boats` only pays one
  network round trip. Adds a `lastQ` guard so a slow `boa` response
  arriving after the user has moved on to `boats` can't overwrite
  the freshly-rendered dropdown.

  JS-only change +â-ó+óGÇÜ-¼" no PHP, no schema, no gateway-call shape shifts.
  Phase C (browser-token gateway-direct fetch) is queued for
  v1.0.21 because the Zen Cart connector doesn't mint browser
  tokens today; the flat-rows `/v1/typeahead` migration is queued
  behind it.

  Spec: `seekmodo/docs/CONNECTOR_TYPEAHEAD_SPEC.md`.

## v1.0.19 +â-ó+óGÇÜ-¼" 2026-06-11

- **Category landing-page redirect** (search-features-plan Sprint 6
  PR 1) +â-ó+óGÇÜ-¼" Klevu / Algolia parity for navigational-intent queries.
  See `zc_plugins/Seekmodo/v1.0.19/CHANGELOG.md` for the full detail.

## v1.0.18 +â-ó+óGÇÜ-¼" 2026-06-08

- **Stable ed25519 release-signing key (`seekmodo-2026-06`).** The
  prior label `marketing-2026-05` was aspirational +â-ó+óGÇÜ-¼" no production
  build ever wrote a real public key under it. v1.0.13 shipped with
  a literal `PLACEHOLDER_REPLACED_BY_BUILD_RELEASE_PY` string in
  `admin/release-signing.pub`; v1.0.14+â-ó+óGÇÜ-¼"v1.0.17 shipped with a
  per-build *ephemeral* keypair whose private half was generated on
  the build host, used to sign that one zip, then discarded +â-ó+óGÇÜ-¼"
  unverifiable forever from the operator's side. Every release since
  v1.0.7 has therefore carried the `dev-ephemeral` flag that the
  in-plugin UpdateClient refuses outright (i.e. **auto-update has
  been silently broken since v1.0.7**).

  v1.0.18 fixes this end-to-end: the build pipeline reads a real
  ed25519 keypair from `~/.numinix/release-signing-<kid>.key`,
  vendors the matching JWK into `admin/release-signing.pub`, emits
  `sig_kid: seekmodo-2026-06` + `signed_with: file:<path>` in the
  manifest, and (newly) vendors the pubkey **before** building the
  zip so the shipped artifact actually carries the trust root.

  **Operator action required (one-time):** v1.0.17 +â-ó+óGé¼-á' v1.0.18 must be
  a manual upgrade because v1.0.17's vendored pubkey carries
  `kid: dev-ephemeral` and the v1.0.18 manifest entry will carry
  `sig_kid: seekmodo-2026-06`. The in-plugin verifier raises
  "manifest sig_kid (seekmodo-2026-06) != vendored kid
  (dev-ephemeral); manual upgrade required to rotate keys" exactly
  as documented in `UpdateClient`'s rotation contract. From v1.0.18
  forward, the vendored key matches the kid we sign under, so
  v1.0.18 +â-ó+óGé¼-á' v1.0.19+ auto-updates flow normally. See
  `docs/SIGNING_KEYS.md` in `numinix/seekmodo` for the rotation
  runbook and the manual cutover steps for the live fleet.

- **Build-pipeline parity with the WP connector.** The Zen Cart
  build script now accepts the same hex- or base64-encoded 32-byte
  ed25519 seed files as the WordPress one, so the same
  `~/.numinix/release-signing-<kid>.key` on the operator's disk
  serves both pipelines. No more PEM-only assumption.

## v1.0.17 +â-ó+óGÇÜ-¼" 2026-06-08

- **AKS-connector parity port (generic improvements only).** Two
  features lifted from the AKS connector v1.3 (`numinix/aks-seekmodo-connector`,
  2026-06-07) that aren't AKS- or vehicle-specific. Both are
  additive and backwards-compatible +â-ó+óGÇÜ-¼" every existing tenant's
  payload shape is unchanged in the no-trigger case.

  1. **SKU / part-number exact-match boost** (port of AKS
     Sprint 2's `EzNumberBooster`). Shopper queries that look
     like a single-token SKU / part number (alphanumeric +
     dashes/underscores/dots, 2-32 chars by default) now set
     `prioritize_exact_match=true` on the gateway call so the
     exact-SKU product floats to position 0 regardless of
     textual relevance scoring. Multi-word natural-language
     queries are unaffected. Configurable via the new
     `NUMINIX_SEEKMODO_SKU_BOOST_ENABLED` (default `true`) and
     `NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX`. Applies to the
     full-search path AND the legacy `/v1/search`-based typeahead
     fallback.

  2. **Expanded tenant-unavailable graceful degradation** (port
     of AKS v1.3's `Client::classifyByErrorCode()`). The
     storefront has always fallen back to native Zen Cart `LIKE`
     search on a `403 tenant_paused`; v1.0.17 expands the
     recognised lifecycle vocabulary to also cover
     `tenant_not_found`, `tenant_unknown`, `tenant_suspended`,
     `tenant_disabled`, and applies the body peek to **both**
     403 and 404 responses (the gateway emits 404 for
     `tenant_not_found` / `tenant_unknown`, 403 for the rest).
     Behaviourally the fallback to native search is unchanged +â-ó+óGÇÜ-¼"
     `Client::call()` returns `null` on every 4xx exactly as
     before +â-ó+óGÇÜ-¼" but the structured log line now distinguishes
     `tenant_unavailable` (with `fallback_reason =
     tenant_unavailable`) from the generic `caller_error` so
     admin observability can attribute the volume correctly.

  Full per-version detail: [`zc_plugins/Seekmodo/v1.0.17/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.17/CHANGELOG.md).

## v1.0.14 +â-ó+óGÇÜ-¼" 2026-06-04

- **Typeahead routes through the gateway's SuggestTool (Sprint 3 PR 6).**
  v1.0.13 packed every typeahead keystroke into a `/v1/search` payload
  so autocomplete impressions counted against the same metering bucket
  as full-search SERPs. v1.0.14 swaps the default path to the
  gateway's dedicated `SuggestTool` at `/v1/suggest`, which returns
  three result blocks in one round-trip (keywords / products /
  categories), meters the call against the new `searches_suggest`
  display bucket separately from `searches_text`, and short-circuits
  scraper keystroke storms via the same bot-gate the SERP uses.

  Ships a drop-in client-side JS handler (`jscript_seekmodo_typeahead.js`,
  150ms debounce, vanilla DOM, no jQuery dep) plus a tenant-side AJAX
  endpoint (`catalog/numinix_seekmodo_suggest.php`) so unmodified
  storefronts can opt into Seekmodo-driven typeahead without editing
  their own search templates. Sites on a custom template need to copy
  the JS file into their own template's `jscript/` folder +â-ó+óGÇÜ-¼" Zen Cart
  doesn't auto-inherit `jscript_*.js` from `template_default`.

  Operators can roll back to the v1.0.13 `/v1/search` typeahead path
  per-call (`opts.use_search=true`) or globally
  (`NUMINIX_SEEKMODO_TYPEAHEAD_USE_SEARCH=true`) for the cutover
  window. Form-submit behaviour is intentionally unchanged +â-ó+óGÇÜ-¼" the
  SERP still routes through `numinix_seekmodo_run_search()`.

  Full detail in `zc_plugins/Seekmodo/v1.0.14/CHANGELOG.md`.

## v1.0.12 +â-ó+óGÇÜ-¼" 2026-06-02

- **Static `.well-known/mcp.json` writer (Sprint 14 PR 4 follow-up,
  2026-06-02).** v1.0.11's PHP-driven `.well-known/mcp.json`
  interceptor required an `.htaccess` rewrite that doesn't ship in
  stock Zen Cart and isn't reachable at all when the storefront is
  installed in a `/catalog/` subdirectory (the root-level redirect
  catches the URL before PHP sees it). v1.0.12 fixes both cases by
  physically writing a real `.well-known/mcp.json` file (plus a
  defence-in-depth `<Files "mcp.json"> Require all granted </Files>`
  `.htaccess`) to **every viable docroot the connector can
  resolve** +â-ó+óGÇÜ-¼" `DIR_FS_CATALOG`, `$_SERVER['DOCUMENT_ROOT']` when
  distinct, and the parent of `DIR_FS_CATALOG` as a CLI fallback.
  Apache serves the resulting file directly; no rewrite required.
  Triggers: pair callback (immediate on Connect), `RemoteConfig::
  writeThrough` (every snapshot poll), and an APCu-gated
  once-per-hour refresh from the head observer so any already-paired
  storefront self-heals on the next page render.
- Idempotency: the writer reads existing on-disk content and skips
  the write when it matches the canonical payload. Safe to call on
  every storefront request; ~free when nothing has changed.
- Failure posture unchanged from v1.0.11 +â-ó+óGÇÜ-¼" every code path is
  wrapped in try/catch, the writer NEVER throws to its caller, and
  a writer failure does NOT block pairing or 500 a storefront page.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.12/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.12/CHANGELOG.md).

## v1.0.11 +â-ó+óGÇÜ-¼" 2026-06-02

- **Public-MCP (anonymous-tier) discovery for AI agents (Sprint 14 PR 4).**
  Two new discovery surfaces let third-party AI agents (ChatGPT,
  Claude Desktop, Cursor, etc.) find the storefront's product-search
  MCP endpoint at `https://<tenant_id>.mcp.seekmodo.com/mcp` without
  any merchant intervention:

  - **`/.well-known/mcp.json`** +â-ó+óGÇÜ-¼" a small JSON discovery document
    served by a new early-init interceptor
    (`catalog/includes/init_includes/init_numinix_seekmodo_well_known.php`,
    registered at `autoLoadConfig[60]`). Advertises the gateway
    endpoint, the `search` tool, the per-IP / per-(tenant,IP) rate
    limits, and a link to the operator runbook. Requires a one-line
    `.htaccess` rewrite (`RewriteRule ^\.well-known/mcp\.json$ index.php [L,QSA]`)
    on stock Zen Cart docroots; falls through cleanly when missing.
  - **`<link rel="mcp-server">` + `<meta name="mcp-server">`** +â-ó+óGÇÜ-¼"
    injected into every storefront page's `<head>` via a new
    `NOTIFY_HTML_HEAD_END` observer
    (`NuminixSeekmodoMcpDiscoveryObserver`). No web-server config
    required; works on stock Zen Cart 1.5.8 / 2.0 unmodified.

  Both surfaces emit only when the connector is enabled
  (`numinix_seekmodo_enabled()` true +â-ó+óGÇÜ-¼" i.e. paired, mode != off,
  not domain-locked-out) and silently no-op otherwise. Every code
  path is wrapped in `try/catch` +â-ó+óGÇÜ-¼" a discovery failure NEVER 500s a
  storefront page.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.11/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.11/CHANGELOG.md).

## v1.0.7 +â-ó+óGÇÜ-¼" 2026-05-31

- **In-plugin auto-update +â-ó+óGÇÜ-¼" admin "Updates" page (Sprint 4 PR 2).**
  New `admin/numinix_seekmodo_updates.php` (sibling of
  `numinix_seekmodo_connect.php`) pulls
  `https://seekmodo.com/plugins/manifest.json`, compares
  `platforms.zen_cart.latest` against the local `pluginVersion`,
  and surfaces release notes + an **Apply update** button. The
  manifest's ed25519 signature is verified against the public key
  vendored at `admin/release-signing.pub`.
- **Daily update-check cron (Sprint 4 PR 3).** New CLI runner
  `admin/numinix_seekmodo_check_updates.php` runs once a day from
  cron. When a new version is found it writes a sentinel row that
  the admin shell renders as a top-bar one-liner linking to the
  Updates page. `tools/install_redline_connector.py` (seekmodo
  monorepo) prints the cron line alongside the existing indexer
  line.
- **One-click apply + rollback (Sprint 4 PR 4).** The Updates page's
  **Apply update** action downloads the signed zip, re-verifies
  SHA-256 + ed25519, snapshots the live tree to
  `.backup-<oldver>/`, expands the new tree, and runs the new
  version's `ScriptedInstaller` upgrade entry-point. A
  **Roll back to vX.Y.Z** link on the same page swaps directories
  back; the last 3 backups are kept and older ones are pruned.
- **Vendored release-signing public key.** `tools/build_release.py`
  (Sprint 4 PR 1) writes `admin/release-signing.pub` into each
  per-version plugin tree on every release build.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.7/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.7/CHANGELOG.md).

## v1.0.6 +â-ó+óGÇÜ-¼" 2026-05-31

- **Bot-check backend selector (W6c, PROJECT_PLAN.md +âGÇÜ+é-ºP1-14 Phase B).**
  `RemoteConfig::writeThrough()` now mirrors **eight** keys from the
  gateway snapshot (was seven) +â-ó+óGÇÜ-¼" adding `bot_check_backend` +â-ó+óGé¼-á'
  `NUMINIX_BOT_CHECK_BACKEND`. Values are clamped to `legacy` |
  `gateway`; anything else is dropped (the row is left untouched, and
  the bot-check client falls through to its built-in `legacy`
  default). Operators flip the value from `admin.seekmodo.com` once
  Phase B shadow validation completes on a tenant.
- **Vendored bot-check client.**
  `catalog/includes/functions/numinix_bot_check_client.php` now ships
  inside the plugin tree. Reads `NUMINIX_BOT_CHECK_BACKEND` and routes
  classify / nonce.issue / nonce.verify either at
  `bot-check.numinix.com` (legacy) or at the gateway's `BotCheck\*`
  tools (`/v1/bot.classify`, `/v1/nonce.issue`, `/v1/nonce.verify`)
  when set to `gateway`. `if (!function_exists(...))` guards on every
  helper keep the existing tenant-repo copy as the first-loaded
  authoritative one until the connector deploy runs.
- **Installer row.** `ScriptedInstaller` adds
  `NUMINIX_BOT_CHECK_BACKEND` (default `'legacy'`) to the Seekmodo
  Search configuration group.
- **Tests.** New `tests/W6cBackendSelectorTest.php` pins the 8-key
  writeThrough surface, the gateway/legacy switching path inside the
  bot-check client (URL + header scheme + endpoint remap), and the
  malformed-snapshot guard.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.6/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.6/CHANGELOG.md).

## v1.0.5 +â-ó+óGÇÜ-¼" 2026-05-30

- **W6b consumption (default_mode + indexer_schedule).**
  `RemoteConfig::writeThrough()` now mirrors **seven** keys from the
  gateway snapshot (was five) +â-ó+óGÇÜ-¼" adding `default_mode` +â-ó+óGé¼-á'
  `NUMINIX_SEEKMODO_DEFAULT_MODE` and `indexer_schedule` +â-ó+óGé¼-á'
  `NUMINIX_SEEKMODO_INDEXER_SCHEDULE`.
- **Mode resolver fall-through.** `numinix_seekmodo_mode()` consults
  `NUMINIX_SEEKMODO_DEFAULT_MODE` when `MODE` is empty / unset /
  invalid, before defaulting to `'off'`.
- **Indexer cron renderer.** New `tools/render_indexer_cron.php`
  translates the `indexer_schedule` enum into a cron line. Consumed
  by the operator-side `tools/install_redline_connector.py` (in the
  seekmodo monorepo) to populate
  `/etc/cron.d/numinix-seekmodo-<tenant>` on managed-mode installs.
- **Installer rows.** ScriptedInstaller now seeds
  `NUMINIX_SEEKMODO_DEFAULT_MODE=active` and
  `NUMINIX_SEEKMODO_INDEXER_SCHEDULE=daily` as safe defaults.
- **Tests.** New `tests/W6bConsumptionTest.php` pins the 5-key +â-ó+óGé¼-á'
  7-key writeThrough surface plus the four-case mode-resolver
  fall-through behaviour.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.5/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.5/CHANGELOG.md).

## v1.0.4 +â-ó+óGÇÜ-¼" 2026-05-29

- LTR P6 conversion-event helpers
  (`numinix_seekmodo_mirror_add_to_cart`, `numinix_seekmodo_mirror_purchase`).
- Filter-context propagation: structured `filters` map on every
  `/v1/search` so the trainer can group clicks by `(query, filter_hash)`
  without a JSON-extract scan.
- `search_event_id` linkage from search response +â-ó+óGé¼-á' click beacon +â-ó+óGé¼-á'
  trainer's grade joiner.
- New SERP impression beacon helper
  (`numinix_seekmodo_mirror_serp_impression`).
- New `numinix_seekmodo_current_search_event()` accessor used as the
  canonical source-of-truth for the current request's
  `(search_event_id, filter_by, filters, keyword)` tuple.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.4/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.4/CHANGELOG.md).

## v1.0.3 +â-ó+óGÇÜ-¼" 2026-05-28

- Storefront tuning forwarded to gateway (typo / drop / query_by /
  query_by_weights / sort_by). Hot-fix for the
  `keyword=automotive+rotisserie` regression on Redline.
- Generic filter pass-through +â-ó+óGÇÜ-¼" runtime filter-mapping registry
  (`numinix_seekmodo_register_filter_mapping`).
- Local-filter intersection helper for non-indexed filters.
- Type-ahead through the gateway with surface-tagged click mirroring.

Full detail: [`zc_plugins/Seekmodo/v1.0.3/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.3/CHANGELOG.md).

## v1.0.2 +â-ó+óGÇÜ-¼" 2026-05-28

- Forward shopper session / UA / IP to gateway so the bot-check
  classifier runs on `/v1/search`. Closes Seekmodo P0-1 / P0-3.

## v1.0.1 +â-ó+óGÇÜ-¼" 2026-05-28

- Connector now pages through gateway results so Zen Cart's local
  pagination sees every matching product (was capped at 10).
- IPv4 forced + connect timeout relaxed (250-750ms) +â-ó+óGÇÜ-¼" fixes the flaky
  Cloudflare IPv6 path that was tripping the circuit breaker.
- Response normaliser handles both the gateway's nested
  `results.hits[*].document` envelope and the legacy flat shape.

## v1.0.0 +â-ó+óGÇÜ-¼" 2026-05-26

- Initial release. Four swap-points (search, indexer, click beacon,
  type-ahead). Mode-aware (`off` / `shadow` / `enforce`). HMAC-signed
  REST envelope. APCu circuit breaker shared across php-fpm workers.
