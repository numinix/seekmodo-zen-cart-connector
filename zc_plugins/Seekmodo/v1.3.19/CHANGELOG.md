# v1.3.19 (2026-07-07)

## Fixed

- **Zen Cart 1.5.7 Tools menu** — self-healing admin page registration via `zen_register_admin_page()` (singular); earlier releases only called the 1.5.8+ plural API, so Connect to Seekmodo never appeared under Tools on 1.5.7 installs.
- **`extra_configures` bootstrap** — loads `numinix_seekmodo_admin_pages.php` on every admin request so file-only/rsync installs heal without Plugin Manager → Install.

## Changed

- **`zcVersions`** — manifest now includes `v157` for official Zen Cart 1.5.7 compatibility.

## Included from fleet head (v1.3.17–v1.3.18)

- Suggest/SERP live-stock parity — typeahead applies the same live DB stock partition as the SERP.
- Suggest stock reorder timing after widget paint (`seekmodo_action=stock-order`).
