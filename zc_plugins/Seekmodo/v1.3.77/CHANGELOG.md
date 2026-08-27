# Seekmodo for Zen Cart v1.3.76

## 2026-08-26 - Typeahead product click PDP fallback (RED-1880)

- **Product rows without `url` navigate to the PDP** — when the
  gateway suggest index omits `url` on a product row, clicking that row
  now substitutes `products_id` into a storefront `product_info_href`
  template instead of falling through to a keyword SERP (Redline
  "upper hose" → REPP70-UHC).

# Seekmodo for Zen Cart v1.3.75

## 2026-08-18 - SERP ES/Typesense total parity

- **Legacy `$es_products_id_2` total stays in sync with Seekmodo** —
  custom product_listing modules that pass
  `['total' => $es_products_id_2['total']]` into splitPageResults no
  longer clobber the gateway count after a Seekmodo rewrite.
- **Omit legacy `NUMINIX_TYPESENSE_QUERY_BY` on gateway SERP** so
  suggest/gateway report the Seekmodo total (avoids Redline-style
  SERP 50 vs suggest 52 divergence).

## 2026-08-18 - seekmodo_nocache write-through refreshes SERP cache

- `?seekmodo_nocache=1` still skips the cache **read** so operators get a
  fresh gateway ranking, but a successful response is now written back
  into the enforce-mode SERP / typeahead cache.
- Previously a nocache refresh wasted the gateway round-trip: View All
  and later SERP pages kept serving the stale TTL entry until it expired
  (~5 minutes). After this change, one nocache hit heals subsequent
  cached View All / pagination / sort toggles for that query.

## 2026-08-17 - categories_id=0 no longer empties gateway SERPs

- Zen Cart advanced search posts `categories_id=0` for "all categories".
  The default filter registry turned that into Typesense
  `category_id:=0`. No products live in category 0, so `/v1/search`
  returned `found=0` and the SERP fell back to Enhanced Native
  (`products_sort_order`) while suggest (no category filter) still
  showed relevance-ranked hits — STRIN "guitar" mismatch.
- Treat `categories_id=0` / `cPath=0` as unbound. Search cache key
  bumped to `sm_search_v4`; empty `products[]` envelopes are no longer
  cached so a zero-hit reply cannot poison later unbound SERPs.

## 2026-08-17 - Broad SERPs no longer OOM on live-stock hydration

- `NOTIFY_SEARCH_RESULTS` re-ranked gateway IDs by calling
  `docs_for_ids()` for every hit. That joins `products_description`
  and builds full catalog docs (HTML strip, categories, breadcrumbs,
  per-SKU stock). STRIN "guitar" (~10k hits) exhausted PHP at 1GB
  inside `query_factory.php` — intermittent HTTP 500s.
- Live-stock partition now loads only `products_id` / quantity / NPF
  flags.

## 2026-08-17 - Unfiltered SERP no longer clamps price to $0

- `NOTIFY_SEARCH_RESULTS` defaulted missing `pfrom`/`pto` to `0`, so
  every search without a price band became Typesense
  `price:>=0 && price:<=0`. Suggest still reported the full catalog
  (`meta.total` ~10k for "guitar"); the SERP listed the few $0 hits.
- Parse missing/blank price params as unbound. Explicit `pfrom=0`
  (first suggest band) still filters `price:>=0`. Search cache key
  bumped to `sm_search_v3` so a stale `$0` envelope cannot replay.

## 2026-08-17 - Suggest thumbs no longer vanish behind x.gif

- `zen_get_products_image(240)` on some templates (STRIN SBM2015)
  returns the spacer pixel `includes/templates/.../images/x.gif`.
  That file HTTP 200s, so the hydrator replaced the working gateway
  photo and `onerror` never restored it — thumbs flashed then
  disappeared.
