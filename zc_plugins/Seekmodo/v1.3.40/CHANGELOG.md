# Seekmodo for Zen Cart v1.3.40

## 2026-07-29 - Tax-inclusive index prices (NS-26042 Cannapot)

- When `DISPLAY_PRICE_WITH_TAX=true`, catalog Push indexes the gross
  (VAT-inclusive) selling price using `zen_add_tax` / store tax class,
  matching PDP display (e.g. EUR 26.50 net + 13% = EUR 29.95).
- Active specials are preferred as the indexed selling price.
- Suggest price hydration numeric field follows the same tax rule.

## 2026-07-29 - Complete storefront i18n (from v1.3.39)

- Suggest / reco / legacy / CORS labels for DE/ES/FR.
