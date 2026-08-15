# Seekmodo for Zen Cart v1.3.65

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
