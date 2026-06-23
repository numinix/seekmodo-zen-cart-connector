# Seekmodo Zen Cart connector — v1.2.4 changelog

## v1.2.4 — catalog orphan prune + suggest bundle refresh (2026-06-23)

- **`numinix_seekmodo_push_catalog.php`** — after a successful full push,
  calls gateway `catalog.prune` with the run-start cutoff so hard-deleted
  SKUs drop out of suggest/typeahead without manual Typesense ops. Use
  `--no-prune` to skip reconcile during debugging.
- **Pending-delete queue** — `NUMINIX_SEEKMODO_PENDING_DELETES` helpers
  for near-real-time tombstone flushes between full runs.
- **Suggest bundle** — `@seekmodo/web-components` v0.3.0: split-rail mobile
  draggable divider (default ~28% keyword rail, flex-controlled resize,
  scroll for overflow). Native product title tooltips; anchor rAF throttle;
  `bundleSrc()` filemtime cache-bust.

## v1.2.3 — typeahead search_event_id + SEO SERP clicks (2026-06-16)

- Typeahead product-row clicks thread `search_event_id` from `/v1/suggest`
  meta through row-click beacons.
- Product-info click attribution resolves `products_id` from SEO slug URLs
  and referer keywords.
