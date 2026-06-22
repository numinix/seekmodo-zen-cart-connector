# Seekmodo Zen Cart connector — v1.2.1 changelog

## v1.2.1 — typeahead product-row click attribution (2026-06-22)

- **`NuminixSeekmodoSuggestObserver`** — on `seekmodo-suggest:row-click`
  for `block=products`, fire a `sendBeacon` POST to
  `numinix_seekmodo_click.php` (`surface=typeahead`) before navigation.
  Parity with WordPress connector v0.8.2.
