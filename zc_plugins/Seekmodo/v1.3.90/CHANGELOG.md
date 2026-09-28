# Seekmodo for Zen Cart v1.3.90

## v1.3.90 - 2026-09-28 (cloud Inbox publish)

### Added
- **Cloud Inbox discovery** — when Seekmodo AI Chatbot is installed,
  `tenant.snapshot` push includes an `inbox` block (`api_url`,
  `mcp_url`, `plugin_version`, `capabilities`) so admin.seekmodo.com
  can HMAC-proxy the Chat Inbox without storing MCP operator tokens.
  Transcripts, SMTP/IMAP, and BYOK remain on the store.
