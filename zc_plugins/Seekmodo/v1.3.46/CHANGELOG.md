# Seekmodo for Zen Cart v1.3.46

## 2026-08-04 - German suggest UI labels on German-primary shops (NS-26042)

- **Default-language suggest chrome** — `init_numinix_seekmodo.php` now
  falls back to Zen Cart `DEFAULT_LANGUAGE` (same resolution as the
  suggest `lang` attribute) instead of hard-coding English when the
  session language is not ready yet.
- **`suggestLabels()` reads the active language pack from disk** — so
  English constants defined by an earlier init pass cannot stick and
  leave German-primary storefronts showing "results for" /
  "SUGGESTIONS" while Spanish/French switch correctly (Cannapot).
