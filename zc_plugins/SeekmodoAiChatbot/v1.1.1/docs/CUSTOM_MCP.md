# Custom MCP tools for Seekmodo AI Chatbot

Seekmodo AI Chatbot talks to the store through **Numinix MCP**. Seekmodo itself is the catalog brain (HMAC / connector search).

## Works without custom tools

With Numinix MCP installed, the agent can use generic tools (`product.*`, `order.get`, shipping/address/cancel helpers when enabled, KB, support tickets, etc.).

## Adding client-specific tools

1. Ship a small Zen Cart plugin (example: `KipMcpExtensions`) that listens for `NOTIFY_NUMINIX_MCP_REGISTER_TOOLS`.
2. Register MCP tools with chatbot-safe scopes (see Numinix MCP `ScopePolicy`).
3. Optionally extend `ChatAgent` tool definitions for first-class agent tools that wrap those MCP names.

When a store-specific tool is absent, the chatbot should return a structured error the model can explain (never invent order/policy data).

## Upsell

Numinix can implement custom MCP tool packs (order personalisation, CRM, ERP, etc.) as a paid services engagement on top of Seekmodo AI Chatbot.
