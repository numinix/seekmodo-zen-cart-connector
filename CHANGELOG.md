# Seekmodo for Zen Cart — top-level changelog

This file tracks what's in the **latest** released zip. The full
per-version detail lives next to the source under
`zc_plugins/Seekmodo/v<X.Y.Z>/CHANGELOG.md`.

## v1.0.5 — 2026-05-30

- **W6b consumption (default_mode + indexer_schedule).**
  `RemoteConfig::writeThrough()` now mirrors **seven** keys from the
  gateway snapshot (was five) — adding `default_mode` →
  `NUMINIX_SEEKMODO_DEFAULT_MODE` and `indexer_schedule` →
  `NUMINIX_SEEKMODO_INDEXER_SCHEDULE`.
- **Mode resolver fall-through.** `numinix_seekmodo_mode()` consults
  `NUMINIX_SEEKMODO_DEFAULT_MODE` when `MODE` is empty / unset /
  invalid, before defaulting to `'off'`.
- **Indexer cron renderer.** New `tools/render_indexer_cron.php`
  translates the `indexer_schedule` enum into a cron line. Consumed
  by the operator-side `tools/install_redline_connector.py` (in the
  seekmodo monorepo) to populate
  `/etc/cron.d/numinix-seekmodo-<tenant>` on managed-mode installs.
- **Installer rows.** ScriptedInstaller now seeds
  `NUMINIX_SEEKMODO_DEFAULT_MODE=active` and
  `NUMINIX_SEEKMODO_INDEXER_SCHEDULE=daily` as safe defaults.
- **Tests.** New `tests/W6bConsumptionTest.php` pins the 5-key →
  7-key writeThrough surface plus the four-case mode-resolver
  fall-through behaviour.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.5/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.5/CHANGELOG.md).

## v1.0.4 — 2026-05-29

- LTR P6 conversion-event helpers
  (`numinix_seekmodo_mirror_add_to_cart`, `numinix_seekmodo_mirror_purchase`).
- Filter-context propagation: structured `filters` map on every
  `/v1/search` so the trainer can group clicks by `(query, filter_hash)`
  without a JSON-extract scan.
- `search_event_id` linkage from search response → click beacon →
  trainer's grade joiner.
- New SERP impression beacon helper
  (`numinix_seekmodo_mirror_serp_impression`).
- New `numinix_seekmodo_current_search_event()` accessor used as the
  canonical source-of-truth for the current request's
  `(search_event_id, filter_by, filters, keyword)` tuple.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.4/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.4/CHANGELOG.md).

## v1.0.3 — 2026-05-28

- Storefront tuning forwarded to gateway (typo / drop / query_by /
  query_by_weights / sort_by). Hot-fix for the
  `keyword=automotive+rotisserie` regression on Redline.
- Generic filter pass-through — runtime filter-mapping registry
  (`numinix_seekmodo_register_filter_mapping`).
- Local-filter intersection helper for non-indexed filters.
- Type-ahead through the gateway with surface-tagged click mirroring.

Full detail: [`zc_plugins/Seekmodo/v1.0.3/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.3/CHANGELOG.md).

## v1.0.2 — 2026-05-28

- Forward shopper session / UA / IP to gateway so the bot-check
  classifier runs on `/v1/search`. Closes Seekmodo P0-1 / P0-3.

## v1.0.1 — 2026-05-28

- Connector now pages through gateway results so Zen Cart's local
  pagination sees every matching product (was capped at 10).
- IPv4 forced + connect timeout relaxed (250-750ms) — fixes the flaky
  Cloudflare IPv6 path that was tripping the circuit breaker.
- Response normaliser handles both the gateway's nested
  `results.hits[*].document` envelope and the legacy flat shape.

## v1.0.0 — 2026-05-26

- Initial release. Four swap-points (search, indexer, click beacon,
  type-ahead). Mode-aware (`off` / `shadow` / `enforce`). HMAC-signed
  REST envelope. APCu circuit breaker shared across php-fpm workers.
