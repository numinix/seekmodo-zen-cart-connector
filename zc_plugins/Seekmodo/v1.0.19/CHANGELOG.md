# Seekmodo Zen Cart connector — v1.0.19 changelog

## Summary

v1.0.19 ships **category landing-page redirect** (search-features-plan
Sprint 6 PR 1) — Klevu / Algolia parity for the navigational-intent
slice of storefront search traffic.

When a shopper's query closely matches a single storefront category,
the connector now 302's the request to that category landing page
instead of rendering an `advanced_search_result` SERP. The intuition
is that a shopper typing **"personalised mugs"** almost certainly
wants the *Personalised Mugs* category landing page (with its full
filter rail, editorial copy, pagination, sort options) rather than a
thin SERP fragment that happens to surface the same products.

## What changed

### NEW: `catalog/includes/functions/numinix_seekmodo_category_redirect_lib.php`

The resolver. Pure-PHP, no gateway round trip:

1.  Pulls the tenant's active-category list (`categories_id` +
    `categories_name` + `parent_id`) once per hour into APCu, with
    pre-normalised comparison forms.
2.  Normalises the inbound query the same way (lowercase,
    UK→US-spelling fold, ampersand fold, ASCII-punctuation strip,
    single-space collapse, English stopword drop, plural-`s` clip on
    tokens >3 chars).
3.  Scores each category in a tiered tournament:
    - **1.00** exact normalised match (token order ignored)
    - **0.95** all query tokens present in category name
    - **0.90** all category tokens present in query
    - **0.80–0.92** `similar_text()` percent (capped so fuzzy never
      beats a token-set hit)
4.  Picks the best score above the configurable floor
    (`NUMINIX_SEEKMODO_CATEGORY_REDIRECT_MIN_SIMILARITY`, default
    `0.92`), only if it clears the runner-up by the configurable gap
    (`NUMINIX_SEEKMODO_CATEGORY_REDIRECT_CLEAR_WINNER_GAP`, default
    `0.05`).
5.  Builds the cPath chain (leaf → root) and returns the
    `zen_href_link(FILENAME_DEFAULT, 'cPath=…')` URL.

Per-query result is also APCu-cached for 5 minutes so a shopper
clicking pager links on a redirected category page doesn't
re-resolve.

### MODIFIED: `catalog/includes/init_includes/init_numinix_seekmodo.php`

Adds `numinix_seekmodo_category_redirect_lib.php` to the catalog
eager-load list between the typeahead lib and the indexer lib. The
file is internally gated on
`NUMINIX_SEEKMODO_CATEGORY_REDIRECT_ENABLED`, so eager-load adds no
measurable cost when the feature is off.

### MODIFIED: `catalog/includes/classes/observers/NuminixSeekmodoObserver.php`

Hooks `NOTIFY_HEADER_START_ADVANCED_SEARCH_RESULTS` — the very first
line of `includes/modules/pages/advanced_search_result/header_php.php`.

The handler:

1.  Reads `$_GET['keyword']`.
2.  Skips redirect when any structured narrowing filter is set
    (`categories_id`, `manufacturers_id`, `pfrom`, `pto`, `dfrom`,
    `dto`, `inc_subcat`) — those signal the shopper has already
    narrowed intent and a redirect would silently drop their filters.
3.  Calls `numinix_seekmodo_resolve_category_redirect($keyword)`.
4.  On a non-null URL: issues a 302 `Location` header and `exit`s
    before any of Zen Cart's search SQL build runs.

Falling out of the page this early also means:

- the per-tenant `numinix_seekmodo_run_search()` call the swap-point
  would have made is skipped → saves a gateway round trip and a
  billed search row when the shopper's intent was navigational.
- the SERP impression beacon from
  `NOTIFY_HEADER_END_ADVANCED_SEARCH_RESULTS` doesn't fire → keeps
  the impression stream clean of "shopper never saw this SERP" rows.

### MODIFIED: `Installer/ScriptedInstaller.php`

Adds three configuration rows (sort orders 115–117) and the matching
`executeUninstall()` cleanup:

| Key                                                       | Default | Purpose                                  |
| --------------------------------------------------------- | ------- | ---------------------------------------- |
| `NUMINIX_SEEKMODO_CATEGORY_REDIRECT_ENABLED`              | `true`  | Per-tenant kill-switch                   |
| `NUMINIX_SEEKMODO_CATEGORY_REDIRECT_MIN_SIMILARITY`       | `0.92`  | Score floor a winner must clear          |
| `NUMINIX_SEEKMODO_CATEGORY_REDIRECT_CLEAR_WINNER_GAP`     | `0.05`  | Min gap between best and second-best     |

All three are mirrored from `tenant.snapshot.category_redirect_*` by
`RemoteConfig::writeThrough`, so an operator can flip the feature off
or retune the thresholds from `admin.seekmodo.com` without a
redeploy.

## Telemetry

When the resolver returns a hit AND the connector's `DEBUG` flag is
on, a structured row is appended to `logs/numinix_seekmodo.log`:

```json
{"ts":"2026-06-11T05:42:18+00:00","event":"category_redirect",
 "q":"personalised mugs","norm_q":"personalized mug",
 "category_id":225,"category_name":"All Personalised Mugs",
 "score":1.0,"second_best":0.95,"gap":0.05,"language_id":1}
```

