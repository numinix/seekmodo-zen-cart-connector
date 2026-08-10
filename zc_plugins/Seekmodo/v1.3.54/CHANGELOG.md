# Seekmodo for Zen Cart v1.3.54

## 2026-08-10 - Enhanced Native SERP when unpaid / unpaired

- **SERP uses Enhanced Native without the gateway** — the listing
  observer no longer early-returns when `numinix_seekmodo_enabled()`
  is false or sticky unpaid (`trial_expired` / `over_quota` /
  cancelled) prefers local suggest. Unpaired installs and trial-ended
  stores keep multi-field SQL retrieval + FIELD ordering on the SERP,
  matching typeahead’s Enhanced Native path (v1.1.6 / v1.3.51).
- **Skip wasted gateway round-trips** while sticky unpaid — same gate
  as `Client::shouldPreferLocalSuggest()`.
- Relevance sort UI may render on Enhanced Native SERPs as well as
  cloud-ranked ones.

## 2026-08-10 - Suggest branded-theming CSS custom properties

- See v1.3.53 notes (inherited base).
