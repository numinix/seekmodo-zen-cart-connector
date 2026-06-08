# Seekmodo for Zen Cart — top-level changelog

This file tracks what's in the **latest** released zip. The full
per-version detail lives next to the source under
`zc_plugins/Seekmodo/v<X.Y.Z>/CHANGELOG.md`.

## v1.0.17 — 2026-06-08

- **AKS-connector parity port (generic improvements only).** Two
  features lifted from the AKS connector v1.3 (`numinix/aks-seekmodo-connector`,
  2026-06-07) that aren't AKS- or vehicle-specific. Both are
  additive and backwards-compatible — every existing tenant's
  payload shape is unchanged in the no-trigger case.

  1. **SKU / part-number exact-match boost** (port of AKS
     Sprint 2's `EzNumberBooster`). Shopper queries that look
     like a single-token SKU / part number (alphanumeric +
     dashes/underscores/dots, 2-32 chars by default) now set
     `prioritize_exact_match=true` on the gateway call so the
     exact-SKU product floats to position 0 regardless of
     textual relevance scoring. Multi-word natural-language
     queries are unaffected. Configurable via the new
     `NUMINIX_SEEKMODO_SKU_BOOST_ENABLED` (default `true`) and
     `NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX`. Applies to the
     full-search path AND the legacy `/v1/search`-based typeahead
     fallback.

  2. **Expanded tenant-unavailable graceful degradation** (port
     of AKS v1.3's `Client::classifyByErrorCode()`). The
     storefront has always fallen back to native Zen Cart `LIKE`
     search on a `403 tenant_paused`; v1.0.17 expands the
     recognised lifecycle vocabulary to also cover
     `tenant_not_found`, `tenant_unknown`, `tenant_suspended`,
     `tenant_disabled`, and applies the body peek to **both**
     403 and 404 responses (the gateway emits 404 for
     `tenant_not_found` / `tenant_unknown`, 403 for the rest).
     Behaviourally the fallback to native search is unchanged —
     `Client::call()` returns `null` on every 4xx exactly as
     before — but the structured log line now distinguishes
     `tenant_unavailable` (with `fallback_reason =
     tenant_unavailable`) from the generic `caller_error` so
     admin observability can attribute the volume correctly.

  Full per-version detail: [`zc_plugins/Seekmodo/v1.0.17/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.17/CHANGELOG.md).

## v1.0.14 — 2026-06-04

- **Typeahead routes through the gateway's SuggestTool (Sprint 3 PR 6).**
  v1.0.13 packed every typeahead keystroke into a `/v1/search` payload
  so autocomplete impressions counted against the same metering bucket
  as full-search SERPs. v1.0.14 swaps the default path to the
  gateway's dedicated `SuggestTool` at `/v1/suggest`, which returns
  three result blocks in one round-trip (keywords / products /
  categories), meters the call against the new `searches_suggest`
  display bucket separately from `searches_text`, and short-circuits
  scraper keystroke storms via the same bot-gate the SERP uses.

  Ships a drop-in client-side JS handler (`jscript_seekmodo_typeahead.js`,
  150ms debounce, vanilla DOM, no jQuery dep) plus a tenant-side AJAX
  endpoint (`catalog/numinix_seekmodo_suggest.php`) so unmodified
  storefronts can opt into Seekmodo-driven typeahead without editing
  their own search templates. Sites on a custom template need to copy
  the JS file into their own template's `jscript/` folder — Zen Cart
  doesn't auto-inherit `jscript_*.js` from `template_default`.

  Operators can roll back to the v1.0.13 `/v1/search` typeahead path
  per-call (`opts.use_search=true`) or globally
  (`NUMINIX_SEEKMODO_TYPEAHEAD_USE_SEARCH=true`) for the cutover
  window. Form-submit behaviour is intentionally unchanged — the
  SERP still routes through `numinix_seekmodo_run_search()`.

  Full detail in `zc_plugins/Seekmodo/v1.0.14/CHANGELOG.md`.

## v1.0.12 — 2026-06-02

- **Static `.well-known/mcp.json` writer (Sprint 14 PR 4 follow-up,
  2026-06-02).** v1.0.11's PHP-driven `.well-known/mcp.json`
  interceptor required an `.htaccess` rewrite that doesn't ship in
  stock Zen Cart and isn't reachable at all when the storefront is
  installed in a `/catalog/` subdirectory (the root-level redirect
  catches the URL before PHP sees it). v1.0.12 fixes both cases by
  physically writing a real `.well-known/mcp.json` file (plus a
  defence-in-depth `<Files "mcp.json"> Require all granted </Files>`
  `.htaccess`) to **every viable docroot the connector can
  resolve** — `DIR_FS_CATALOG`, `$_SERVER['DOCUMENT_ROOT']` when
  distinct, and the parent of `DIR_FS_CATALOG` as a CLI fallback.
  Apache serves the resulting file directly; no rewrite required.
  Triggers: pair callback (immediate on Connect), `RemoteConfig::
  writeThrough` (every snapshot poll), and an APCu-gated
  once-per-hour refresh from the head observer so any already-paired
  storefront self-heals on the next page render.
- Idempotency: the writer reads existing on-disk content and skips
  the write when it matches the canonical payload. Safe to call on
  every storefront request; ~free when nothing has changed.
- Failure posture unchanged from v1.0.11 — every code path is
  wrapped in try/catch, the writer NEVER throws to its caller, and
  a writer failure does NOT block pairing or 500 a storefront page.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.12/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.12/CHANGELOG.md).

## v1.0.11 — 2026-06-02

- **Public-MCP (anonymous-tier) discovery for AI agents (Sprint 14 PR 4).**
  Two new discovery surfaces let third-party AI agents (ChatGPT,
  Claude Desktop, Cursor, etc.) find the storefront's product-search
  MCP endpoint at `https://<tenant_id>.mcp.seekmodo.com/mcp` without
  any merchant intervention:

  - **`/.well-known/mcp.json`** — a small JSON discovery document
    served by a new early-init interceptor
    (`catalog/includes/init_includes/init_numinix_seekmodo_well_known.php`,
    registered at `autoLoadConfig[60]`). Advertises the gateway
    endpoint, the `search` tool, the per-IP / per-(tenant,IP) rate
    limits, and a link to the operator runbook. Requires a one-line
    `.htaccess` rewrite (`RewriteRule ^\.well-known/mcp\.json$ index.php [L,QSA]`)
    on stock Zen Cart docroots; falls through cleanly when missing.
  - **`<link rel="mcp-server">` + `<meta name="mcp-server">`** —
    injected into every storefront page's `<head>` via a new
    `NOTIFY_HTML_HEAD_END` observer
    (`NuminixSeekmodoMcpDiscoveryObserver`). No web-server config
    required; works on stock Zen Cart 1.5.8 / 2.0 unmodified.

  Both surfaces emit only when the connector is enabled
  (`numinix_seekmodo_enabled()` true — i.e. paired, mode != off,
  not domain-locked-out) and silently no-op otherwise. Every code
  path is wrapped in `try/catch` — a discovery failure NEVER 500s a
  storefront page.

Full per-version detail: [`zc_plugins/Seekmodo/v1.0.11/CHANGELOG.md`](zc_plugins/Seekmodo/v1.0.11/CHANGELOG.md).

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
