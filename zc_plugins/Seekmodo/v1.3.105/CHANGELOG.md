## v1.3.105 — 2026-11-19

### Fixed
- **Proxy-first suggest empty dropdown on LiteSpeed/EA-PHP.** Catalog /v1/suggest reads the SDK JSON body before the CSRF POST→GET rewrite.

# Seekmodo for Zen Cart v1.3.105

## Fixed
- Do not mint or clear `sm_pid` Set-Cookie on `Sec-Purpose: prefetch` /
  `Purpose: prefetch` (Cloudflare Speculation Rules refuse cookie responses).

Based on v1.3.104 SSR SERP prefetch UX.
