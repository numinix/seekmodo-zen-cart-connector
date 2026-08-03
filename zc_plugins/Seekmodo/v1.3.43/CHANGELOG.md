# Seekmodo for Zen Cart v1.3.43

## 2026-08-03 - Numeric model / SKU lookup (RED-1862)

- **Bare-digit search uses Typesense `id` + exact `model`/`sku`** —
  `_numinix_seekmodo_apply_products_id_lookup` no longer emits
  `products_id:=N` (not a filterable field on commerce schemas; Typesense
  400 → gateway failure → native LIKE fallback). Filters are now
  `(id:=N || model:=N || sku:=N)` and keep the digit query as `q`.
  Fixes storefront searches like Redline `"4826"` where the product's
  model is `4826` but `products_id` is different (was #46 of ~1700).

## 2026-08-02 - LTR conversion attribution + cron noise (from v1.3.42)

- **Drop automation conversions** — wget/curl/CLI cart and checkout
  hooks (PayPal recurring, store-credit crons) no longer mirror
  `add_to_cart` / `purchase` events. Those rows had empty UA hashes,
  zero `search_event_id`, and polluted conversion funnels.
- **`sm_ltr` attribution cookie** — persists last `search_event_id` and
  a product→seid map for 7 days so buy-now / login-regenerated checkout
  sessions still link graded labels to the SERP click that preceded
  them.
