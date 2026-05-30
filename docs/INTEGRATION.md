# Seekmodo connector — storefront integration

This document is the integration contract for developers wiring the
Seekmodo connector into a storefront. The connector ships with sensible
defaults for stock Zen Cart, but production tenants almost always need
to wire a handful of integration points specific to their theme and
plugin stack.

Audience: PHP integrators landing the connector on a new Zen Cart store
(or on a non–Zen Cart platform, where the patterns here translate
one-for-one to whatever swap-points that platform exposes).

## Touch-points

The connector exposes **four** swap-points. Each is opt-in — the
connector is fully self-contained inside the plugin tree and a
storefront only has to invoke as many helpers as it actually needs.

| #   | Surface                  | What the storefront does | Connector helper called |
|-----|--------------------------|--------------------------|--------------------------|
| 1   | Full-page search (SERP)  | Conditionally route the existing Typesense call through the gateway. | `numinix_seekmodo_run_search($params)` |
| 2   | Indexer cron             | Conditionally route the bulk-upsert through the gateway. | `numinix_seekmodo_run_bulk_upsert($docs, $collection)` |
| 3   | Click beacon             | Mirror a click event to the gateway after writing the local row. | `numinix_seekmodo_mirror_click($kw, $pid, $pos, $bot, $opts)` |
| 4   | Type-ahead / autocomplete | Conditionally route the AJAX autocomplete call + log clicks. | `numinix_seekmodo_run_typeahead($q, $max)` + `numinix_seekmodo_mirror_typeahead_click(...)` |

Each helper short-circuits to `null` (or no-op) when the connector is
disabled (`MODE=off`, breaker open, or credentials missing), so a
storefront with all four wired falls back to its native code path on
its own — no orchestration required from the integrator.

## Boot order

`init_numinix_seekmodo.php` runs at `autoLoadConfig[80]` (after
`configure.php`, before any storefront code that reads the
`NUMINIX_SEEKMODO_*` constants). It registers the autoloader and
eager-loads all five procedural helper files:

```
numinix_seekmodo_client.php       # numinix_seekmodo_enabled / mode / SDK wrappers
numinix_seekmodo_search_lib.php   # numinix_seekmodo_run_search + filter registry
numinix_seekmodo_typeahead_lib.php # numinix_seekmodo_run_typeahead
numinix_seekmodo_indexer_lib.php  # numinix_seekmodo_run_bulk_upsert
numinix_seekmodo_events_lib.php   # mirror_click / mirror_typeahead_click / mirror_impression
```

Integrators almost never need to look at these directly — call the
documented entry points and trust the boot order.

## Per-tenant configuration

Two flavors of configuration ship with the connector:

1. **Static config** — six rows in Zen Cart's `configuration` table,
   created by the ScriptedInstaller. These hold the gateway URL, tenant
   ID, shared secret, mode, and a few tunables. They're managed via
   `admin.seekmodo.com`; the local Zen Cart admin shows a read-only
   snapshot.

2. **Runtime registration** — code the storefront's init include (or
   theme bootstrap) calls *once per request* to declare which sidebar
   filter parameters map to which Typesense fields. See
   [FILTERS.md](FILTERS.md). This is the only piece of integration that
   varies meaningfully tenant-to-tenant within the same platform.

## Versioning + upgrade safety

Plugin directories are immutable per release (`v1.0.0/`, `v1.0.1/`,
`v1.0.2/`, `v1.0.3/`, …). The Zen Cart Plugin Manager activates
exactly one version at a time; the storefront swap-points call helpers
through `function_exists()` so an out-of-date storefront landed against
a newer connector still degrades cleanly.

When the connector ships a new public helper, the rule is:

- **Adding helpers** is fine — old callers don't know about them.
- **Adding optional arguments** to existing helpers requires that the
  new argument has a non-breaking default (matching the v1.0.2 call
  shape).
- **Renaming** a helper is forbidden; ship a new helper and leave the
  old one as a thin alias.
- **Bumping behavior** of an existing helper (e.g. changing the return
  shape) requires a major-version bump and a deprecation period.

The v1.0.3 release exercises rule #2: `numinix_seekmodo_mirror_click()`
gains a fifth argument `$opts = []`. Callers that haven't been
re-deployed still work; new callers can pass `['surface' => 'typeahead']`
to tag the event.

### Activating a new version on a Zen Cart tenant

Zen Cart's Plugin Manager loads the version recorded in
`plugin_control.version`, **not** the highest version present on disk.
Shipping a new `zc_plugins/Seekmodo/v1.0.X/` tree alone is not enough —
the storefront will keep loading the version Plugin Manager has
recorded, and any new helpers in `vX/` will be invisible. This is
easy to miss on tenants whose tree-update mechanism is a generic git
pull (no admin click), and shows up as "I deployed the new code but
nothing changed."

Symptoms we've hit: connector v1.0.3 added gateway payload tuning that
went silent on production until `plugin_control.version` was bumped
from `v1.0.0`; the storefront kept loading v1.0.0's
`_numinix_seekmodo_build_search_payload()` (no `typo_tokens_threshold`
passthrough) and `keyword=automotive rotisserie` returned 9 hits
instead of the 177 the storefront's admin-tuned constants should
yield.

For each Zen Cart tenant, after a connector tree update, ensure the
following is also true:

```sql
-- Confirm
SELECT unique_key, version, status FROM plugin_control
WHERE unique_key='Seekmodo';

-- Bump if needed (idempotent)
UPDATE plugin_control
   SET version='v1.0.<latest>', status=1
 WHERE unique_key='Seekmodo';

INSERT INTO plugin_control_versions
   (unique_key, author, version, zc_versions, infs)
VALUES
   ('Seekmodo', 'Numinix', 'v1.0.<latest>', '["v158"]', 1)
ON DUPLICATE KEY UPDATE
   author=VALUES(author),
   zc_versions=VALUES(zc_versions),
   infs=1;
```

`tools/install_redline_connector.py` does the right thing already (its
`BOOTSTRAP_SQL` block uses `ON DUPLICATE KEY UPDATE ... version =
'{PLUGIN_VERSION}'`); re-running that tool after a tree update is the
preferred path. If only the connector file tree was deployed via the
tenant's git auto-deploy, run the SQL above (or re-run
`install_redline_connector.py`) to sync Plugin Manager. Don't forget
to clear PHP-FPM's opcache after the bump so the new auto_loader path
is rescanned (`curl` a small `opcache_reset()` endpoint, or restart
PHP-FPM).

## Cross-platform notes

This connector is Zen Cart 1.5.8+ today. The patterns translate
directly to other platforms; see [PLATFORM_NOTES.md](PLATFORM_NOTES.md)
for known caveats per platform. The four documented swap-points are the
abstract integration contract — every connector we publish (Zen Cart
today, WooCommerce / Shopify / custom Laravel storefronts later) wires
the same four hooks in whatever the platform's idiomatic location is.

## Further reading

- [FILTERS.md](FILTERS.md) — sidebar filter wiring
- [TYPEAHEAD.md](TYPEAHEAD.md) — autocomplete wiring
- [CLICK_ATTRIBUTION.md](CLICK_ATTRIBUTION.md) — beacon + event model
- [PLATFORM_NOTES.md](PLATFORM_NOTES.md) — per-platform gotchas
- [../README.md](../README.md) — plugin install / deploy / mode flips
