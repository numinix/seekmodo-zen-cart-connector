# v1.3.6 — suggest session-currency prices (2026-07-03)

- **Suggest price currency** — `<seekmodo-suggest>` no longer formats indexed
  base-currency floats as USD. The widget reads `currency` from the element
  attribute and `meta.region.currency` from `/v1/suggest` (gateway parity
  with SearchTool). Vendored `@seekmodo/web-components` bundle updated.
- **Session price hydration** — on `seekmodo-suggest:open`, batch lookup at
  `numinix_seekmodo_suggest.php?seekmodo_action=prices&ids=…` resolves
  `zen_get_products_display_price()` per `products_id` so multicurrency
  storefronts show the shopper's active currency and converted amount.
- **Catalog index** — pushes stamp `currency` (store default ISO code) on
  each product doc alongside `price`.
- **Shopper context** — autoboot forwards `shopper_context.currency` in
  `serp-passthrough` and sets the `currency` attribute on `<seekmodo-suggest>`.
