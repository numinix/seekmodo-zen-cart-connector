# Seekmodo Zen Cart connector — v1.2.6 changelog

## v1.2.6 — suggest / SERP / View-all parity (2026-06-22)

- **Suggest bundle (`@seekmodo/web-components@0.3.1`)** — debounced `/v1/suggest`
  requests now include `complete: true` so the gateway can run the full SearchTool
  pipeline for stable queries (products + `meta.total` match `/v1/search`).
- **Form submit parity (enforce mode only)** — search forms that POST/GET to
  `advanced_search_result` / `search_result` append
  `seekmodo_skip_category_redirect=1` so Enter shows the same SERP as View all.
  Shadow/beacon tenants keep category redirect behaviour unchanged.
- **Requires** gateway `SuggestTool` SERP preview + tenant config
  `suggest_serp_mode` (migration 072).
