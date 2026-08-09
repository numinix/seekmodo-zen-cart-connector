# Seekmodo for Zen Cart v1.3.48

## 2026-08-09 - Soft-guard live stock warnings on search

- **Search stock soft-guard** — `numinix_seekmodo_catalog_doc_live_in_stock()`
  suppresses Zen Cart core `Undefined array key "products_quantity"`
  warnings from `zen_get_products_stock()` (orphaned / attribute edge-case
  product ids). SERP live-stock partition demotes Typesense ids with no
  products row without a second stock lookup (NS-26042 Cannapot).
