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

## Carries over from v1.0.18

- Stable signing key `seekmodo-2026-06` vendored in
  `admin/release-signing.pub` — the in-plugin auto-updater verifies
  signed zips with a real ed25519 root for the first time. No further
  changes in v1.0.19 on the signing-key path.
