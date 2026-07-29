# Seekmodo for Zen Cart v1.3.36

## 2026-07-28 - Log flood hardening (KIP)

- **CGI-safe CLI reject** - `admin/numinix_seekmodo_check_updates.php` and
  catalog CLI entry points (`push_catalog`, `index_delta`,
  `reconcile_cron`, `render_indexer_cron`) no longer call
  `fwrite(STDERR, …)` on the HTTP reject path. Under cgi-fcgi that
  emitted "undefined constant STDERR" / fwrite type warnings into Zen
  Cart `myDEBUG` / PHP error logs on every probe — the pattern that
  previously filled KIP production logs when bots hit Seekmodo cron
  wrappers (and `tools/seekmodo_index.php` before the site-side guard).
- **Rotated `numinix_seekmodo.log`** - shared
  `numinix_seekmodo_log_append()` caps the connector log at 32 MiB and
  rotates to `.1` so shadow telemetry cannot grow without bound.
- **Shadow push accounting** - standalone `push_catalog.php` no longer
  treats the swap-point `null` return in `MODE=shadow` as a failed
  batch (that contract means "observation done, fall through" for
  native Typesense indexers). Nightly shadow crons stop flooding
  indexer logs with false WARN lines.

## 2026-07-28 - Push catalog open_basedir (from v1.3.35)

- **Trust NUMINIX_SEEKMODO_PHP_BINARY under open_basedir** - shared hosts
  often block `is_file('/opt/cpanel/...')` while `shell_exec` can still
  run that CLI.
