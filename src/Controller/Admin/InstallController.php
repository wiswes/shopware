<?php declare(strict_types=1);

namespace Wiswes\Widget\Controller\Admin;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Wiswes\Widget\Service\ConfigReader;

/**
 * Admin endpoints backing the "Install with WisWes" / "Disconnect" /
 * "Status" UI in the Shopware admin module. All routes live under the
 * `/api/_action/wiswes/*` namespace which Shopware admin auth protects
 * automatically — only logged-in admin users with API access reach
 * these handlers.
 *
 * @Route(defaults={"_routeScope"={"api"}})
 */
class InstallController extends AbstractController
{
    public function __construct(
        private readonly ConfigReader $config,
        private readonly HttpClientInterface $http,
    ) {
    }

    /**
     * Returns the current install status the admin module renders.
     *
     * @Route("/api/_action/wiswes/status", name="api.action.wiswes.status", methods={"GET"})
     */
    public function status(Request $request): JsonResponse
    {
        $salesChannelId = $request->query->get('salesChannelId');
        return new JsonResponse($this->config->snapshot($salesChannelId));
    }

    /**
     * One-click install: collects shop info, calls chat_agent's
     * /api/integrations/shopware/install, and persists the returned
     * widget_token + tenant credentials into system_config.
     *
     * Body:
     *   { "salesChannelId": "<uuid|null>", "adminEmail": "wes@example.com",
     *     "shopUrl": "https://store.example.com" }
     *
     * @Route("/api/_action/wiswes/install", name="api.action.wiswes.install", methods={"POST"})
     */
    public function install(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?: [];
        $salesChannelId = $body['salesChannelId'] ?? null;
        $adminEmail = trim((string) ($body['adminEmail'] ?? ''));
        $shopUrl = trim((string) ($body['shopUrl'] ?? ''));

        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'A valid admin email is required.'], 400);
        }
        if ($shopUrl === '') {
            return new JsonResponse(['error' => 'shopUrl is required.'], 400);
        }

        $base = rtrim($this->config->getString('chatAgentBaseUrl', $salesChannelId, 'https://app.wiswes.com'), '/');

        try {
            $response = $this->http->request('POST', $base . '/api/integrations/shopware/install', [
                'json' => [
                    'shop_url' => $shopUrl,
                    'admin_email' => $adminEmail,
                    'platform' => 'shopware',
                ],
                'timeout' => 15,
            ]);
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface | TransportExceptionInterface $exc) {
            return new JsonResponse([
                'error' => 'Could not reach chat_agent: ' . $exc->getMessage(),
            ], 502);
        }

        if (!isset($data['widget_token'], $data['tenant_slug'], $data['tenant_secret'])) {
            return new JsonResponse([
                'error' => 'chat_agent response missing required fields',
                'response' => $data,
            ], 502);
        }

        // Persist into system_config — the Subscriber reads these on
        // every storefront render. Once these are set, the widget
        // appears automatically.
        $this->config->set('widgetToken', (string) $data['widget_token'], $salesChannelId);
        $this->config->set('tenantSlug', (string) $data['tenant_slug'], $salesChannelId);
        $this->config->set('tenantSecret', (string) $data['tenant_secret'], $salesChannelId);

        return new JsonResponse([
            'success' => true,
            'snapshot' => $this->config->snapshot($salesChannelId),
        ]);
    }

    /**
     * Tear down the connection — clears stored credentials. The
     * remote tenant on app.wiswes.com isn't deleted (kept so the
     * merchant can re-install without losing conversation history);
     * we just stop the storefront from loading the widget.
     *
     * @Route("/api/_action/wiswes/disconnect", name="api.action.wiswes.disconnect", methods={"POST"})
     */
    public function disconnect(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?: [];
        $salesChannelId = $body['salesChannelId'] ?? null;

        $this->config->clear('widgetToken', $salesChannelId);
        $this->config->clear('tenantSlug', $salesChannelId);
        $this->config->clear('tenantSecret', $salesChannelId);

        return new JsonResponse(['success' => true]);
    }
}
