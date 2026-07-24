# Seekmodo for Zen Cart v1.3.30

## 2026-07-24 — cart support_count ranking

- **Cart cascade** — multi-line carts rank by `support_count` across up
  to 10 anchors (`meta.anchor_cap`), then score and source priority.
  `rejectColdStartSources` on also_bought for cart and PDP bought.

## 2026-07-24 — PDP/cart recommendation cascades

- **PDP/cart cascades** — adds `pdp-cascade` and `cart` placements that
  compose gateway `recommend.*` / `bundle.suggest` with cross-section
  de-dupe and in-cart excludes (AKS `RecommendationsAdapter` parity).
  `NuminixSeekmodoObserver` injects one cascade container on product_info
  and shopping_cart; `jscript_seekmodo_recommendations.js` renders
  multi-section strips and soft-refreshes on cart AJAX.
- **RecommendationsCascade** — new library class under
  `Numinix\Seekmodo\RecommendationsCascade`.
- Legacy single-algo placements (`pdp-related`, etc.) remain supported.

## 2026-07-22 — suggest observer docblock parse fix (from v1.3.29)

- **SuggestObserver parse error** - the `suggestLabels()` docblock in
  `NuminixSeekmodoSuggestObserver.php` used the path
  `languages/*/extra_definitions`, and the `*/` prematurely closed the
  `/**` comment. Path now uses `languages/{lang}/extra_definitions`.
