# Seekmodo Zen Cart connector — v1.2.9 changelog

## v1.2.9 — search/click session_id parity (2026-06-24)

- **PHP session first** — `_numinix_seekmodo_session_id()` now prefers the active
  PHP session over the click-log cookie token so gateway search rows and later
  click/purchase events share the same `session_id` within a storefront visit.
- **Stashed search session** — `_numinix_seekmodo_remember_search_event()` records
  the `session_id` used on the search row; click/conversion mirroring reuses that
  stashed id on subsequent requests (PDP, cart, checkout) so session-aware linkage
  and search-attributed revenue rollups can join clicks back to searches.
- Builds on v1.2.8's unified event helper; no template changes required.
