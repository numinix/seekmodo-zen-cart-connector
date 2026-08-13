# Version-cut checklist (Zen Cart connector)

Short practical gates before copying `zc_plugins/Seekmodo/v1.3.(n)` → `v1.3.(n+1)` or tagging a release.

## Do not ship while a behavioral fix is open

- Do **not** cut / publish `v1.3.(n+1)` while a behavioral fix PR for the current latest tree is still open or unmerged.
- Land the fix on the latest tree first (or cut the new version **from** the fixed tree after merge).

## Diff critical suggest paths

When copying a new version tree from the previous one, diff at least:

- `catalog/includes/functions/numinix_seekmodo_typeahead_lib.php` — image helpers (`numinix_seekmodo_is_no_picture_url`, `numinix_seekmodo_prefer_catalog_over_placeholder_suggest_image`, `numinix_seekmodo_suggest_product_image_url*`)
- `catalog/includes/classes/observers/NuminixSeekmodoSuggestObserver.php` — hydrate / `no_picture` force-upgrade JS

Confirm `no_picture.(gif|png|jpg|webp)` is still treated as a miss and never wins over catalog `products_image`.

## Run the no_picture regression smoke

```bash
php tests/test_no_picture_suggest_thumbs.php
```

The smoke discovers the highest `zc_plugins/Seekmodo/v1.3.*` tree automatically. Run it before tagging / releasing.

## Fix belongs in connector main

Never land KIP / demo-only overlays for this class of bug. The fix ships in `numinix/seekmodo-zen-cart-connector` so every tenant gets it.

## Post-release verify

On a host with empty or missing local `/images` (or missing Image Handler thumbs) but real catalog originals:

1. Hit suggest / `seekmodo_action=images` for products that have `products_image`.
2. Expect catalog original URLs (or empty string) — **never** `no_picture.gif` (or other `no_picture.*`) when a catalog image exists.
