# Seekmodo for Zen Cart v1.3.51

## 2026-08-10 - EN local suggest on unpaid / 402

- **Enhanced Native suggest when unpaid** — browser `/v1/suggest` HTTP
  402 (`trial_expired` / `over_quota`) no longer leaves a hung loading
  dropdown. Vendored `@seekmodo/web-components` 0.3.15 emits
  `seekmodo-suggest:empty` with `reason=quota`; sticky APCu/session
  gate skips cloud suggest and serves PHP Enhanced Native typeahead
  until a successful metered search/suggest clears it (resubscribe).
  Transient 5xx does not sticky-switch.

## 2026-08-09 - SERP default relevance sort

- **SERP keeps Seekmodo relevance by default** — ignore Zen Cart's
  injected `PRODUCT_LISTING_DEFAULT_SORT_ORDER` (often `2a` /
  products_name) unless `sort=` is present on the inbound query
  string. Default ranking uses `ORDER BY FIELD(...)` again.
- **`sort=relevance`** sentinel + footer UI injection prepend a
  localized "Relevance" / "Relevanz" option to theme sort menus so
  shoppers can return to Seekmodo ranking after sorting by name/price.
