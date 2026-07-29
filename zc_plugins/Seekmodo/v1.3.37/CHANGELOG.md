# Seekmodo for Zen Cart v1.3.37

## 2026-07-29 - Suggest thumb + Push session (NS-26042 Cannapot)

- **Suggest thumbnails stay on catalog originals** — Image Handler
  `/images/cache/.../*.image.240x240.jpg` hydrate URLs that 403 (or
  otherwise fail) no longer replace working gateway/catalog
  `image_url` values. Matches the existing `bmz_cache` preference.
  Fixes the flash-then-broken-image suggest UI on Cannapot.
- **Force thumb reload keeps prior src on error** — when `img-ver`
  force-reloads a thumb, `onerror` restores the previously working
  src instead of leaving a broken icon permanently.
- **CLI Push closes session after bootstrap** — avoids MySQL
  `Commands out of sync` (2014) on `session_write_close` during long
  catalog `Execute` loops (NS-26042 Push / sessions.php fatals).

## 2026-07-28 - Log flood hardening (from v1.3.36)

- **CGI-safe CLI reject** - admin/catalog cron entry points no longer
  call `fwrite(STDERR)` on the HTTP reject path.
- **Rotated numinix_seekmodo.log** - 32 MiB cap with `.1` rotation.
- **Shadow push accounting** - standalone `push_catalog.php` treats
  shadow-mode `null` as observation-ok.
