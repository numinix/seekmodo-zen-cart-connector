## Exact-part empty suggest alignment (prep for v1.3.45)

- Restrict suggest to products/categories + drop View-all for exact part tokens;
  REST shim clears keywords when products empty (AKS 1.7.6).

# Seekmodo for Zen Cart v1.3.44

## 2026-08-03 - Exact SKU filter for hyphenated part tokens

- **Exact `model` / `sku` filter for complete part numbers** — port of
  AKS `EzNumberBooster::looksLikeExactPartToken` (incl. all-digit
  multi-hyphen `4-6340-20`). When the query matches, pin
  `(model:=TOKEN || sku:=TOKEN)` so Typesense `drop_tokens` cannot
  dump hundreds of irrelevant SERP hits on a miss. Missing catalog
  SKU → zero results. Keeps shopper keyword as `q` (RED-1862).
- Applied on full SERP, suggest payload, and legacy typeahead path;
  gated by the existing SKU-boost master switch + trigger regex.

## 2026-08-03 - Numeric model / SKU lookup (from v1.3.43)

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
