# Seekmodo Zen Cart v1.3.86

## 2026-09-07 - Broken suggest image recache

- `seekmodo_action=images&mark_dirty=1` queues catalog dirty ids so
  Typesense `image_url` is re-pushed after live thumb hydrate.
- Suggest CFG sets `images-hydrate-url` for shared web-components heal.
- `tenant.snapshot` `pending_image_refresh_ids` drains into the dirty
  queue and acks the gateway.

## 2026-09-07 - Recommendation hover stacking (from v1.3.85)

### Fixed
- Recommendations strip CSS/JS: horizontal scroll on a viewport wrapper with
  reserved bottom padding so theme product-card hover expansions are not
  clipped by following page sections (reviews, etc.).
