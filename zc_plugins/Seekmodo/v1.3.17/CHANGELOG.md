# v1.3.17 (2026-07-07)

## Fixed

- **Zen Cart 1.5.7 admin Tools menu** — ZC 1.5.7 ships `zen_register_admin_page()` (singular); the connector only called `zen_register_admin_pages()` (plural, 1.5.8+), so `Connect to Seekmodo` never registered when Plugin Manager → Install was skipped or failed. Adds a self-healing `extra_configures` bootstrap that registers both Tools entries on every admin load.

## Changed

- **`zcVersions`** — manifest now declares `v157` alongside `v158` and `v200` for official 1.5.7 compatibility.
