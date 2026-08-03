# Seekmodo for Zen Cart v1.3.43

## 2026-08-03 - Numeric model / SKU lookup (RED-1862)

- **Bare-digit search OR model/sku** — `_numinix_seekmodo_apply_products_id_lookup`
  still matches `products_id:=N` for admin id paste, but also ORs exact
  `model:=N` / `sku:=N` (Zen Cart indexes `products_model` into both).
  Fixes storefront searches like Redline `"4826"` where the product's
  model is `4826` but `products_id` is different: previously the
  exclusive id filter returned 0 gateway hits and native LIKE put the
  perfect model match at #46 of ~1700 results.

## 2026-08-02 - LTR conversion attribution + cron noise (from v1.3.42)

- **Drop automation conversions** — wget/curl/CLI cart and checkout
  hooks (PayPal recurring, store-credit crons) no longer mirror
  `add_to_cart` / `purchase` events. Those rows had empty UA hashes,
  zero `search_event_id`, and polluted conversion funnels.
- **`sm_ltr` attribution cookie** — persists last `search_event_id` and
  a product→seid map for 7 days so buy-now / login-regenerated checkout
  sessions still link graded labels to the SERP click that preceded
  them.
