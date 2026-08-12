# Seekmodo for Zen Cart v1.3.59

## 2026-08-12 - SERP click beacon category-id poison fix

- **`pidFromHref` no longer stamps `categories_id` as `product_id`.**
  The competitor / category-redirect click beacon used a bare
  `-(\\d+)` URL tail regex that matched Zen Cart `-c-N` category
  slugs. Those poison clicks arrived before the real product click
  and collapsed Seekmodo LTR shadow click-rank samples on Klevu
  tenants (KIP, Redline). Now rejects `cPath` / `categories_id` /
  `-c-N`, and only accepts `products_id=`, `-p-N`, or `-N.html`.

## Prior (v1.3.57)

- Cast catalog-push duration to int for `record_indexer_run`.