- Treat template spacers the same as `no_picture.*`: miss, then catalog
  original, else leave the gateway thumb. Existing Image Handler /
  `bmz_cache` / `images/cache` / `.image.WxH` miss behavior is unchanged
  (Cannapot NS-26042, KIP empty `/images`).

## 2026-08-17 - Default suggest is the subscribed split-rail widget

- Installer now writes `NUMINIX_SEEKMODO_SUGGEST_ENABLED=true` and
  `NUMINIX_SEEKMODO_SUGGEST_USE_LEGACY=false`. Missing those rows used
  to fall through to the modern widget, but recovery stamps of
  `USE_LEGACY=true` kept paired stores on the v1.0.20 flat dropdown.
- Plugin Manager install/upgrade resets leftover `true` so the
  storefront matches the Seekmodo default (`<seekmodo-suggest>`
  split-rail bundle). Billing 402/cancelled still forces the
  same-origin legacy path via `shouldPreferLocalSuggest()`.
- Catalog init defines the same defaults when the configuration
  table has not loaded the keys.

## 2026-08-17 - Typeahead prices show HTML tags

- Zen Cart `zen_get_products_display_price()` wraps amounts in
  `<span class="productBasePrice">`. The legacy typeahead JSON is
  rendered with HTML escaping, so STRIN DEV printed the tags as
  literal text next to each product.
- Typeahead / recommend now strip that markup to a plain display
  string (`$28.00`). The legacy JS also strips tags before escape as
  a second line of defense.

## 2026-08-17 - Catalog push QueryCache leak

- v1.3.56 keyset-paginated the product scan so `queryFactory::Execute()`
  no longer buffers the whole catalog in one result. That was not
  enough: Zen Cart's `QueryCache` (and storefront subclasses such as
  `zc_optimizer_query_cache`) still keep every unique `SELECT`
  `mysqli_result` for the whole CLI request. Each keyset page uses a
  new SQL string, and each SKU also ran its own
  `products_to_categories` lookup, so memory grew linearly and STRIN
  DEV OOM'd at 512MB around 4.3k of 12.5k products.
- CLI `push_catalog` / `index_delta` now replace `$queryCache` with a
  no-op, free each page's `mysqli_result`, prefetch category links for
  the page in one `IN()` query, then drop the page array before the
  next keyset fetch. A 12.5k catalog should finish inside a normal
  256–512MB CLI limit.
- `docs_for_ids()` (SERP live-stock) must not call `reset('ALL')`. The
  drain helper is now a no-op unless `$queryCache` is the indexer
  no-op object, so a shared doc builder cannot wipe storefront cache.
- `mysqli_free_result` is likewise indexer-only. Zen Cart QueryCache
  stores the same `mysqli_result`; freeing it from `docs_for_ids()`
  would poison a later `getFromCache()` `mysqli_data_seek`.

## 2026-08-15 - SERP click beacon accepts product slug-{id}?cPath=

- `pidFromHref` no longer returns null on every href that contains
  `cPath` / `categories_id`. That blanket reject (v1.3.59, for Klevu
  category-card poison) also dropped legitimate Zen Cart listing
  links that append `?cPath=` as a breadcrumb on the product URL
  (`/tableau-for-woocommerce-1172?cPath=403_407_462` on
  www.numinix.com). SERP `sendBeacon` then never fired, which is
  the `tracking_clicks_missing` P1 on tenant `jlew-00e070bd`.
- Still rejects `-c-N` category slugs and trailing-slash
  `/slug-{id}/` category rewrites. Product slug `-{id}?` / `-{id}`
  end / `-p-N` / `.html` / `products_id=` still match.

## 2026-08-14 - Daily unpaid-recovery recheck

- `Client::shouldPreferLocalSuggest()` — the single choke point every
  suggest/typeahead surface calls before `RemoteConfig::pull()` ever
  runs — no longer trusts a `trial_expired` / `over_quota` / cancelled
  sticky indefinitely. At most once every 24 hours it force-invalidates
  the cached `tenant.snapshot` and re-pulls it; if the gateway's new
  `billing.status` field reports `active`, it clears both the
  over-quota/trial_expired sticky and the cancelled/tenant-unavailable
  sticky so cloud suggest resumes on its own.
