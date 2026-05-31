# Upgrading the Seekmodo Zen Cart connector

The plugin's Scripted Installer handles version-to-version upgrades
cleanly: existing configuration rows are preserved, the new code path
ships alongside the old, and Plugin Manager flips the active version
to the new one once the install completes.

This file is the operator-facing change journal — what to expect, what
might bite, and how to roll back. The full per-version detail lives in
each version directory's `CHANGELOG.md`.

---

## Routine path: in-plugin auto-update (v1.0.7+)

**The supported routine update path is the in-plugin Updates page.**
From the Zen Cart admin, navigate to **Tools → Seekmodo Updates**:

1. The page pulls
   `https://seekmodo.com/plugins/manifest.json` (5-min APCu cache),
   compares the manifest's `platforms.zen_cart.latest` against the
   `pluginVersion` in the local `manifest.php`, and surfaces a
   release-notes link.
2. Click **Apply update**.  The connector:
   - Downloads the signed zip from `manifest.url`.
   - Re-verifies SHA-256 against `manifest.sha256`.
   - Verifies the manifest's ed25519 signature against the public
     key vendored at `zc_plugins/Seekmodo/v<X.Y.Z>/admin/release-signing.pub`.
     A `kid` mismatch surfaces as "untrusted manifest — manual
     upgrade required" rather than silently failing open.
   - Snapshots `zc_plugins/Seekmodo/v<oldver>/` to a sibling
     `.backup-<oldver>/` directory.
   - Expands the new tree into `zc_plugins/Seekmodo/v<newver>/`.
   - Drops a `.pending-upgrade` sentinel; the new version's
     `ScriptedInstaller::executeUpgrade()` runs on the next admin
     page-load (this indirection avoids re-entrancy bugs in opcache
     when files are swapped mid-request).
3. Storefront traffic continues serving without restart.

**Roll back** from the same page: each backup is listed with a
**Roll back** button.  Last 3 backups are kept; older ones are
pruned automatically.

A daily cron (`numinix_seekmodo_check_updates.php`, installed by
the operator-side `tools/install_redline_connector.py`) refreshes
the `NUMINIX_SEEKMODO_UPDATE_NOTICE` sentinel.  When a newer
version is published it surfaces as a top-bar one-liner in the
Zen Cart admin shell linking to the Updates page.

> **First request after upgrade** can be ~50 ms slower while APCu
> warms — that's expected. After 1-2 requests it stabilises.

## Recovery path: Plugin Manager Upload + Scripted Installer

Use this when the Updates page can't reach `seekmodo.com` or the
manifest fails signature verification (e.g. you're behind a strict
egress firewall, or a zip release was hand-tampered with).

1. **Download** the new zip + sha256 sidecar from
   `seekmodo.com/plugins/`. Verify with `sha256sum -c`.
2. **Plugin Manager → Upload New Plugin** with the new zip. Zen Cart
   stages the new version directory (`zc_plugins/Seekmodo/v<X.Y.Z>/`)
   alongside the old one.
3. The plugin row now shows **Update** as an action. Click it.
4. The Scripted Installer:
   - Reads the existing `NUMINIX_SEEKMODO_*` rows and preserves their
     values.
   - Adds any new rows the version introduces.
   - Updates `plugin_control.version` to the new value.
   - Bumps the active code path to the new directory.
5. Storefront traffic continues serving without restart.

## Recovery path: `tools/install_redline_connector.py`

The Numinix-side operator script in the seekmodo monorepo
(`tools/install_redline_connector.py`) is the **bootstrap** /
**recovery** path — used when the tenant docroot has been wiped
(e.g. tenant repo `rsync --delete` clobbered the plugin tree, or
a fresh hosting provisioning needs the plugin laid down before the
admin page is reachable).  It is no longer the routine update path
as of v1.0.7; per
[`PROVISIONING.md`](https://github.com/numinix/seekmodo/blob/main/PROVISIONING.md)
§4 step 8 it stays as the recovery path only.

## Version notes

### v1.0.7 (current)

- **In-plugin auto-update** (Sprint 4 PR 2-4).  Tools menu now
  exposes a **Seekmodo Updates** page (sibling of *Connect to
  Seekmodo*) with a Check-for-updates button + one-click **Apply
  update** + per-backup **Roll back** action.  The page pulls
  `seekmodo.com/plugins/manifest.json`, compares the published
  version against the local install, and verifies the ed25519
  signature against the bundled public key.
- **Daily update-check cron.**  `tools/install_redline_connector.py`
  in the seekmodo monorepo now prints a daily cron line alongside
  the existing indexer cron.  The cron sets the
  `NUMINIX_SEEKMODO_UPDATE_NOTICE` sentinel which the admin shell
  reads to render a top-bar update banner.
- **Vendored release-signing public key** at
  `admin/release-signing.pub`.  Defence-in-depth: an attacker who
  hijacks `seekmodo.com` still cannot forge a manifest the plugin
  trusts.

**Bite watch:** if the Updates page reports "untrusted manifest —
manual upgrade required", the manifest's `sig_kid` doesn't match
the bundled public key.  This is intentional — a key rotation
needs to ship with a new connector release that bundles the new
public key, then admins re-install via Plugin Manager.  Apply +
Rollback won't bypass this gate.

### v1.0.6

- **Bot-check backend selector (W6c, P1-14 Phase B).**
  `RemoteConfig::writeThrough()` now mirrors **eight** keys from
  the gateway's `tenant.snapshot` payload (was seven), adding
  `bot_check_backend` → `NUMINIX_BOT_CHECK_BACKEND` (`legacy` |
  `gateway`, default `legacy`).  Operators flip this from
  `admin.seekmodo.com` once Phase B shadow validation passes.
- **Vendored bot-check client** at
  `catalog/includes/functions/numinix_bot_check_client.php`.
  Routes classify / nonce.issue / nonce.verify either at
  `bot-check.numinix.com` (legacy) or at the gateway's
  `BotCheck\*` tools when `NUMINIX_BOT_CHECK_BACKEND=gateway`.

**Bite watch:** v1.0.6 ships behaviour identical to v1.0.5 by
default (`legacy`).  The opt-in to `gateway` is a separate operator
action via `admin.seekmodo.com`, so an in-place file deploy does
NOT change classify behaviour.

### v1.0.5

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
