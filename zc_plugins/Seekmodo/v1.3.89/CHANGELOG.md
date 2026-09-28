# Seekmodo for Zen Cart v1.3.89

## v1.3.89 - 2026-09-28 (SERP shopper sort)

### Fixed
- **SERP sorting with Seekmodo enabled** — shopper `sort=` choices
  (Artikelname / Preis / Artikelnummer / Erstelldatum and asc/desc) now
  re-order the Seekmodo result set instead of staying locked to relevance.
  Captures the sort at `NOTIFY_HEADER_START_ADVANCED_SEARCH_RESULTS`
  (before Zen Cart injects the store default) and reuses the theme's
  native `ORDER BY` from the pre-rewrite listing SQL so custom mappings
  (e.g. Cannapot Winchester: `3`=price, `5`=date_added) stay correct.
  Enhanced Native SERPs honor the same `sort=` codes. Ticket #615985.

