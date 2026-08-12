# Seekmodo for Zen Cart v1.3.57

## 2026-08-12 - Catalog push duration TypeError fix

- **`record_indexer_run()` no longer fatals on PHP 7.1+.**
  `(int) $ms / 1000` cast only the millisecond delta, then divided —
  leaving a float for the `int $durationS` parameter. Cast after
  dividing (and round) so a successful full push can persist run
  metadata without `TypeError`.

## Prior (v1.3.56)

- Keyset-paginated catalog scan to avoid queryFactory OOM on mid-size catalogs.
