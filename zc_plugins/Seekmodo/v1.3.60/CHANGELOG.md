# Seekmodo for Zen Cart v1.3.60

## 2026-08-12 - Storefront suggest meta tags lost to language-pack BOM

- **UTF-8 BOM in catalog language packs no longer breaks seekmodo-suggest.**
  Including lang.numinix_seekmodo.php during NOTIFY_HTML_HEAD_END
  emitted a U+FEFF character into head. HTML treats that as
  non-whitespace text, implicitly closes head, and moves the
  following meta name=seekmodo:tenant|gateway|refresh|token
  tags into body. The web component only reads
  document.head.querySelector(...), so suggest failed with
  meta name=seekmodo:tenant is required even though the tags
  were present in the HTML source. Language packs are saved without
  BOM; all pack includes are wrapped in output buffering.

## Prior (v1.3.59)

- pidFromHref rejects category -c-N / cPath URLs so categories_id
  is not stamped as product_id on Klevu shadow LTR clicks.
