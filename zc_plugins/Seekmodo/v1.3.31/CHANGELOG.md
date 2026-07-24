# Seekmodo for Zen Cart v1.3.31

## 2026-07-24 — Connect Push catalog now

- **Push catalog now** — Tools → Connect exposes a button that forks
  `numinix_seekmodo_push_catalog.php` in the background so organic
  sign-ups can recover from an empty Typesense collection without
  SSH/CLI. Requires gateway mode Active or Learning (not off); use
  Refresh snapshot first if you just flipped mode on admin.seekmodo.com.
- Retains v1.3.30 PDP/cart recommendation cascades.

## 2026-07-24 — PDP/cart recommendation cascades (from v1.3.30)

- **PDP/cart cascades** — adds `pdp-cascade` and `cart` placements that
  compose gateway `recommend.*` / `bundle.suggest` with cross-section
  de-dupe and in-cart excludes (AKS `RecommendationsAdapter` parity).
