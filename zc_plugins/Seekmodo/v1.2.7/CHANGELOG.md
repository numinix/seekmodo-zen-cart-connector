# Seekmodo Zen Cart connector — v1.2.7 changelog

## v1.2.7 — purchase telemetry on forked checkout notifiers (2026-06-24)

- **Purchase observer fix** — `NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS`
  signatures differ between stock Zen Cart and Numinix forks (e.g. Redline
  passes the full `$order` object once instead of per-line arrays). The observer
  now dispatches all known shapes and reads cart-style `id` as well as
  `products_id`, with `final_price` / `price` forwarded for revenue rollup.
- Fixes zero purchase events on high-volume enforce tenants where checkout
  completed but analytics showed `purchase_30d=0`.
