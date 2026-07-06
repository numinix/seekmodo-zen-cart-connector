# v1.3.15 (2026-07-06)

## Added

- **products_id search** — bare numeric queries (e.g. `1898`, `167`, or comma-separated `167,1898`) route to an exact `products_id:=` Typesense filter instead of BM25 text search. Fixes zero-result admin lookups on Zen Cart catalogs with short numeric product ids (common on KIP). Applies to full SERP search, typeahead `/v1/suggest`, and Enhanced Native SQL fallback.

## Changed

- `_numinix_seekmodo_build_suggest_payload()` sets `complete=true` on products_id lookups so suggest SERP preview matches the full results page.

# v1.3.13 (2026-07-05)

## Added

- Index `occasion_tags`, `occasion_peak_month`, and `units_sold_lifetime` on catalog docs for gateway seasonal relevance and popularity ranking.
- Occasion tagging from product title, description, and category breadcrumbs (UK gift-store lexicon: Christmas, Valentine's, Easter, etc.).

## Changed

- `numinix_seekmodo_push_catalog.php` delegates doc assembly to `numinix_seekmodo_catalog_doc_from_row()` so full push and delta index share the same field shape.
