<?php
/**
 * Seekmodo AI Chatbot for Zen Cart.
 */
return [
    'pluginVersion' => 'v1.1.1',
    'pluginName' => 'Seekmodo AI Chatbot',
    'pluginDescription' => 'Seekmodo AI chatbot with live agent takeover, ticketing/inbox, multi-provider LLM (OpenAI / Claude / Deepseek), catalog search, and optional store MCP tools. Ships with the Seekmodo connector; enable after pairing on a paid Seekmodo plan.',
    'pluginAuthor' => 'Numinix / Seekmodo',
    'pluginId' => 0,
    'zcVersions' => ['v158', 'v200'],
    'changelog' => 'v1.1.1 (2026-11-03): Catalog-fit prompt — voice/currency from store context; gift-order ambiguous-intent list only when mutation tools are present; never invent popularity or claim a product is missing without searching; Numinix digital order/license/Zammad helpers when host is numinix.com/.ca. v1.1.1 (2026-11-02): Seekmodo chat launcher (color, flair, one-time auto-open) from tenant snapshot; shared seekmodo-chat package for portal tokens and mail replies. Bundled with Seekmodo connector releases. v1.1.0 (2026-10-16): Widget agent icon — upload a store avatar in Chatbot Settings; it renders beside bot and human agent replies. v1.1.0 (2026-09-25): Auto-archive resolved chats after N days (default 30) via tools/auto_archive_chats.php + settings; install README documents required cron jobs. v1.1.0 (2026-09-23): Widget primary/secondary color settings; shopper online/offline presence for live vs email; reopen handed-off chats when shopper returns (unified transcript); Chat Inbox admin homepage widget. Product rename to Seekmodo AI Chatbot; Seekmodo Concept 4 branding; paired paid Seekmodo connector gate; BYO LLM providers (OpenAI, Claude, Deepseek); live admin agent claim/reply/resume AI with internal notes; soft-fallback when kip.order.* MCP tools are absent; installer migrates OpenaiGptChatbot config/table/admin pages (language keys + filenames) and disables the legacy plugin. v1.0.21: order mutations toggle + post-chat analysis queue.',
    'github_repo' => 'https://github.com/numinix/openai-gpt-chatbot',
    'pluginGroups' => [],
];
