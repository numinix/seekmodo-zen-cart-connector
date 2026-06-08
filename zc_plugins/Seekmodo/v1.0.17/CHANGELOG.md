# Seekmodo Zen Cart connector — v1.0.17 changelog

## Summary

v1.0.17 ports two generally-applicable improvements from the AKS
connector (`numinix/aks-seekmodo-connector` v1.3, 2026-06-07) into the
Zen Cart connector. Both are additive and backwards-compatible: every
existing tenant's payload shape is unchanged in the no-trigger case,
and every existing fallback path keeps its current behaviour. The
defaults are tuned so an in-place file deploy from v1.0.16 → v1.0.17
is safe even before the operator has flipped any new constants.

The two ports:

1. **SKU / part-number exact-match boost** (port of AKS Sprint 2's
   `Numinix\AksSeekmodo\Search\EzNumberBooster`). When the shopper's
   query looks like a single-token product code (alphanumeric +
   dashes / underscores / dots, 2-32 chars, leading letter or digit),
   the connector now sets `prioritize_exact_match=true` on the
   gateway call so an exact match on a SKU-bearing field jumps to
   position 0 regardless of textual relevance scoring. Multi-word
   natural-language queries are unaffected — they still rank by
   relevance through Typesense's commerce-vertical defaults. Applies
   to both the full-search path
   (`_numinix_seekmodo_build_search_payload()`) and the legacy
   `/v1/search`-based typeahead fallback
   (`_numinix_seekmodo_typeahead_via_search()`); the SuggestTool
   path doesn't accept `prioritize_exact_match` so it's left alone.

