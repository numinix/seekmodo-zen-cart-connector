# v1.3.9 — 2026-07-03

## Fixed

- **Suggest tab-switch thumbnails** — vendored `@seekmodo/web-components` v0.3.7
  loads product thumbnails eagerly, reloads stalled images on tab return
  (`scheduleThumbRecovery`, `_smrv` cache-bust), and emits
  `seekmodo-suggest:tab-visible` for connector listeners. The Zen Cart
  hydrator now force-repaints cached thumbs even when `img.src` already
  matches, fixing blank gray slots on Chrome/Windows after switching away
  and back while the suggest dropdown stays open.

## Added

- **Keyword merchandising redirects** — server-side 302 via
  `numinix_seekmodo_redirect_lib.php` before the auto category redirect
  resolver runs.
