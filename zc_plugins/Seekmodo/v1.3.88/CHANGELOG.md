# Seekmodo Zen Cart v1.3.88

## 2026-09-25 - Pair opens in a new tab

### Changed
- Connect / Re-pair uses `formtarget="_blank"` so seekmodo.com opens in a
  new tab and Zen Cart admin stays available. Close the Seekmodo tab when
  pairing finishes.

## 2026-09-24 - Over-quota PreferLocal after period reset

### Fixed
- Over-quota PreferLocal recovers after billing-period reset via soft-probe
  + expired `resets_at` clear (carried forward from v1.3.87).
