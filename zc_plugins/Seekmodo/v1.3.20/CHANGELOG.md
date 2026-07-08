# v1.3.20 (2026-07-08)

## Fixed

- **View-all SERP parity for keyword redirects** — when the suggest widget navigates to the SERP with `seekmodo_skip_category_redirect=1`, the connector now forwards `skip_merchandising_redirect=true` to the gateway so ranked product hits match suggest `meta.total` and order. Without this, redirect terms (e.g. KIP `pint`) returned gateway `found=0` and the observer fell back to Enhanced Native search (242 legacy hits vs 239 in suggest).

## Requires

- Gateway with `skip_merchandising_redirect` support on `/v1/search` (seekmodo monorepo, 2026-07-08).
