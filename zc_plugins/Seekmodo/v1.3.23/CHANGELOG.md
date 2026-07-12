# v1.3.23 (2026-07-12)

## Fixed

- **Fleet-health SERP click tracking on Numinix typed PDPs** - observer now
  listens for `NOTIFY_HEADER_START_SERVICE_PRODUCT_INFO`,
  `DOWNLOAD_PRODUCT_INFO`, `DOCUMENT_PRODUCT_INFO`,
  `PRODUCT_MUSIC_INFO`, and `PRODUCT_FREE_SHIPPING_INFO` in addition to
  stock `NOTIFY_HEADER_START_PRODUCT_INFO`. Without these, SERP?PDP click
  mirroring never ran on www.numinix.ca SEO product URLs
  (`body=serviceproductinfoBody`).
- Retains the v1.3.22 SEO slug `pidFromHref` pattern in the SERP JS beacon
  (`/product-name-902`) so sendBeacon fires before navigation.
