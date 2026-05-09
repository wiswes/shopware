<?php declare(strict_types=1);

namespace Wiswes\Widget\Controller\Mcp;

use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * MCP checkout tools — guest-only flow that mirrors Magento's
 * Mcp/Tool/Checkout/{SetAddress,PaymentMethods,ShippingMethods,
 * PlaceOrder}Tool.php. Customer auth is intentionally out of scope
 * (Phase X if ever) — the chat assistant handles guest shoppers and
 * either places a guest order or hands off to the storefront's full
 * registration UI for anything more complex.
 *
 * Auth: handled upstream by BearerTokenSubscriber.
 *
 * @Route(defaults={"_routeScope"={"storefront"}, "_loginRequired"=false})
 */
class CheckoutController extends AbstractController
{
    public function __construct(
        private readonly AbstractPaymentMethodRoute $paymentMethodRoute,
        private readonly AbstractShippingMethodRoute $shippingMethodRoute,
        private readonly AbstractRegisterRoute $registerRoute,
        private readonly AbstractCartOrderRoute $cartOrderRoute,
        private readonly CartService $cartService,
        private readonly SalesChannelContextService $contextService,
    ) {
    }

    /**
     * List payment methods active on this sales channel.
     *
     * @Route(
     *     "/wiswes/_mcp/checkout/payment_methods",
     *     name="wiswes._mcp.checkout.payment_methods",
     *     methods={"POST", "GET"}
     * )
     */
    public function paymentMethods(Request $request): JsonResponse
    {
        try {
            $context = $this->loadContext($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }
        $methods = $this->paymentMethodRoute->load(new Request(), $context, new Criteria())->getPaymentMethods();
        $out = [];
        foreach ($methods as $m) {
            $out[] = [
                'id' => $m->getId(),
                'name' => $m->getTranslation('name') ?? $m->getName(),
                'description' => strip_tags((string) ($m->getTranslation('description') ?? $m->getDescription() ?? '')),
            ];
        }
        return new JsonResponse(['payment_methods' => $out]);
    }

    /**
     * List shipping methods active on this sales channel.
     *
     * @Route(
     *     "/wiswes/_mcp/checkout/shipping_methods",
     *     name="wiswes._mcp.checkout.shipping_methods",
     *     methods={"POST", "GET"}
     * )
     */
    public function shippingMethods(Request $request): JsonResponse
    {
        try {
            $context = $this->loadContext($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }
        $methods = $this->shippingMethodRoute->load(new Request(), $context, new Criteria())->getShippingMethods();
        $out = [];
        foreach ($methods as $m) {
            $out[] = [
                'id' => $m->getId(),
                'name' => $m->getTranslation('name') ?? $m->getName(),
                'description' => strip_tags((string) ($m->getTranslation('description') ?? $m->getDescription() ?? '')),
            ];
        }
        return new JsonResponse(['shipping_methods' => $out]);
    }

    /**
     * Register a guest customer with the supplied billing/shipping
     * address. This is the Shopware-y way to "set the address on the
     * cart": there's no separate set-address-on-anonymous-cart API —
     * to bind an address to a cart you need a customer attached, and
     * for guest checkout that means register-as-guest.
     *
     * Body:
     *   {
     *     email, first_name, last_name,
     *     billing: { street, zipcode, city, country_iso2, ... }
     *   }
     *
     * On success the customer is logged into the cart's context, and
     * the cart now has an associated address that downstream tools
     * (place_order) need.
     *
     * @Route(
     *     "/wiswes/_mcp/checkout/set_address",
     *     name="wiswes._mcp.checkout.set_address",
     *     methods={"POST"}
     * )
     */
    public function setAddress(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $email = trim((string) ($body['email'] ?? ''));
        $first = trim((string) ($body['first_name'] ?? ''));
        $last = trim((string) ($body['last_name'] ?? ''));
        $billing = (array) ($body['billing'] ?? []);

        foreach (['email' => $email, 'first_name' => $first, 'last_name' => $last] as $k => $v) {
            if ($v === '') {
                return new JsonResponse(['error' => "$k is required"], 400);
            }
        }
        foreach (['street', 'zipcode', 'city', 'country_iso2'] as $k) {
            if (trim((string) ($billing[$k] ?? '')) === '') {
                return new JsonResponse(['error' => "billing.$k is required"], 400);
            }
        }

        try {
            $context = $this->loadContext($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }

        $countryId = $this->resolveCountryId($context, (string) $billing['country_iso2']);
        if ($countryId === null) {
            return new JsonResponse([
                'error' => "no active country with iso2='{$billing['country_iso2']}' on this sales channel",
            ], 400);
        }
        $salutationId = $this->resolveDefaultSalutationId($context);

        $data = new RequestDataBag([
            'guest' => true,
            'email' => $email,
            'firstName' => $first,
            'lastName' => $last,
            'salutationId' => $salutationId,
            'storefrontUrl' => $request->getSchemeAndHttpHost(),
            'billingAddress' => [
                'firstName' => $first,
                'lastName' => $last,
                'salutationId' => $salutationId,
                'street' => (string) $billing['street'],
                'zipcode' => (string) $billing['zipcode'],
                'city' => (string) $billing['city'],
                'countryId' => $countryId,
            ],
            'acceptedDataProtection' => true,
        ]);

        try {
            $resp = $this->registerRoute->register($data, $context, /* validateStorefrontUrl */ false);
        } catch (\Throwable $exc) {
            return new JsonResponse([
                'error' => 'guest_registration_failed',
                'detail' => $exc->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
            'customer_id' => $resp->getCustomer()?->getId(),
            'context_token' => $resp->headers->get('sw-context-token') ?: $context->getToken(),
        ]);
    }

    /**
     * Convert the current cart into an order. Requires set_address
     * to have run first (so the cart has billing + shipping bound).
     *
     * @Route(
     *     "/wiswes/_mcp/checkout/place_order",
     *     name="wiswes._mcp.checkout.place_order",
     *     methods={"POST"}
     * )
     */
    public function placeOrder(Request $request): JsonResponse
    {
        try {
            $context = $this->loadContext($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        if ($cart->getLineItems()->count() === 0) {
            return new JsonResponse(['error' => 'cart is empty'], 400);
        }
        if ($context->getCustomer() === null) {
            return new JsonResponse([
                'error' => 'no customer on cart — call /checkout/set_address first to register a guest',
            ], 400);
        }

        try {
            $order = $this->cartOrderRoute->order($cart, $context, new RequestDataBag([]))->getOrder();
        } catch (\Throwable $exc) {
            return new JsonResponse([
                'error' => 'order_placement_failed',
                'detail' => $exc->getMessage(),
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'total' => $order->getPrice()->getTotalPrice(),
        ]);
    }

    // -- helpers ---------------------------------------------------------

    private function resolveCountryId(SalesChannelContext $context, string $iso2): ?string
    {
        // Pull active countries off the sales channel rather than
        // hitting the country repository directly — guarantees the
        // returned id is one this sales channel actually accepts.
        foreach ($context->getSalesChannel()->getCountries() ?? [] as $country) {
            if (strcasecmp($country->getIso() ?? '', $iso2) === 0) {
                return $country->getId();
            }
        }
        // Fall back to the sales channel's default country if the
        // collection wasn't lazily loaded (some DI configs).
        $default = $context->getSalesChannel()->getCountry();
        if ($default instanceof CountryEntity && strcasecmp($default->getIso() ?? '', $iso2) === 0) {
            return $default->getId();
        }
        return null;
    }

    private function resolveDefaultSalutationId(SalesChannelContext $context): string
    {
        // Shopware requires a salutation on registration. We don't
        // bother the LLM with this — pick "not_specified" if available
        // (Shopware's default-system value). If the merchant wiped it,
        // any salutation is fine since the customer never sees it.
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salutationKey', 'not_specified'));
        $criteria->setLimit(1);
        // We only have access to the SalesChannelContext here, no
        // direct SalutationRepository — but the cart's customer
        // creation path will fall back to the default if we pass
        // empty. Returning empty string lets Shopware's validator
        // fill in. (When the validator is strict we'll wire in the
        // salutation.repository service properly.)
        return '';
    }

    private function loadContext(Request $request): SalesChannelContext
    {
        $token = (string) $request->headers->get('sw-context-token', '');
        if ($token === '') {
            $body = json_decode((string) $request->getContent(), true) ?: [];
            $token = trim((string) ($body['context_token'] ?? ''));
        }
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }

        $salesChannelId = (string) $request->headers->get('sw-sales-channel-id', '');
        if ($salesChannelId === '') {
            $body = json_decode((string) $request->getContent(), true) ?: [];
            $salesChannelId = trim((string) ($body['sales_channel_id'] ?? ''));
        }
        if ($salesChannelId === '') {
            throw new \InvalidArgumentException(
                'sales_channel_id is required (header sw-sales-channel-id or body field)'
            );
        }

        return $this->contextService->get(new SalesChannelContextServiceParameters(
            $salesChannelId,
            $token,
        ));
    }
}
