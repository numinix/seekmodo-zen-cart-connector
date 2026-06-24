# Seekmodo Zen Cart connector — v1.2.8 changelog

## v1.2.8 — search-click session attribution (2026-06-24)

- **Session id parity** — click, impression, add-to-cart, and purchase events
  now use `_numinix_seekmodo_event_session_id()`, the same resolution order as
  gateway search calls (`numinix_search_log_session_token` → PHP `session_id`
  → UA+IP hash). Previously clicks/purchases often shipped with an empty
  `session_id` while searches used the PHP session fallback, so
  `ltr.beacon.audit` session-aware linkage and search-attributed revenue
  rollups stayed at ~0% even when shoppers clicked Seekmodo SERP results.
- No storefront template or observer changes required — existing SERP beacons
  and checkout hooks pick up the fix automatically on deploy.
