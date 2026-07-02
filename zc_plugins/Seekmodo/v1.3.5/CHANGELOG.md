# Seekmodo Zen Cart connector — v1.3.5 changelog

## v1.3.5 — suggest thumbnail hydration + ZC route fix (2026-07-02)

- **Suggest product thumbnails** — when gateway `/v1/suggest` rows lack
  `image_url`, the observer hydrates empty thumb slots on
  `seekmodo-suggest:open` via batch lookup at
  `numinix_seekmodo_suggest.php?seekmodo_action=images&ids=…`.
- **Zen Cart cart-handler collision** — shim routes use `seekmodo_action=`
  instead of bare `action=` so `init_cart_handler.php` does not intercept
  browser-token refresh or image hydration before the shim runs.
- **Optimized thumb URLs** — `numinix_seekmodo_catalog_doc_image_url()` skips
  the `DIR_WS_IMAGES` prefix for `cache/optimized_images/` paths.
- **Catalog-root shim sync** — ship `tools/sync_catalog_shims.php` so tenants
  can refresh the five catalog-root HTTP shims after plugin upgrades.
