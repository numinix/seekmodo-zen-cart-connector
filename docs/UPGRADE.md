# Upgrading the Seekmodo Zen Cart connector

The plugin's Scripted Installer handles version-to-version upgrades
cleanly: existing configuration rows are preserved, the new code path
ships alongside the old, and Plugin Manager flips the active version
to the new one once the install completes.

This file is the operator-facing change journal — what to expect, what
might bite, and how to roll back. The full per-version detail lives in
each version directory's `CHANGELOG.md`.

---

## How upgrades work

1. **Download** the new zip + sha256 sidecar from
   `seekmodo.com/plugins/`. Verify with `sha256sum -c`.
2. **Plugin Manager → Upload New Plugin** with the new zip. Zen Cart
   stages the new version directory (`zc_plugins/Seekmodo/v<X.Y.Z>/`)
   alongside the old one.
3. The plugin row now shows **Update** as an action. Click it.
4. The Scripted Installer:
   - Reads the existing `NUMINIX_SEEKMODO_*` rows and preserves their
     values.
   - Adds any new rows the version introduces (e.g. v1.0.5 added
     `NUMINIX_SEEKMODO_DEFAULT_MODE` and
     `NUMINIX_SEEKMODO_INDEXER_SCHEDULE`).
   - Updates `plugin_control.version` to the new value.
   - Bumps the active code path to the new directory.
5. Storefront traffic continues serving without restart.

> **First request after upgrade** can be ~50 ms slower while APCu
> warms — that's expected. After 1-2 requests it stabilises.

## Roll back

The previous version directory stays on disk. To roll back:

1. Plugin Manager → **Seekmodo** → **Uninstall** (if Zen Cart's
   plugin manager exposes a "downgrade" action use that instead;
   1.5.8a does not).
2. Locate the previous version dir (e.g. `zc_plugins/Seekmodo/v1.0.3/`).
3. Plugin Manager → **Seekmodo v1.0.3** → **Install**.
4. The Scripted Installer is symmetric — config rows are preserved.

If you stash the previous zip alongside the new one before upgrading,
this whole path is a 30-second operation.

## Version notes

### v1.0.5 (current)

- New constants `NUMINIX_SEEKMODO_DEFAULT_MODE` (default `active`) and
  `NUMINIX_SEEKMODO_INDEXER_SCHEDULE` (default `daily`). Both are
  seeded by the installer and are gateway-authoritative.
- `RemoteConfig::writeThrough()` mirrors them from `tenant.snapshot`.
- New helper `tools/render_indexer_cron.php` for managed-mode installs.

**Bite watch:** if you'd previously hand-tuned the `MODE` row to
`shadow` or `enforce` and never touched seekmodo.com's mode setting,
the v1.0.5 upgrade is still safe — `MODE` continues to override
`DEFAULT_MODE`. The new constant only matters when `MODE` is unset
(empty), which on existing installs it isn't.

### v1.0.4

- LTR P6 conversion-event helpers added. Storefront templates that
  call `numinix_seekmodo_mirror_add_to_cart` / `_purchase` from Zen
  Cart's notifier hooks start producing graded relevance labels.
- New `numinix_seekmodo_current_search_event()` accessor returns the
  most recent `(search_event_id, filter_by, filters, keyword)` tuple
  for the current request.

**Bite watch:** if you have custom storefront code calling
`numinix_seekmodo_mirror_impression()` directly, prefer the new
`numinix_seekmodo_mirror_serp_impression()` for SERP renders — it
threads the `search_event_id` automatically.

### v1.0.3

- Storefront tuning forwarded to gateway (typo / drop / query_by).
- Generic filter pass-through via runtime filter-mapping registry.
- Type-ahead through the gateway with surface-tagged click mirroring.

**Bite watch:** if your storefront uses non-standard URL params for
filters (e.g. `?type=…` instead of `?p_type=…`), register them at
boot — see [`FILTERS.md`](FILTERS.md). Without registration the
gateway sees no filter and your sidebar is effectively a no-op.

### v1.0.2

- Forwards shopper session / UA / IP so the bot-check classifier runs
  on every `/v1/search`. No operator action required.

### v1.0.1

- Pagination fix (cursor through every gateway page rather than just
  the first 10). No operator action required.

### v1.0.0

- Initial release.

## Migrating from `NuminixSeekmodo` (legacy unique_key)

Before May 2026 the plugin's `unique_key` was `NuminixSeekmodo` and
its source directory was `zc_plugins/NuminixSeekmodo/`. The May 2026
rename to `Seekmodo` was breaking — Plugin Manager treats unique_keys
as opaque IDs, so the `NuminixSeekmodo` row needs to go.

If you're upgrading **across** that rename:

1. Run the install flow for the new `Seekmodo` plugin as above.
2. The Scripted Installer detects the legacy `NuminixSeekmodo`
   `plugin_control` row and removes it (after archiving the legacy
   directory to `zc_plugins/NuminixSeekmodo.legacy-<ts>/`).
3. The configuration group's title is renamed from `Numinix Seekmodo`
   to `Seekmodo Search`. Existing constants (which already use the
   `NUMINIX_SEEKMODO_` prefix) are left as-is.
4. Verify by running `tools/probe_redline_seekmodo.py --env=<env>`
   from your seekmodo monorepo checkout (operator-only tool).

If you're scripting this against a multi-store deployment, Numinix's
`tools/install_redline_connector.py` (in the seekmodo monorepo) is the
canonical reference for the migration SQL. It's idempotent.
