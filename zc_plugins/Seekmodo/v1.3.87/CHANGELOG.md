# Seekmodo Zen Cart v1.3.87

## 2026-09-23 - Restock intelligence sync

- Push exact active-product quantities in tenant-scoped snapshot batches.
- Backfill complete order lines independently of search attribution.
- Hash logged-in customer IDs with the tenant ID so demand concentration can
  be measured without sending direct identifiers or customer PII.
- Keep restock transport out of checkout; the scheduled sync replaces complete
  order line sets and corrects cancellations.

## 2026-09-07 - Broken suggest image recache

- `seekmodo_action=images&mark_dirty=1` queues catalog dirty ids so
  Typesense `image_url` is re-pushed after live thumb hydrate.
- Suggest CFG sets `images-hydrate-url` for shared web-components heal.
- `tenant.snapshot` `pending_image_refresh_ids` drains into the dirty
  queue and acks the gateway.
- Fix: run pending-image drain after cron reconcile (not inside its
  catch), so successful snapshot pulls still recache broken images.

## 2026-09-07 - Recommendation hover stacking (from v1.3.85)

### Fixed
- Recommendations strip CSS/JS: horizontal scroll on a viewport wrapper with
  reserved bottom padding so theme product-card hover expansions are not
  clipped by following page sections (reviews, etc.).
