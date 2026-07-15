# v1.3.25 (2026-07-15)

## Fixed

- **Suggest thumbnails blank after 2nd keystroke** — vendored
  `@seekmodo/web-components` v0.3.13 paints gateway `image_url`
  immediately (eager) instead of deferring to empty placeholders while
  `img-ver` storefront hydration races the rAF re-render. Typing
  `pin` → `pint` (or clearing and retyping a cached query) no longer
  leaves grey `·` boxes. Also emits `seekmodo-suggest:rendered` after
  DOM paint; `NuminixSeekmodoSuggestObserver` hydrates 240px thumbs
  from that event with the same double-rAF guard as live-stock reorder.
