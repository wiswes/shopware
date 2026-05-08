<?php declare(strict_types=1);

namespace Wiswes\Widget\Storefront\Subscriber;

use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Wiswes\Widget\Service\ConfigReader;

/**
 * Inject the chat widget <script> tag on every storefront page render.
 *
 * Pulls config from system_config (so the widget token is per-shop,
 * configurable via Settings → WisWes), not from a hardcoded Twig
 * template. Skips injection cleanly when:
 *   - the merchant hasn't completed the "Install with WisWes" flow
 *     yet (widgetToken empty), or
 *   - they've toggled `injectOnStorefront` off in admin.
 */
final class WidgetInjectionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ConfigReader $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender',
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $snap = $this->config->snapshot($salesChannelId);

        // Two soft kill-switches; either is enough to stay quiet:
        // - Not yet connected to a WisWes tenant
        // - Merchant disabled the storefront injection
        if (!$snap['isInstalled'] || !$snap['injectOnStorefront']) {
            return;
        }

        $event->setParameter('wiswesWidget', [
            'enabled' => true,
            'baseUrl' => rtrim($snap['chatAgentBaseUrl'], '/'),
            'token' => $snap['widgetToken'],
        ]);
    }
}
