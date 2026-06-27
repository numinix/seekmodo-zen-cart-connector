# Seekmodo Zen Cart connector — v1.3.4 changelog

## v1.3.4 — view-all SERP route + suggest vehicle filter (2026-06-27)

- **View-all SERP route detection** — suggest `view_all_href` now picks
  `search_result` when the storefront ships that page, otherwise falls back to
  `advanced_search_result` with `search_in_description=1`. Fixes legacy forks
  (KIP-style) where linking to a missing `search_result` 301'd to the homepage.
- **Suggest vehicle filter sync** — autoboot stamps garage/YMM fitment context on
  the suggest web component and refreshes the vendored bundle so gateway queries
  and view-all URLs carry active vehicle filters.
