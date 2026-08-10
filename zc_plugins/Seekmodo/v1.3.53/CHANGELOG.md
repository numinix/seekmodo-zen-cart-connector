# Seekmodo for Zen Cart v1.3.53

## 2026-08-10 - Suggest branded-theming CSS custom properties

- **Suggest theming tokens** — rebundled `seekmodo_suggest.bundle.js`
  (`@seekmodo/web-components` 0.4.2). Adds `--seekmodo-suggest-accent`,
  `-header-bg/-color`, `-did-you-mean-bg/-border`, `-badge-bg/-color`,
  `-meta-bg/-color/-count-color`, `-cta-*`, `-dym-swap-*`,
  `-row-accent*`, and `-rail-bg` CSS custom properties so a storefront
  can fully brand the suggest dropdown (colors, CTA pill, row accent
  bar, rail background) from its own theme CSS without touching this
  plugin. No defaults changed — every new token falls back to the
  existing look, so installs with no overrides render identically to
  v1.3.52.

## 2026-08-10 - Admin billing notices (soft + sitewide)

- **Connect page soft banner** while paired + unpaid / over_quota /
  trial_expired / paused — amber for Enhanced Native, red for paused.
  Copy clarifies staying on Enhanced Native is free.
- **Admin homepage caution** once per admin session until dismissed
  (keyed by reason). Dismissals wipe when cloud recovers
  (`Client::clearCloudSuggestDenial`) so a future lapse notifies again.
- Never warns unpaired Enhanced Native–only installs.

## 2026-08-10 - EN local suggest on unpaid / 402

- **Enhanced Native suggest when unpaid** — browser `/v1/suggest` HTTP
  402 (`trial_expired` / `over_quota`) no longer leaves a hung loading
  dropdown. Vendored `@seekmodo/web-components` 0.3.15 emits
  `seekmodo-suggest:empty` with `reason=quota`; sticky APCu/session
  gate skips cloud suggest and serves PHP Enhanced Native typeahead
  until a successful metered search/suggest clears it (resubscribe).
  Transient 5xx does not sticky-switch.

## 2026-08-09 - SERP default relevance sort

- **SERP keeps Seekmodo relevance by default** — ignore Zen Cart's
  injected `PRODUCT_LISTING_DEFAULT_SORT_ORDER` (often `2a` /
  products_name) unless `sort=` is present on the inbound query
  string. Default ranking uses `ORDER BY FIELD(...)` again.
- **`sort=relevance`** sentinel + footer UI injection prepend a
  localized "Relevance" / "Relevanz" option to theme sort menus so
  shoppers can return to Seekmodo ranking after sorting by name/price.
