# Seekmodo for Zen Cart v1.3.42

## 2026-08-02 - LTR conversion attribution + cron noise

- **Drop automation conversions** — wget/curl/CLI cart and checkout
  hooks (PayPal recurring, store-credit crons) no longer mirror
  `add_to_cart` / `purchase` events. Those rows had empty UA hashes,
  zero `search_event_id`, and polluted conversion funnels.
- **`sm_ltr` attribution cookie** — persists last `search_event_id` and
  a product→seid map for 7 days so buy-now / login-regenerated checkout
  sessions still link graded labels to the SERP click that preceded
  them.

## 2026-07-31 - SERP categories_filter_title (from v1.3.41)

- Define `$categories_filter_title` early on advanced search results so
  Winchester Responsive / PHP 8+ themes do not log
  `Undefined variable $categories_filter_title` when the SERP template
  echoes it without isset(). Uses the category name when
  `categories_id` is present.
