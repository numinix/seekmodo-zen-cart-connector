# v1.3.8 — 2026-07-03

## Fixed

- **Suggest tab-switch thumbnails** — vendored `@seekmodo/web-components`
  v0.3.5–v0.3.6 loads product thumbnails eagerly and reloads stalled
  images when the browser tab becomes visible again. Superseded by v1.3.9
  which adds v0.3.7 plus hydrated-thumb force-repaint for Chrome/Windows.
