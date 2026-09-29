# Seekmodo for Zen Cart v1.3.91

## v1.3.91 - 2026-09-29 (SERP sort header label)

### Fixed
- **SERP sort header shows Relevanz only when relevance is active** —
  the Relevance menu injector no longer prepends Relevanz/Relevance
  into pagination or header language/currency menus that merely
  preserve a single `sort=2a` (or similar) in every link. Only real
  sort menus (2+ distinct `sort=` option values) get the Relevance
  choice, and the active-sort toggle rewrite is scoped to those
  menus. After choosing Artikelname / Product Name, the theme label
  stays visible and Relevanz no longer leaks into the listing header
  or top bar. Ticket #615985 (Cannapot).
