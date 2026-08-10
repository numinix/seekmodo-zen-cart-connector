# Seekmodo for Zen Cart v1.3.50

## 2026-08-10 - SERP / suggest count parity (Typesense stores)

- **Omit legacy `NUMINIX_TYPESENSE_QUERY_BY` on gateway SERP** so
  `found` matches suggest/SerpPreview gateway defaults.
- **Sync `$es_products_id_2['total']`** when rewriting SERP results so
  custom Themesense product_listing rebuilds don't keep the native count.

## 2026-08-09 - SERP default relevance sort

- **SERP keeps Seekmodo relevance by default** — ignore Zen Cart's
  injected `PRODUCT_LISTING_DEFAULT_SORT_ORDER` (often `2a` /
  products_name) unless `sort=` is present on the inbound query
  string. Default ranking uses `ORDER BY FIELD(...)` again.
- **`sort=relevance`** sentinel + footer UI injection prepend a
  localized "Relevance" / "Relevanz" option to theme sort menus so
  shoppers can return to Seekmodo ranking after sorting by name/price.
