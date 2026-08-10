# Seekmodo for Zen Cart v1.3.49

## 2026-08-10 - SERP ES/Typesense total parity

- **Legacy `$es_products_id_2` total stays in sync with Seekmodo** —
  stores whose `product_listing.php` rebuilds pagination with
  `['total' => $es_products_id_2['total']]` (pre-Seekmodo Typesense/
  elasticsearch SERP bags) no longer display the native count while
  suggest/gateway report the Seekmodo total.

## 2026-08-09 - SERP listing SQL parity

- **SERP product grid uses Seekmodo SQL** — after a successful gateway
  search the observer now sets `$GLOBALS['listing_sql']` alongside the
  `$result` splitPageResults rewrite. Stock and custom
  `product_listing.php` modules rebuild pagination from `$listing_sql`
  (notifier param1 is by-value), so the listing grid + "1 – N of M
  items" no longer fall back to native FULLTEXT while the header still
  showed the Seekmodo result count.