- Fixes a self-perpetuating stuck state: previously, once suggest went
  sticky-local, nothing on that path talked to the gateway again, so a
  merchant who subscribed (or an operator who extended a trial) stayed
  degraded until the sticky's own TTL lapsed or someone manually clicked
  **Refresh snapshot** in the admin. Full search was never affected —
  it always retries the gateway and clears the sticky on the next
  success.
- Requires the gateway's `tenant.snapshot` to expose
  `billing.status` / `billing.trial_ends_at` (2026-08 daily
  unpaid-recovery plan, gateway step). Older gateways simply omit the
  field and the recheck is a no-op, same as today.
- Admin **Connect to Seekmodo → Refresh snapshot** now applies the same
  `billing.status` write-through (`Client::applyBillingSnapshot()`) so
  the documented "click Refresh snapshot for immediate restore" path
  actually clears the sticky instead of only mirroring mode/FSM fields.
- On hosts without APCu (sticky falls back to each shopper's own
  `$_SESSION`), the Refresh snapshot success message no longer claims
  cloud suggest is instantly restored — an admin request can only ever
  clear its own session, not every shopper's. It now says recovery
  will land as sessions refresh (still within the 24h daily recheck).
- `Client::applyBillingSnapshot()` no longer clears a genuine
  `over_quota` sticky just because `billing.status === 'active'` — an
  actively-paying tenant can still be over this period's metered
  quota, and `active` was already true when that 402 landed. It now
  reads the sticky's own stored `code` and only recovers cancelled /
  trial_expired / tenant-unavailable reasons; `over_quota` still only
  clears via its 1-hour TTL or a real successful metered call.
- The browser-side `stamp-cloud-denied` shim (fired by the storefront
  suggest widget after a direct-to-gateway 402) no longer hardcodes
  `code=trial_expired` for every denial. It now validates an optional
  `code` query param against the same allowlist `Client` uses
  internally (`Client::normalizeDenialCode()`), so once the bundled
  web component starts reporting the real reason on its quota-empty
  event, a genuine `over_quota` denial from that path is protected by
  the same guard as the server-side proxy path instead of being
  silently mislabeled and prematurely cleared.

## 2026-08-13 - Suggest price-range rail (Klevu QS parity)

- Vendored `@seekmodo/web-components` 0.3.17: split-rail left column
  caps keywords and categories at 4 each and adds a **Price range**
  section with clearer separators. Clicking a band re-fetches suggest
  products/categories with Typesense `filter_by` on `price`; click
  again to clear.
- View all substitutes `{price_from}` / `{price_to}` into the SERP URL
  as vanilla Zen Cart `pfrom` / `pto` (core advanced search params).
- SERP enforce search ANDs those bounds into Typesense `filter_by`
  (`price:>=` / `price:<=`), including `pfrom=0`.
- Row-click handler ignores `price_range` so the widget can filter
  in-dropdown without navigating.

## Prior (v1.3.63)

- Extract `numinix_seekmodo_is_no_picture_url()` and
  `numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image()` so
  suggest thumb resolution can be smoke-tested without a full Zen Cart
  bootstrap. Behavior matches v1.3.62 (never promote `no_picture.*` over
  catalog originals; return empty when only the placeholder exists).

## Prior (v1.3.62)

- Treat `no_picture.*` from `zen_get_products_image()` as a miss and fall through to the catalog original (`products_image`) so suggest hydration no longer blanks product thumbs when local resized files are missing but HTTP/CDN originals still resolve (e.g. KIP demo).
- Force hydrate when an already-rendered thumb is the Zen placeholder (valid `naturalWidth` but still wrong).

## Prior (v1.3.61)

- Allow catalog indexing when `locked_domain` is an intentional nonprod host (demo/staging tenants).
