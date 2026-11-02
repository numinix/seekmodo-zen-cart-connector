# Chat inbox retention

Resolved chats stay in the live inbox until an admin archives them (or the auto-archive cron runs). Soft-archive only sets `archived_at` — transcripts are not deleted and remain under **Show archived**.

## Auto-archive (recommended)

1. Install / upgrade **Seekmodo AI Chatbot** so the config key `SEEKMODO_AI_CHATBOT_AUTO_ARCHIVE_RESOLVED_DAYS` is seeded (**default `30`**).
2. Confirm the value under Tools → Seekmodo AI Chatbot → **Chat Inbox** (`0` disables).
3. Add a **daily** cron job:

```bash
php zc_plugins/SeekmodoAiChatbot/v1.1.0/tools/auto_archive_chats.php \
  --catalog=/path/to/catalog \
  --host=www.example.com
```

Only chats with status `resolved`, `archived_at IS NULL`, and `COALESCE(resolved_at, date_updated)` older than the configured days are archived (up to 500 per run).

Also add the inbound-mail cron (every 1–5 minutes) and optionally the post-chat analysis nightly job. Full install table and crontab examples: plugin root `README.md` → **Cron jobs (required after install)**.
