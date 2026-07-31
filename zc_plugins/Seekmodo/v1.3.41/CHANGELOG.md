# Seekmodo for Zen Cart v1.3.41

## 2026-07-31 - SERP categories_filter_title (NS-26042 Cannapot)

- Define `$categories_filter_title` early on advanced search results so
  Winchester Responsive / PHP 8+ themes do not log
  `Undefined variable $categories_filter_title` when the SERP template
  echoes it without isset(). Uses the category name when
  `categories_id` is present.

## 2026-07-29 - Tax-inclusive index prices (from v1.3.40)

- When `DISPLAY_PRICE_WITH_TAX=true`, catalog Push indexes the gross
  (VAT-inclusive) selling price using `zen_add_tax` / store tax class,
  matching PDP display (e.g. EUR 26.50 net + 13% = EUR 29.95).
