# v1.3.13 (2026-07-05)

## Added

- Index `occasion_tags`, `occasion_peak_month`, and `units_sold_lifetime` on catalog docs for gateway seasonal relevance and popularity ranking.
- Occasion tagging from product title, description, and category breadcrumbs (UK gift-store lexicon: Christmas, Valentine's, Easter, etc.).

## Changed

- `numinix_seekmodo_push_catalog.php` delegates doc assembly to `numinix_seekmodo_catalog_doc_from_row()` so full push and delta index share the same field shape.
