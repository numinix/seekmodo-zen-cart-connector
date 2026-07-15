# v1.3.24 (2026-07-15)

## Fixed

- **Zen Cart 1.5.7 pairing / shim bootstrap** — catalog-root
  endpoints (`numinix_seekmodo_pair_callback.php`, suggest, click,
  recommend, forget-me, push/delta CLI) now load
  `init_numinix_seekmodo.php` from `zc_plugins/Seekmodo/v*/` when
  Plugin Manager auto_loaders did not merge into the storefront.
  Without this, a live pair-callback returned JSON
  `Class "Numinix\Seekmodo\Pairing" not found` and suggest answered
  `connector_unavailable` even though the eight shim files were
  present under `/shop/`.
- **Install/Upgrade shim deploy** — `ScriptedInstaller` copies the
  eight flat catalog-root PHP shims next to `index.php` on every
  Install/Upgrade so subdirectory catalogs (e.g. `/shop/`) do not
  require a separate manual FTP of those files.

## Changed

- Added `numinix_seekmodo_ensure_plugin_init.php` helper used by the
  catalog-root shims.
