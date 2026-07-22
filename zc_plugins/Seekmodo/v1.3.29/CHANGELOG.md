# Seekmodo for Zen Cart v1.3.29

## 2026-07-22 — suggest observer docblock parse fix

- **SuggestObserver parse error** - the `suggestLabels()` docblock in
  `NuminixSeekmodoSuggestObserver.php` used the path
  `languages/*/extra_definitions`, and the `*/` prematurely closed the
  `/**` comment. PHP then treated `extra_definitions` as code and fatally
  errored (`unexpected identifier "extra_definitions", expecting
  "function"` on line 1453), taking down the storefront (HTTP 500) after
  the v1.3.27 language-pack release. The path is now written as
  `languages/{lang}/extra_definitions`.
