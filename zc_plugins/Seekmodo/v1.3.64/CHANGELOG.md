# Seekmodo for Zen Cart v1.3.64

## 2026-08-13 - Suggest price-range rail (Klevu QS parity)

- Vendored `@seekmodo/web-components` 0.3.16: split-rail left column
  caps keywords and categories at 4 each and adds a **Price range**
  section. Clicking a band re-fetches suggest products/categories with
  Typesense `filter_by` on `price`; click again to clear.
- View all substitutes `{price_from}` / `{price_to}` into the SERP URL
  as vanilla Zen Cart `pfrom` / `pto` (core advanced search params).
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
