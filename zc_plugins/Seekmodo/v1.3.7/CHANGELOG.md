# Seekmodo Zen Cart connector — v1.3.7 changelog

## v1.3.7 — suggest high-DPI thumbnail hydration (2026-07-03)

- **Suggest image quality** — `numinix_seekmodo_suggest_product_images()` now
  resolves thumbnails via `zen_get_products_image()` at 240px (Image Handler /
  Numinix automatic optimization) instead of raw catalog paths or 60px legacy
  thumbs. Open-event hydration upgrades **all** visible product rows (not only
  empty placeholders), replacing low-res gateway `image_url` values that looked
  pixelated in split-rail mobile grids.
