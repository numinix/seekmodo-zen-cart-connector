# Seekmodo for Zen Cart v1.3.32

## 2026-07-27 — HMAC auth fallback classification

- **Auth misconfig native fallback** — gateway `auth_fail` /
  `signature_mismatch` responses (401/403, in either the `error` or
  `reason` JSON field) are now classified as `auth_misconfig` with
  `fallback_reason = auth_misconfig` in structured client logs. Behaviour
  is unchanged (`Client::call()` still returns `null` → native search);
  only observability and docblocks are corrected so pairing/HMAC drift
  is attributed like `tenant_unavailable`, not lumped into `caller_error`.
  Mirrors AKS connector `KIND_AUTH_MISCONFIG` (2026-07-27).
- `rate_limited` and malformed requests remain `caller_error` (still
  return `null`).

## 2026-07-24 — Connect Push catalog now (from v1.3.31)

- **Push catalog now** — Tools → Connect exposes a button that forks
  `numinix_seekmodo_push_catalog.php` in the background so organic
  sign-ups can recover from an empty Typesense collection without
  SSH/CLI. Requires gateway mode Active or Learning (not off); use
  Refresh snapshot first if you just flipped mode on admin.seekmodo.com.
- Retains v1.3.30 PDP/cart recommendation cascades.