Once gateway-side `redirects_category` metering lands (sprint 6 PR
2), this row will be mirrored as an `/v1/events` envelope so
`admin.seekmodo.com → Usage` can chart the per-tenant redirect rate
alongside searches.

## Safety posture

- **No gateway round trip** — the resolver is pure SQL + APCu, so a
  gateway outage cannot break the feature.
- **Conservative defaults** — the 0.92 floor + 0.05 gap means a
  storefront with overlapping category names ("Mugs", "Mugs For Her",
  "Mugs For Him") will NOT redirect on the bare query "mugs"
  unless the operator lowers the floor.
- **Structured-filter bailout** — see above; a categorised /
  manufacturer-scoped / price-narrowed search never silently
  navigates away.
- **Exit-on-headers-sent fallback** — if a downstream notifier ever
  fires output before this hook (unusual on stock Zen Cart), the
  handler emits a JS `window.location.href = …` instead of throwing
  a "headers already sent" warning.

## Connector environment self-diagnostics (additive)

A merchant-facing addition to the **Tools → Connect to Seekmodo**
admin page. Surfaces the host's PHP environment so a self-hosted
operator can self-serve the most common causes of degraded mode
(missing extensions, disabled OPcache, locked-domain split-brain)
without contacting support.

### NEW: `catalog/includes/library/Numinix/Seekmodo/EnvProbe.php`

A 200-line read-only helper. Two callable surfaces:

1.  `EnvProbe::current(): array` — snapshots PHP version, SAPI, and
    a fixed list of extension-load flags (`sodium_loaded`,
    `apcu_loaded`, `apcu_extension`, `opcache_enabled`,
    `curl_loaded`, `openssl_loaded`, `mysqli_loaded`,
    `intl_loaded`, `json_loaded`) plus the connector's
    `server_time_unix`. Pure PHP, no I/O.
2.  `EnvProbe::diagnostics(?array $env = null): array` — turns the
    raw map into a list of `{label, value, severity, hint}` rows
    keyed to four severity tiers (`ok`, `warn`, `fail`, `info`).
    Hints are one-liner copy-paste install commands keyed to the
    host's PHP version (so an `ea-php74` host gets
    `ea-php74-pecl-apcu`, an `ea-php82` host gets
    `ea-php82-pecl-apcu`).

A third helper, `EnvProbe::lockedDomainStatus()`, compares
`NUMINIX_SEEKMODO_LOCKED_DOMAIN` to `HTTPS_CATALOG_SERVER` and
returns the same severity-tagged shape — surfacing the
"locked domain doesn't match the storefront's actual host" case
that previously caused silent fallback to native search.

PHP 7.4-compatible (no `mixed`, `readonly`, enums, or constructor
property promotion) — same posture as the rest of v1.0.19 after
the back-port. Tested by `tests/V1019EnvProbeTest.php` for row
shape, severity classification, and PHP-version-aware install
package names.

### MODIFIED: `admin/numinix_seekmodo_connect.php`

Two new sections, both rendered only when the storefront is paired:

1.  **APCu warning banner** (`msg-warn`, yellow). Mirrors the
    existing sodium error block but as a recommendation rather
    than a blocker — APCu missing means the connector reaches
    `mcp.seekmodo.com` on every admin render and every storefront
    search burst (instead of riding a 5-min cache), causing brief
    "gateway unreachable" flickers and slightly slower search.
    Pairing and search still work.

    Three install paths in collapsible `<details>` so the banner
    stays compact: cPanel/EasyApache `yum install -y
    ea-phpXY-pecl-apcu` + WHM Multi PHP INI Editor instructions,
    Debian/Ubuntu `apt-get install -y phpX.Y-apcu`, and a copy-
    paste-ready support-ticket template (with one-click
    `mailto:` button) for managed/shared-host merchants who can't
    `yum` anything themselves.

2.  **Diagnostics panel** under the Last 5 transitions table.
    Renders the full `EnvProbe::diagnostics()` table plus the
    locked-domain row. Color-coded check / warn / cross icons
    matching severity, with each row's hint shown inline so the
    fix is one click away.

The CSS additions (`.msg-warn`, `.diag-table`, `.sev-*`) are
self-contained and do not perturb the existing snapshot card or
transitions table styling.

### MODIFIED: `catalog/includes/library/Numinix/Seekmodo/RemoteConfig.php`

`RemoteConfig::push()` now merges the EnvProbe map into the FSM
push payload under the `env` key on every call. The gateway
persists this so `admin.seekmodo.com` operators can spot tenants
running with degraded environment (APCu missing, OPcache disabled,
old PHP) before the merchant notices.

Forward-compatible with pre-env gateways: `Store::pushSnapshot`
is a per-key allowlist, so a gateway running the older v8 schema
silently ignores the `env` field and the push still records the
FSM state. No flag day required for the connector ↔ gateway
roll-out — they can land independently in either order.

## Carries over from v1.0.18

- Stable signing key `seekmodo-2026-06` vendored in
  `admin/release-signing.pub` — the in-plugin auto-updater verifies
  signed zips with a real ed25519 root for the first time. No further
  changes in v1.0.19 on the signing-key path.
