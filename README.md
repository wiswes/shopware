# WisWes Chat Widget — Shopware 6 Plugin

Embeds the [WisWes](https://app.wiswes.com) AI shopping-assistant chat widget into a Shopware 6 storefront. The widget guides shoppers from product discovery through cart to checkout via natural-language conversation, backed by your store's live catalog.

## Status

Early — published as a starting point. The widget URL and merchant token are currently hardcoded in a Twig template (`src/Resources/views/storefront/base.html.twig`). A future revision will move both into a Shopware admin config UI so merchants don't edit theme files.

If you want a smoother install path before that lands, the App System manifest at `https://app.wiswes.com/manifest.xml` is the alternative — it doesn't require a PHP plugin at all.

## Requirements

- Shopware 6.5+
- A WisWes account at https://app.wiswes.com
- Your tenant's `widget_token` (Dashboard → Settings → Widget)

## Install

From your Shopware project root:

```bash
composer require wiswes/widget
bin/console plugin:refresh
bin/console plugin:install --activate WiswesWidget
bin/console cache:clear
```

## Configure

Open `src/Resources/views/storefront/base.html.twig` in this plugin and replace both occurrences of `YOUR_USER_TOKEN` with the widget token from your WisWes dashboard.

Then rebuild the storefront so the Twig override picks up:

```bash
bin/console theme:compile
bin/console cache:clear
```

Visit any storefront page — the chat icon should appear in the bottom-right corner.

## Uninstall

```bash
bin/console plugin:uninstall WiswesWidget
```

## Roadmap

- [ ] Admin config UI (`Resources/config/config.xml`) so merchants set `userToken` without editing Twig
- [ ] Storefront subscriber (`Resources/config/services.xml`) instead of Twig template override
- [ ] Webhook receiver for `app.wiswes.com` to push catalog/intent updates
- [ ] App System manifest as an alternative install path (no PHP plugin)

## License

MIT — see [LICENSE](./LICENSE).
