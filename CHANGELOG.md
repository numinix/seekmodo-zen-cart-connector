# Seekmodo for Zen Cart — top-level changelog

This file tracks what's in the **latest** released zip. The full
per-version detail lives next to the source under
`zc_plugins/Seekmodo/v<X.Y.Z>/CHANGELOG.md`.

## v1.0.7 — 2026-05-31

- **In-plugin auto-update — admin "Updates" page (Sprint 4 PR 2).**
  New `admin/numinix_seekmodo_updates.php` (sibling of
  `numinix_seekmodo_connect.php`) pulls
  `https://seekmodo.com/plugins/manifest.json`, compares
  `platforms.zen_cart.latest` against the local `pluginVersion`,
  and surfaces release notes + an **Apply update** button. The
  manifest's ed25519 signature is verified against the public key
  vendored at `admin/release-signing.pub`.
- **Daily update-check cron (Sprint 4 PR 3).** New CLI runner
  `admin/numinix_seekmodo_check_updates.php` runs once a day from
  cron. When a new version is found it writes a sentinel row that
  the admin shell renders as a top-bar one-liner linking to the
  Updates page. `tools/install_redline_connector.py` (seekmodo
  monorepo) prints the cron line alongside the existing indexer
  line.
- **One-click apply + rollback (Sprint 4 PR 4).** The Updates page's
  **Apply update** action downloads the signed zip, re-verifies
  SHA-256 + ed25519, snapshots the live tree to
  `.backup-<oldver>/`, expands the new tree, and runs the new
  version's `ScriptedInstaller` upgrade entry-point. A
  **Roll back to vX.Y.Z** link on the same page swaps directories
  back; the last 3 backups are kept and older ones are pruned.
- **Vendored release-signing public key.** `tools/build_release.py`
  (Sprint 4 PR 1) writes `admin/release-signing.pub` into each
  per-version plugin tree on every release build.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.7/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.7/CHANGELOG.md).

## v1.0.6 — 2026-05-31

- **Bot-check backend selector (W6c, PROJECT_PLAN.md §P1-14 Phase B).**
  `RemoteConfig::writeThrough()` now mirrors **eight** keys from the
  gateway snapshot (was seven) — adding `bot_check_backend` →
  `NUMINIX_BOT_CHECK_BACKEND`. Values are clamped to `legacy` |
  `gateway`; anything else is dropped (the row is left untouched, and
  the bot-check client falls through to its built-in `legacy`
  default). Operators flip the value from `admin.seekmodo.com` once
  Phase B shadow validation completes on a tenant.
- **Vendored bot-check client.**
  `catalog/includes/functions/numinix_bot_check_client.php` now ships
  inside the plugin tree. Reads `NUMINIX_BOT_CHECK_BACKEND` and routes
  classify / nonce.issue / nonce.verify either at
  `bot-check.numinix.com` (legacy) or at the gateway's `BotCheck\*`
  tools (`/v1/bot.classify`, `/v1/nonce.issue`, `/v1/nonce.verify`)
  when set to `gateway`. `if (!function_exists(...))` guards on every
  helper keep the existing tenant-repo copy as the first-loaded
  authoritative one until the connector deploy runs.
- **Installer row.** `ScriptedInstaller` adds
  `NUMINIX_BOT_CHECK_BACKEND` (default `'legacy'`) to the Seekmodo
  Search configuration group.
- **Tests.** New `tests/W6cBackendSelectorTest.php` pins the 8-key
  writeThrough surface, the gateway/legacy switching path inside the
  bot-check client (URL + header scheme + endpoint remap), and the
  malformed-snapshot guard.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.6/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.6/CHANGELOG.md).

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
