# Seekmodo for Zen Cart v1.3.62

## 2026-08-12 - Suggest thumbs: ignore Zen no_picture placeholders

- Treat `no_picture.*` from `zen_get_products_image()` as a miss and fall through to the catalog original (`products_image`) so suggest hydration no longer blanks product thumbs when local resized files are missing but HTTP/CDN originals still resolve (e.g. KIP demo).
- Force hydrate when an already-rendered thumb is the Zen placeholder (valid `naturalWidth` but still wrong).

## Prior (v1.3.61)

- Allow catalog indexing when `locked_domain` is an intentional nonprod host (demo/staging tenants).
