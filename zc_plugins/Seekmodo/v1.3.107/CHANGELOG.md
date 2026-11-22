## v1.3.107 — 2026-11-21

### Fixed
- **Seekmodo Tickets admin page sends the action with every call.** The shared ticket UI calls `request(action, body)`; the Zen Cart page glue only posted its first argument, so the proxy received a bare string and answered `bad_json`. The glue now merges the action into the body.

### Added
- **More ticket actions through the bundled AI Chatbot Tickets proxy.** `attachment_get`, `participants_add` / `participants_remove`, and `macros_list` are allowed. Tenant settings, macro editing, suppressions, quarantine, merge and bulk update stay in admin.seekmodo.com.

# Seekmodo for Zen Cart v1.3.107

Based on v1.3.106 gateway origin-outage skip. Search plugin code is unchanged; this cut ships the bundled Seekmodo AI Chatbot Tickets fix.
