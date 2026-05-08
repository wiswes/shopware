# WisWes Chat Widget — Shopware 6 Plugin

One-click install of the [WisWes](https://app.wiswes.com) AI shopping-assistant chat widget on a Shopware 6 storefront. The widget guides shoppers from product discovery through cart to checkout via natural-language conversation, backed by your store's live catalog.

## Features (Phase 1)

- **Install with WisWes button** in Shopware admin (Settings → Plugins → WisWes) — auto-creates your tenant on app.wiswes.com, mints a widget token, and wires it into the storefront.
- **No hardcoded values** — widget token, tenant slug, and chat-agent base URL all live in Shopware's `system_config` and are populated by the install flow.
- **Storefront Subscriber** injects the chat widget script tag on every page render (no Twig editing required).
- **Sales-channel-scoped** — connect different storefronts to different WisWes tenants.

## Roadmap (Phases 2–7)

The plugin currently relies on chat_agent's Python adapter calling Shopware's Store API directly to expose tools (cart, catalog, checkout) to the LLM. Coming next:

- **PHP-side MCP server** inside this plugin (mirrors the Magento extension) — exposes `cart_*`, `product_*`, `category_list`, `checkout_*`, `order_info` tools as authenticated HTTP endpoints. chat_agent calls them instead of Shopware's Store API directly.
- **Push service + admin "push catalog" button** — replaces the Python pull-based indexer.
- **Per-customer auth context** — currently guests-only; logged-in customers will come with the customer-info tools (post-Phase 4).

## Requirements

- Shopware **6.5** or **6.6**
- A merchant account at **app.wiswes.com** (the install button auto-creates one if your shop's hostname doesn't match an existing tenant)
- Outbound HTTPS access from your Shopware server to `https://app.wiswes.com`

## Install

From your Shopware project root:

```bash
composer require wiswes/widget
bin/console plugin:refresh
bin/console plugin:install --activate WiswesWidget
bin/console cache:clear
bin/build-administration.sh    # rebuilds the Vue admin so the WisWes module shows up
```

## Connect

1. Open your Shopware admin → **Settings → Plugins → WisWes**.
2. Confirm your shop's URL (auto-filled from the current admin host).
3. Enter your email — used as the contact for the auto-provisioned WisWes tenant.
4. Click **Install with WisWes**.

That's it. The widget appears on your storefront within a minute. Open any storefront page and the chat icon should be in the bottom-right corner.

## Disconnect

Same admin page → **Disconnect** button. Stops the storefront from loading the widget. Your WisWes tenant on app.wiswes.com is preserved (so re-installing later restores conversation history); only the local Shopware credentials are cleared.

## How it works under the hood

| Component | Purpose |
|---|---|
| `Storefront/Subscriber/WidgetInjectionSubscriber.php` | Listens for `StorefrontRenderEvent`. If the merchant is connected and `injectOnStorefront=true`, attaches `wiswesWidget.{baseUrl,token}` to the render context |
| `Resources/views/storefront/base.html.twig` | Renders `<script src="…/embed.js?user_token=…">` from those parameters. Empty if not connected |
| `Controller/Admin/InstallController.php` | Backs the admin button. Routes: `/api/_action/wiswes/{status,install,disconnect}` |
| `Service/ConfigReader.php` | Wraps `SystemConfigService` so callers don't drift on the `WiswesWidget.config.*` key prefix |
| `Resources/app/administration/src/module/wiswes-widget/` | Vue admin module — single page with status display + install/disconnect buttons |
| `Resources/config/config.xml` | Defines the `system_config` fields (chatAgentBaseUrl, widgetToken, tenantSlug, tenantSecret, injectOnStorefront) |

## License

MIT — see [LICENSE](./LICENSE).
