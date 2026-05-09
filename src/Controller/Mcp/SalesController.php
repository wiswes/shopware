<?php declare(strict_types=1);

namespace Wiswes\Widget\Controller\Mcp;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * MCP sales tools — guest order lookup.
 *
 * Mirrors Magento's Mcp/Tool/Sales/OrderInfoTool.php. The LLM uses
 * this when a shopper asks "where's my order #1234" and gives an
 * email — we look up by both fields together so order numbers alone
 * can't be enumerated.
 *
 * Auth: handled upstream by BearerTokenSubscriber.
 *
 * @Route(defaults={"_routeScope"={"storefront"}, "_loginRequired"=false})
 */
class SalesController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
    ) {
    }

    /**
     * Fetch a single order by `{ order_number, email }`. Both fields
     * required — order_number alone is not enough (anti-enumeration).
     *
     * @Route(
     *     "/wiswes/_mcp/sales/order_info",
     *     name="wiswes._mcp.sales.order_info",
     *     methods={"POST"}
     * )
     */
    public function orderInfo(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $orderNumber = trim((string) ($body['order_number'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));

        if ($orderNumber === '' || $email === '') {
            return new JsonResponse([
                'error' => 'both order_number and email are required',
            ], 400);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));
        $criteria->addFilter(new EqualsFilter('orderCustomer.email', $email));
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('stateMachineState');
        $criteria->setLimit(1);

        $order = $this->orderRepository->search($criteria, Context::createDefaultContext())->first();
        if ($order === null) {
            return new JsonResponse([
                'error' => 'order_not_found',
                'detail' => 'no order matches the supplied order_number + email pair',
            ], 404);
        }

        $lineItems = [];
        foreach ($order->getLineItems() ?? [] as $line) {
            $lineItems[] = [
                'name' => $line->getLabel(),
                'quantity' => $line->getQuantity(),
                'total_price' => $line->getTotalPrice(),
            ];
        }

        $shippingMethod = $order->getDeliveries()?->first()?->getShippingMethod()?->getTranslation('name');
        $paymentMethod = $order->getTransactions()?->first()?->getPaymentMethod()?->getTranslation('name');

        return new JsonResponse([
            'order' => [
                'order_number' => $order->getOrderNumber(),
                'state' => $order->getStateMachineState()?->getTranslation('name'),
                'order_date' => $order->getOrderDateTime()?->format(\DateTimeInterface::ATOM),
                'total' => $order->getPrice()?->getTotalPrice(),
                'currency_iso' => $order->getCurrency()?->getIsoCode(),
                'line_items' => $lineItems,
                'shipping_method' => $shippingMethod,
                'payment_method' => $paymentMethod,
                'customer_email' => $order->getOrderCustomer()?->getEmail(),
            ],
        ]);
    }
}
