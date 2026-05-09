# WisWes Chat Widget — Shopware 6 Plugin

One-click install of the [WisWes](https://app.wiswes.com) AI shopping-assistant chat widget on a Shopware 6 storefront. The widget guides shoppers from product discovery through cart to checkout via natural-language conversation, backed by your store's live catalog.

## What this plugin gives you

| Capability | Status |
|---|---|
| **Storefront chat widget** | ✅ injected automatically on every page (driven by Shopware system_config — no Twig editing) |
| **One-click "Install with WisWes" button** in Shopware admin | ✅ Settings → Plugins → WisWes |
| **PHP-side MCP tool surface** that the WisWes chat backend calls into | ✅ 13 endpoints under `/wiswes/_mcp/*`, bearer-token authed |
| **Cart tools** — `cart_info` `cart_add` `cart_update` `cart_remove` | ✅ |
| **Catalog tools** — `product_filter` `product_get` `category_list` `product_filter_options` | ✅ |
| **Checkout tools** (guest flow) — `payment_methods` `shipping_methods` `set_address` `place_order` | ✅ |
| **Sales tools** — `order_info` (guest order lookup by order# + email) | ✅ |
| **Admin push-catalog button** (replaces external indexer with merchant-side sync) | 🚧 roadmap |

The plugin is structurally aligned with the [WisWes Magento extension](https://github.com/wiswes/magento) — same install button, same MCP tool surface, same merchant UX. The implementations differ because Magento has gaps in its native API that Shopware's Store API already fills, but the contract chat_agent talks to is identical.

## Requirements

- Shopware **6.5** or **6.6**
- A merchant account at **app.wiswes.com** (the install button auto-creates one if your shop's hostname doesn't match an existing tenant)
- Outbound HTTPS access from your Shopware server to `https://app.wiswes.com`
- Inbound HTTPS access from `app.wiswes.com` to your storefront — chat_agent calls back into `/wiswes/_mcp/*` to operate the cart/catalog/checkout

## Install

From your Shopware project root:

```bash
composer require wiswes/shopware-mcp
bin/console plugin:refresh
bin/console plugin:install --activate WiswesWidget
bin/console cache:clear
bin/build-administration.sh    # rebuilds the Vue admin so the WisWes module shows up
```

## Connect (one click)

1. Open your Shopware admin → **Settings → Plugins → WisWes**.
2. Confirm your shop's URL (auto-filled from the current admin host).
3. Enter your email — used as the contact for the auto-provisioned WisWes tenant.
4. Click **Install with WisWes**.

That's it. The widget appears on your storefront within a minute. Behind the scenes the plugin:

1. Calls chat_agent's install endpoint with the shop URL and your email.
2. Receives back a `widget_token` + per-tenant `mcp_secret` + `tenant_slug`.
3. Persists all three into Shopware's `system_config` (under the `WiswesWidget.config.*` namespace) so they survive cache clears + restarts.
4. The `WidgetInjectionSubscriber` reads them on every storefront render and injects the `<script src="…/embed.js?user_token=…">` tag.
5. chat_agent uses the same `mcp_secret` as a bearer token whenever the LLM needs to call back into your storefront's tool surface.

## Disconnect

Same admin page → **Disconnect** button. Stops the storefront from loading the widget. Your WisWes tenant on app.wiswes.com is preserved (so re-installing later restores conversation history); only the local Shopware credentials are cleared.

## Architecture

```
                      ┌─────────────────────────────────────────┐
   Shopper            │     app.wiswes.com (chat_agent)         │
   in browser  ──────▶│  - LLM picks a tool                      │
       │              │  - HTTP POST + Bearer mcp_secret to ─┐   │
       │              │                                      │   │
       │              └──────────────────────────────────────┼───┘
       │                                                     │
       │  storefront with embed.js loaded                    │
       │                                                     ▼
   ┌──────────────────────────────────────────────────────────┐
   │  Your Shopware (this plugin)                              │
   │  ─────────────────────────────────────────                │
   │  /wiswes/_mcp/cart/{info,add,update,remove}               │
   │  /wiswes/_mcp/catalog/{product_filter,product_get,...}    │
   │  /wiswes/_mcp/checkout/{payment_methods,...}              │
   │  /wiswes/_mcp/sales/order_info                            │
   │                                                            │
   │  Each route → BearerTokenSubscriber gate →                 │
   │              Symfony controller → Shopware Store API       │
   │              services (CartService, ProductListingRoute,  │
   │              CartOrderRoute, etc.)                         │
   └────────────────────────────────────────────────────────────┘
```

## File map

| Path | Purpose |
|---|---|
| `src/Resources/config/services.xml` | DI registration for all controllers + the bearer-auth subscriber |
| `src/Resources/config/routes.xml` | Imports `Controller/Admin` + `Controller/Mcp` annotated routes |
| `src/Resources/config/config.xml` | system_config schema (chatAgentBaseUrl, widgetToken, tenantSlug, tenantSecret, injectOnStorefront) |
| `src/Service/ConfigReader.php` | Wraps `SystemConfigService` so callers don't drift on the `WiswesWidget.config.*` key prefix |
| `src/Storefront/Subscriber/WidgetInjectionSubscriber.php` | StorefrontRenderEvent listener that puts widget config on the render context |
| `src/Resources/views/storefront/base.html.twig` | Renders `<script src="…/embed.js?user_token=…">` from the subscriber-set parameters |
| `src/Controller/Admin/InstallController.php` | Backs the "Install with WisWes" / "Disconnect" admin buttons. Routes: `/api/_action/wiswes/{status,install,disconnect}` |
| `src/Mcp/Http/BearerTokenSubscriber.php` | kernel.controller listener that 401s any `/wiswes/_mcp/*` request without a matching `tenantSecret` |
| `src/Controller/Mcp/CartController.php` | 4 cart routes — info / add / update / remove |
| `src/Controller/Mcp/CatalogController.php` | 4 catalog routes — product_filter / product_get / category_list / product_filter_options |
| `src/Controller/Mcp/CheckoutController.php` | 4 checkout routes — payment_methods / shipping_methods / set_address / place_order |
| `src/Controller/Mcp/SalesController.php` | 1 sales route — order_info |
| `src/Resources/app/administration/src/module/wiswes-widget/` | Vue admin module — single page with status display + install/disconnect buttons |

## Roadmap

- **Push catalog from admin** — admin button to push the merchant's product catalog into chat_agent's per-tenant Qdrant collection (replaces the external Python indexer with a merchant-controlled action).
- **Customer-aware tools** — currently guests-only; once the WisWes app's customer-context flow is settled we'll expose `customer_info`, `order_history`, etc. for logged-in shoppers.
- **Marketplace listing** — submit to the Shopware Store once Phase 7's push service lands and a few merchants have run on production.

## License

MIT — see [LICENSE](./LICENSE).
