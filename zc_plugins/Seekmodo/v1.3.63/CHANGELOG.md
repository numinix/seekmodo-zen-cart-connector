# Seekmodo for Zen Cart v1.3.63

## 2026-08-12 - Suggest no_picture helpers (testable)

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
