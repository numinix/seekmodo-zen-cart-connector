# v1.3.16 (2026-07-06)

## Fixed

- Suggest product panel latency after idle typing — skip slow SerpPreview on two-phase product fetch and drop redundant typeahead-fallback HTTP round-trip when gateway already returned products.
- Small/pixelated suggest thumbnails — restore img-ver 240px hydration guard, scheduled retries after async product render, and force-upgrade of gateway 60px images.

## Changed

- Vendored `@seekmodo/web-components` bundle emits `seekmodo-suggest:render` when product rows paint.
