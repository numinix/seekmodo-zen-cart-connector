# Seekmodo Zen Cart v1.3.82

## 2026-09-03 - Unified Suggest UI on Enhanced Native / over-quota

- Stop forcing `seekmodo_typeahead.legacy.js` when
  `Client::shouldPreferLocalSuggest()` is sticky (unpaid / over_quota).
- Keep `<seekmodo-suggest>` and stamp `prefer-local` + typeahead-fallback
  so the same split-rail chrome fills from same-origin Enhanced Native.
- Enrich EN local typeahead rows with `image_url` / `name` / `id` and emit
  `rows` from the suggest shim for CE merge.
- Vendor `@seekmodo/web-components` 0.4.7 (`prefer-local` + products/rows
  envelope parsing).
