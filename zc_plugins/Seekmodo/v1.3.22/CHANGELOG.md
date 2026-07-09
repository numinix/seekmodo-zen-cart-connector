# v1.3.22 (2026-07-09)

## Fixed

- **Product-info URL slug parsing** — `productsIdFromRequest()` no longer
  throws `preg_match(): Unknown modifier '|'` on SEO slug URLs when the
  `#`-delimited regex contained an unescaped `#` alternation branch.

## Documentation

- **Zen Cart 1.5.7 / file-only install path** — `docs/INSTALL.md` §2a
  documents catalog-root shim deployment, subdirectory catalogs (`/shop/`),
  Plugin Manager Install/Upgrade vs upload-only, expected zip sizes, and
  the pair-callback curl verification step. Mirrored in
  `docs/PLATFORM_NOTES.md`, `docs/TROUBLESHOOTING.md`, and
  `docs/UPGRADE.md`.
