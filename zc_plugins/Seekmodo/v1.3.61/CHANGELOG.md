# Seekmodo for Zen Cart v1.3.61

## 2026-08-12 - Allow indexing when locked_domain is intentionally nonprod

- **can_index() no longer fail-closes a matched nonprod locked_domain.**
  Demo/staging tenants locked to hosts like demo.example.com could
  never push catalog (observed == locked still blocked). Unlocked
  nonprod hosts remain fail-closed.

## Prior (v1.3.60)

- Language-pack UTF-8 BOM no longer ejects seekmodo:* metas from head.
