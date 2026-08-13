# Seekmodo for Zen Cart v1.3.56

## 2026-08-12 — Catalog push keyset pagination (OOM fix)

- **Full catalog push no longer loads every product into PHP memory.**
  Zen Cart's `queryFactory::Execute()` buffers the entire result set;
  joining active products with HTML descriptions exhausted the default
  256MB `memory_limit` mid-cron (`query_factory.php` line 44) on
  mid-size catalogs. `numinix_seekmodo_push_catalog.php` now walks
  with keyset pagination (`products_id > last LIMIT page`) sized to
  the upsert batch, so peak memory stays ~one page of rows.

