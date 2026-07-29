# Seekmodo for Zen Cart v1.3.38

## 2026-07-29 - Suggest i18n + admin PHP override (NS-26042 Cannapot)

- **Suggest chrome translations** — wire `results_for` and
  `products_pending` through the `labels` attribute so DE/ES/FR no
  longer show English "N results for" / "Matching products appear when
  you pause typing…". French keywords header uses "Propositions".
- **Admin Push reads catalog PHP binary override** — Connect → Push
  now loads `/shop/includes/extra_configures/numinix_seekmodo_php_binary.php`
  even when the admin SAPI does not auto-include catalog
  extra_configures (fixes "no php binary found" after the merchant
  already created the override for `/usr/bin/php8.3`).

## 2026-07-29 - Suggest thumb + Push session (from v1.3.37)

- Prefer catalog originals over Image Handler `/images/cache/` 403 URLs.
- CLI Push `session_write_close()` after bootstrap.
