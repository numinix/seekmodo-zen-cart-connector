# Seekmodo Zen Cart v1.3.87

## 2026-09-24 - Over-quota PreferLocal after period reset

### Fixed
- Clear PreferLocal when the stored 402 `resets_at` is already past.
- Soft-probe `/v1/suggest` from `applyBillingSnapshot()` for an active
  `over_quota` sticky (WordPress ConfigPullCron parity) instead of
  refusing to clear on `billing.status=active` alone.
- Over_quota unpaid rechecks every 5 minutes (cancelled/trial still daily).
- Do not stamp PreferLocal from a 402 envelope whose `resets_at` is
  already in the past (stale stamp after renewal).
