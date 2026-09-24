# Seekmodo Zen Cart v1.3.86

## Unreleased - Over-quota PreferLocal after period reset

- Clear PreferLocal when the stored 402 `resets_at` is already past.
- Soft-probe `/v1/suggest` from `applyBillingSnapshot()` for an active
  `over_quota` sticky (WordPress ConfigPullCron parity) instead of
  refusing to clear on `billing.status=active` alone.
- Over_quota unpaid rechecks every 5 minutes (cancelled/trial still daily).
- Do not stamp PreferLocal from a 402 envelope whose `resets_at` is
  already in the past (stale stamp after renewal).

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
