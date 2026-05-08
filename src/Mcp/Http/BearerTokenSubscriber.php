<?php declare(strict_types=1);

namespace Wiswes\Widget\Mcp\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Wiswes\Widget\Service\ConfigReader;

/**
 * Auth gate for the PHP-side MCP endpoints `chat_agent` calls into.
 *
 * Every `/wiswes/_mcp/*` request must carry
 * `Authorization: Bearer <tenantSecret>` matching the value the install
 * handshake stored in `system_config`. Anything else gets 401 before the
 * controller runs — keeps unauthenticated callers off the cart/checkout
 * surface even though the URL prefix is otherwise unauthenticated by
 * Shopware's storefront-routing rules.
 *
 * Constant-time comparison prevents timing-based secret recovery if the
 * endpoint becomes a fuzzing target.
 */
final class BearerTokenSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = '/wiswes/_mcp/';
    private const AUTH_HEADER = 'Authorization';
    private const BEARER_PREFIX = 'Bearer ';

    public function __construct(private readonly ConfigReader $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Run on kernel.controller so the ControllerEvent's
        // setController lets us short-circuit with a 401 closure
        // before the cart/checkout controllers do any work.
        return [
            KernelEvents::CONTROLLER => ['onController', 0],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, self::ROUTE_PREFIX)) {
            return;
        }

        $expected = $this->config->getString('tenantSecret');
        if ($expected === '') {
            // Plugin hasn't been installed/connected yet — no secret
            // to compare against. Reject everything; the merchant
            // must complete the install handshake first.
            $event->setController(static fn () => new JsonResponse([
                'error' => 'plugin_not_installed',
                'detail' => 'WisWes is not connected. Click Install with WisWes in Shopware admin first.',
            ], 401));
            return;
        }

        $header = (string) $request->headers->get(self::AUTH_HEADER, '');
        if (!str_starts_with($header, self::BEARER_PREFIX)) {
            $event->setController(static fn () => new JsonResponse([
                'error' => 'missing_bearer_token',
            ], 401));
            return;
        }

        $provided = substr($header, strlen(self::BEARER_PREFIX));
        if (!hash_equals($expected, $provided)) {
            $event->setController(static fn () => new JsonResponse([
                'error' => 'invalid_bearer_token',
            ], 401));
            return;
        }

        // Auth passed — let the controller run. We don't pass anything
        // through to it since the tenantSecret only proves identity;
        // per-call context (sw-context-token, sales channel) comes from
        // the request body/headers as it would for any Store API call.
    }
}
