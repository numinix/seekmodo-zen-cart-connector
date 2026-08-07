# Seekmodo for Zen Cart v1.3.47

## 2026-08-07 - CLI host fallback + suggest/SERP parity

- **CLI / cron storefront host** — `Client::storefrontHost()` (and the
  locked-domain / EnvProbe / Pairing forks) fall back to Zen Cart
  `HTTPS_SERVER` / `HTTP_SERVER` when `HTTPS_CATALOG_SERVER` is unset.
  Storefronts that only define the configure.php `*_SERVER` constants
  (e.g. Redline) no longer fail `numinix_seekmodo_can_index()` in CLI,
  which previously stalled the watermark and left new products out of
  Typesense.
- **Suggest / SERP product parity** — `_numinix_seekmodo_build_suggest_payload()`
  now attaches `serp_passthrough` (same Typesense tuning as the SERP)
  plus `include_products`, and marks multi-word queries `complete` so
  gateway SerpPreview ranks suggest products with SearchTool. Suggest
  and SERP product lists match unless the SERP is on enhanced-native
  fallback. The browser web-component path already sent
  `serp-passthrough`; the PHP / suggest-shim path now matches.
