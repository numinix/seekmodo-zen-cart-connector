# Seekmodo Zen Cart v1.3.84

## 2026-09-07 - Broken suggest image recache

- `seekmodo_action=images&mark_dirty=1` queues catalog dirty ids so
  Typesense `image_url` is re-pushed after live thumb hydrate.
- Suggest CFG sets `images-hydrate-url` for shared web-components heal.
- `tenant.snapshot` `pending_image_refresh_ids` drains into the dirty
  queue and acks the gateway.

## 2026-09-03 - Unified Suggest UI on Enhanced Native / over-quota

- Stop forcing `seekmodo_typeahead.legacy.js` when
  `Client::shouldPreferLocalSuggest()` is sticky (unpaid / over_quota).
- Keep `<seekmodo-suggest>` and stamp `prefer-local` + typeahead-fallback
  so the same split-rail chrome fills from same-origin Enhanced Native.
- Enrich EN local typeahead rows with `image_url` / `name` / `id` and emit
  `rows` from the suggest shim for CE merge.
- Vendor `@seekmodo/web-components` 0.4.7 (`prefer-local` + products/rows
  envelope parsing).
