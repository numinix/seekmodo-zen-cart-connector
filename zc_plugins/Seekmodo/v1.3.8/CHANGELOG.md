# v1.3.8 — 2026-07-03

## Fixed

- **Suggest tab-switch thumbnails** — vendored `@seekmodo/web-components` v0.3.3
  loads product thumbnails eagerly and reloads any stalled images when the
  browser tab becomes visible again, fixing blank gray thumb slots after
  switching away and back while the suggest dropdown stays open.
