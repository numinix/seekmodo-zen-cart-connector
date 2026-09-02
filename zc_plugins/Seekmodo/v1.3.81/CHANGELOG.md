# Seekmodo for Zen Cart v1.3.81

## 2026-09-02 - Suggest unpaid chrome + SERP pagination total clamp

- Unpaid / over-quota Suggest keeps the Seekmodo-styled box: do not
  destroy `<seekmodo-suggest>` on empty; merge typeahead-fallback on 402.
- Raise SERP pagination total to `count($productIds)` when Typesense
  `found` (or a legacy total bag) understates the listing IN-list
  length. Prevents last-page overflow that infinite-scroll themes
  surface as "40 of 34 results".

# Seekmodo for Zen Cart v1.3.80

## 2026-08-31 - Language-aware Suggest View all SERP URLs

- Prefer `numinix_seekmodo_href_link_raw` when building Suggest `view_all_href`
  so the catalog base matches the storefront.
- Detect language path prefixes from `REQUEST_URI` / session language dirs
  (english, french, german, deutsch, spanish, ...) and 2-letter
  prefixes (/fr/, /de/, /es/, ...) when `DIR_WS_CATALOG` is "/" but the
  shopper is under /french/....
- Keep `NUMINIX_SEEKMODO_SUGGEST_VIEW_ALL_HREF` override.
- JS autoboot fallbacks no longer hardcode unprefixed
  /index.php?main_page=... / /search?q={q}; they use CFG or the current
  path language prefix.

# Seekmodo for Zen Cart v1.3.79

## 2026-08-31 - Suggest chrome i18n + web-components bundle

- Vendored `@seekmodo/web-components` suggest bundle refresh
  (`seekmodo_suggest.bundle.js` from `suggest.global.js`).
- Extended connector `suggestLabels()` defaults/map and EN/DE/deutsch/ES/FR
  language packs with chrome keys: `category_filter`, `related`, `try`,
  `page`, `article`, `tool`, `tools` (plus existing magazine keys
  `price_range`, `best_matches`, `more_results`, `top_match`).

# Seekmodo for Zen Cart v1.3.78

## 2026-08-30 - Suggest UI follows storefront language

- **Suggest chrome is no longer stuck in English** — the vendored
  `@seekmodo/web-components` 0.4.4 widget now reads the connector
  `labels` attribute / `window.SeekmodoSuggestLabels` pack (Cannapot
  ticket #615048).
- **`results_for` / magazine labels** use language packs instead of
  hardcoded English.
- Restored proper UTF-8 for DE/FR/ES/deutsch packs.
- **Regression guards:** `tests/test_suggest_i18n_labels_v178.php` (+ CI).


## 2026-08-29 - Enhanced Native + live stock demote OOS

- **Enhanced Native SERP ORDER BY demotes out-of-stock first** —
  default / popularity ranking puts `products_quantity > 0` ahead of
  OOS so unpaid and gateway-fallback SERPs match gateway
  `demote_all_oos` behaviour (Cannapot-style sold-out tops).
- **Live stock flags use `zen_get_products_stock` on gateway-sized
  ID lists (≤500)** — attribute / master-SKU stock matches the
  storefront SOLD OUT badge instead of trusting raw
  `products_quantity` alone. Huge legacy EN ID pools still use the
  bulk qty query only (STRIN OOM guard).

# Seekmodo for Zen Cart v1.3.77

## 2026-08-27 - Multilingual lang stamp

- Stamp ISO 639-1 `lang` on indexed catalog docs and forward
  storefront language on search/suggest/shopper_context so the
  gateway can filter multilingual results.
