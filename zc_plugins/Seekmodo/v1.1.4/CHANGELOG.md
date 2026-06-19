# Seekmodo Zen Cart connector — v1.1.4 changelog

## v1.1.4 — shadow-mode LTR click attribution (2026-06-19)

Fixes zero click telemetry on shadow tenants where a competitor engine
(Klevu) renders the visible SERP while Seekmodo runs in observation mode
(KIP prod / `kip` tenant).

### Changes

- **`_numinix_seekmodo_shadow_finalize_context()`** — after a successful
  shadow gateway search, stash `search_event_id`, a `products_id → rank`
  session map, and emit a SERP impression (same linkage contract as
  enforce mode).
- **`NuminixSeekmodoObserver::onProductInfo()`** — attribute clicks when
  HTTP Referer is the local search-results page and a gateway keyword
  context exists, even if the clicked SKU is outside the shadow rank map
  (`surface=competitor_serp`, `position=0`).
- **`onSerpClickBeacon()`** — on `advanced_search_result`, delegate
  clicks on product links to `numinix_seekmodo_click.php` via
  `navigator.sendBeacon` (covers Klevu grids and `target=_blank` flows
  where Referer never reaches `product_info`).
- **`catalog/numinix_seekmodo_click.php`** — lightweight click endpoint
  for JS beacons (compare harness, competitor SERPs).

---

# Seekmodo Zen Cart connector — v1.1.3 changelog

## v1.1.3 — in-plugin Update test release (2026-06-17)

Test release to verify the Connect page **Update** button and signed
apply path on git-enabled tenants. No functional connector changes
beyond version bump.

---
