# Seekmodo for Zen Cart v1.3.34

## 2026-07-28 — Push catalog PHP CLI discovery (NS-26042)

- **Push catalog now on PHP 8.3 / EasyApache** — `Pairing::resolve_php_binary()`
  now finds CLI binaries on hosts where `$PATH` is empty under FPM and the
  older resolver stopped at `ea-php82`. Adds version-matched
  `/opt/cpanel/ea-php{MM}/…` and CloudLinux `/opt/alt/php{MM}/…` paths,
  derives CLI from `PHP_BINARY` (`php-fpm` → `php`), accepts
  `NUMINIX_SEEKMODO_PHP_BINARY` override, and passes `--ack-quota` on
  admin-forked pushes so Essential quota preflight does not skip them.
  Fixes Connect → Push catalog now failing with
  `no php binary found in $PATH` (Cannapot / links-c3ca80).

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