2. **Expanded tenant-unavailable graceful degradation** (port of
   AKS v1.3's `Client::classifyByErrorCode()`). The Client used to
   recognise three legacy lifecycle codes (`tenant_paused`,
   `subscription_cancelled`, `unknown_tenant`) on 403 responses
   only. v1.0.17 expands the recognised set to cover the full
   gateway lifecycle vocabulary (`tenant_paused`, `tenant_not_found`,
   `tenant_unknown`, `tenant_suspended`, `tenant_disabled`) and
   peeks at the body on **both** 403 and 404 status codes so
   gateway responses like `404 {"error":"tenant_not_found"}` no
   longer fall through to the bare 4xx logger without lifting the
   admin "Account paused" notice. Behaviourally the storefront keeps
   serving the shopper out of native Zen Cart `LIKE` search either
   way (`Client::call()` returns `null` on every 4xx, exactly as
   before) — the v1.0.17 change is the structured log line, which
   now distinguishes `tenant_unavailable` (with `fallback_reason =
   tenant_unavailable`) from the generic `caller_error` so admin
   observability can attribute volume correctly.

## Details

### SKU / part-number exact-match boost

#### What changed

- New helper `_numinix_seekmodo_apply_sku_boost(array $payload,
  string $keyword): array` in
  `catalog/includes/functions/numinix_seekmodo_search_lib.php`.
  Returns a new payload (never mutates input). No-op when the
  master switch is off, the keyword is empty, the trigger regex
  doesn't match the trimmed query, or the caller has already set
  `prioritize_exact_match` on the payload.
- `_numinix_seekmodo_build_search_payload()` calls the helper just
  before returning so every full-search call goes through the
  booster.
- `_numinix_seekmodo_typeahead_via_search()` calls the helper on
  the slim typeahead-via-search payload too. The default
  SuggestTool path is untouched — `/v1/suggest` doesn't accept
  `prioritize_exact_match` (Typesense applies prefix matching with
  its own commerce-vertical defaults at the suggest tier).

#### New configuration constants

| Key | Default | Notes |
|---|---|---|
| `NUMINIX_SEEKMODO_SKU_BOOST_ENABLED` | `'true'` | Master switch. `'false'` disables the helper across every call site. |
| `NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX` | `/^[A-Za-z0-9][A-Za-z0-9_\-\.]{1,31}$/` | Regex (with delimiters) the trimmed query is matched against. A malformed regex is treated as a no-op so a typo here cannot break the storefront. |

Both keys are written by `Installer\ScriptedInstaller::executeInstall()`
on a fresh install AND back-filled idempotently on upgrade. They're
managed locally — the gateway's `tenant.snapshot` doesn't (today) own
either key, but the `RemoteConfig::writeThrough()` writeable key list
can be extended in a future minor if cross-tenant tuning emerges as a
need.

#### Why a generic helper, not a hard-coded boost

The AKS connector's EzNumberBooster has additional logic for
`FilterSpec`-driven sub-search filter modes (`?search_filter=oem|fccid|
competitor|remote`) that don't apply on a generic Zen Cart catalogue.
The v1.0.17 helper drops the FilterSpec branch (Zen Cart doesn't have
that surface) and keeps only the trigger regex + `prioritize_exact_match`
emission, which is the part that's portable.

### Expanded tenant-unavailable graceful degradation

#### What changed

- `Client.php` adds the `TENANT_UNAVAILABLE_ERROR_CODES` constant
  (mirrors the AKS connector's
  `ClientException::TENANT_UNAVAILABLE_ERROR_CODES`):
  ```php
  public const TENANT_UNAVAILABLE_ERROR_CODES = [
      'tenant_paused',
      'tenant_not_found',
      'tenant_unknown',
      'tenant_suspended',
      'tenant_disabled',
      'subscription_cancelled', // legacy
      'unknown_tenant',         // legacy
  ];
  ```
- The 4xx branch of `Client::call()` peeks at the body on both
  403 and 404 (was: 403 only) and matches against the full code
  list (was: hard-coded three-code if/else). Match → set
  `markSubscriptionState(SUB_STATE_CANCELLED)` (existing behaviour)
  and emit a `tenant_unavailable` log line carrying `error_code` and
  `fallback_reason='tenant_unavailable'`. Miss → `caller_error` log
  line as before. The function-level return remains `null` in
  every 4xx case so caller fallback semantics don't change.

#### Compatibility

- The legacy two-code list (`tenant_paused`, `subscription_cancelled`,
  `unknown_tenant`) is retained at the head of the new constant so
  any tenant whose admin UI was already detecting the cancellation
  state via APCu keeps reading the same `SUB_STATE_CANCELLED` flag.
- Behaviourally the storefront keeps falling back to native Zen
  Cart `LIKE` search on every 4xx — that hasn't changed since
  v1.0.0. v1.0.17 only changes which subset of 4xx responses
  additionally light up the admin "Account paused" banner.

## Tests

- `tests/Sprint17TenantUnavailableTest.php` — pins:
  - 403 + each of the seven recognised codes flips
    `numinix_seekmodo_subscription_state()` to `'cancelled'`.
  - 404 + `tenant_not_found` AND 404 + `tenant_unknown` also flip
    the state (regression for the v1.0.16 status-code-restricted
    behaviour).
  - 403 + `signature_mismatch` does NOT flip the state (so a real
    config bug is not silently masked as a paused tenant).
  - 403 with malformed body / oversized body / non-array body
    does NOT flip the state and never throws.
- `tests/Sprint17SkuBoostTest.php` — pins:
  - SKU-shape queries (`STD-1234`, `EZ-LK99`, `12345`, `RLS_99`)
    set `prioritize_exact_match=true` on the payload.
  - Multi-word queries (`automotive rotisserie`,
    `motorcycle stand`) leave the payload unchanged.
  - Empty / whitespace-only queries are no-ops.
  - Disabled master switch produces a no-op even on a SKU-shape
    query.
  - A malformed override regex is treated as a no-op (storefront
    never 500s on a bad operator override).
  - Caller-set `prioritize_exact_match` is preserved (the helper
    doesn't second-guess explicit caller intent).

## Files changed

| Path | Why |
|---|---|
| `manifest.php` | Bump `pluginVersion` to `v1.0.17`; prepend changelog entry. |
| `catalog/includes/library/Numinix/Seekmodo/Client.php` | New `TENANT_UNAVAILABLE_ERROR_CODES` constant; expanded 4xx body peek across 403 + 404; structured `tenant_unavailable` log line. |
| `catalog/includes/functions/numinix_seekmodo_search_lib.php` | New `_numinix_seekmodo_apply_sku_boost()` helper; `_numinix_seekmodo_build_search_payload()` invokes it. |
| `catalog/includes/functions/numinix_seekmodo_typeahead_lib.php` | `_numinix_seekmodo_typeahead_via_search()` invokes the same helper for prefix-typed SKUs on the legacy typeahead path. |
| `Installer/ScriptedInstaller.php` | Two new configuration rows (`NUMINIX_SEEKMODO_SKU_BOOST_ENABLED`, `NUMINIX_SEEKMODO_SKU_BOOST_TRIGGER_REGEX`); cleaned up on uninstall. |

## Upgrade

In-place file deploy. The new configuration rows back-fill on the
next admin Plugin Manager → Upgrade tick (or on the next
`numinix_seekmodo_check_updates.php` cron tick if the operator runs
Sprint 4 auto-update). Storefronts that haven't yet applied the
upgrade keep their existing v1.0.16 behaviour exactly — every code
path the v1.0.17 changes touch is gated on a new constant or a new
trigger regex match.
