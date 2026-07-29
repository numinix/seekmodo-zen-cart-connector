# Seekmodo for Zen Cart v1.3.39

## 2026-07-29 - Complete storefront i18n (NS-26042 Cannapot)

- **Suggest chrome** — localize remaining hardcoded strings: did-you-mean
  swap (`showing_results_for`), short view-all link, `{count} products`
  pill, redirects section header. DE `Powered by` → `Unterstützt von`.
- **Recommendations** — placement/cascade headings from language packs
  via `window.SeekmodoRecoLabels` (DE/ES/FR).
- **Legacy typeahead + CORS notice** — read the same suggest labels
  global so fallback UI is not English-only.

## 2026-07-29 - Suggest i18n + admin PHP override (from v1.3.38)

- `results_for` / `products_pending`; FR Propositions; admin loads
  catalog PHP binary override.
