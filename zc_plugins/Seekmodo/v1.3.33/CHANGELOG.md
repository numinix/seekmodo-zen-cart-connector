# Seekmodo for Zen Cart v1.3.33

## 2026-07-27 — events referer parity (Rec 3)

- **Shopper-context parity** — `numinix_seekmodo_mirror_click`,
  `numinix_seekmodo_mirror_impression`, and
  `numinix_seekmodo_mirror_conversion` now include `referer` from
  `HTTP_REFERER` when present (same as search / typeahead payloads).

## 2026-07-27 — HMAC auth fallback classification (from v1.3.32)

- **Auth misconfig native fallback** — gateway `auth_fail` /
  `signature_mismatch` responses (401/403) are classified as
  `auth_misconfig` with `fallback_reason = auth_misconfig`.
  Behaviour unchanged (`Client::call()` still returns `null` →
  native search); observability + docblocks corrected.
