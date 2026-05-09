# 0.3.0 — 2026-05-09

- One-click "Install with WisWes" admin module that auto-creates a
  WisWes tenant and stores the widget token, tenant slug, and tenant
  secret in `system_config`.
- PHP-side MCP endpoints (`/wiswes/_mcp/*`) gated by per-tenant bearer
  token, exposing 13 cart / catalog / checkout / sales tools that the
  WisWes chat backend calls into.
- Storefront subscriber auto-injects the chat widget on every page
  when "Show widget on storefront" is on (default).
- Admin disconnect button preserves the WisWes-side tenant so a
  reconnect doesn't lose conversation history.

# 0.2.0 — 2026-04-22

- Initial public release. Storefront-only injection driven by manual
  Twig override; admin module placeholder.

# 0.1.0 — 2026-04-15

- Internal preview against a single demo store. Not published.
