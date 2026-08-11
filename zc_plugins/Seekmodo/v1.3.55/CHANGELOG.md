# Seekmodo for Zen Cart v1.3.55

## 2026-08-11 — Enhanced Native SERP + legacy suggest (vanilla + Reloaded)

- **Legacy typeahead endpoint on rewritten SERPs** — inject
  `window.SeekmodoSuggestEndpoint` (catalog-root shim URL) and stop
  resolving a page-relative `numinix_seekmodo_suggest.php` under paths
  like `/search/results.html` (404). Cache-bust legacy JS with
  `filemtime`.
- **EN typeahead** — multi-word AND matching (name/description/model/
  brand); empty matches return `ok:true` instead of `gateway_null`;
  raw `&` in product URLs (`zen_href_link` HTML entities decoded).
- **EN SERP uncapped** — WHERE-based listing SQL + real `COUNT(*)`
  (removes the accidental 48-result cap). Zen Cart pagination works
  across the full match set for vanilla and Reloaded storefronts
  running Mode=off / unpaid EN.
- **sortby codes** — Numinix Reloaded / SBM mapping (`3`=price high,
  `4`=price low, `5`=name, `8`=SBM sort order) applied to EN listing
  SQL and SEEK ID-list re-sorts.

