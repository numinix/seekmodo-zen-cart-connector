# Seekmodo for Zen Cart v1.3.67

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
