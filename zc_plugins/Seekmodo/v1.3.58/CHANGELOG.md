# Seekmodo for Zen Cart v1.3.58

## 2026-08-12 - Suggest thumbs: ignore Zen no_picture placeholders

- Treat `no_picture.*` from `zen_get_products_image()` / gateway docs as a miss and fall through to the catalog original so suggest hydration no longer blanks product thumbs on Image Handler hosts.
- Force hydrate when an already-rendered thumb is the Zen placeholder (valid `naturalWidth` but still wrong).

## Prior (v1.3.57)

- `record_indexer_run()` duration cast fix for PHP 7.1+.
