## v1.3.106 — 2026-11-20

### Changed
- **Gateway origin outage (Phase 1).** Transport errors and 5xx from mcp
  mark a 5-minute APCu outage flag so subsequent storefront calls skip the
  dead-gateway wait and fall back to native search immediately. Cleared on
  the next successful gateway response.

# Seekmodo for Zen Cart v1.3.106

Based on v1.3.105 proxy-first suggest body fix.
