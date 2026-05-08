<?php declare(strict_types=1);

namespace Wiswes\Widget\Controller\Mcp;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * MCP cart tools — what `chat_agent` calls when the LLM wants to
 * inspect or mutate the shopper's cart.
 *
 * Mirrors Magento's `Mcp/Tool/Cart/{Info,Add,Update,Remove}Tool.php`
 * surface, but each tool is one Symfony route delegating to
 * Shopware's `CartService`. The `sw-context-token` header binds the
 * request to a specific shopper's session — same pattern Shopware's
 * own Store API uses, so the cart shopper sees on the storefront and
 * the cart the LLM mutates here are guaranteed to be the same one.
 *
 * Auth: handled upstream by `BearerTokenSubscriber`. Anything that
 * reaches a method on this class has already passed the bearer check.
 *
 * @Route(defaults={"_routeScope"={"storefront"}, "_loginRequired"=false})
 */
class CartController extends AbstractController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly SalesChannelContextService $contextService,
        private readonly SalesChannelContextPersister $contextPersister,
        private readonly LineItemFactoryRegistry $lineItemFactory,
    ) {
    }

    /**
     * @Route(
     *     "/wiswes/_mcp/cart/info",
     *     name="wiswes._mcp.cart.info",
     *     methods={"GET", "POST"}
     * )
     */
    public function info(Request $request): JsonResponse
    {
        try {
            [$context, $token] = $this->loadContextAndToken($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }
        return new JsonResponse($this->serializeCart(
            $this->cartService->getCart($token, $context),
        ));
    }

    /**
     * Add `{ product_id, quantity? }` to the cart. quantity defaults to 1.
     *
     * @Route(
     *     "/wiswes/_mcp/cart/add",
     *     name="wiswes._mcp.cart.add",
     *     methods={"POST"}
     * )
     */
    public function add(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $productId = trim((string) ($body['product_id'] ?? ''));
        $quantity = (int) ($body['quantity'] ?? 1);

        if ($productId === '') {
            return new JsonResponse(['error' => 'product_id is required'], 400);
        }
        if ($quantity < 1) {
            return new JsonResponse(['error' => 'quantity must be >= 1; use /cart/remove to delete a line'], 400);
        }

        try {
            [$context, $token] = $this->loadContextAndToken($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }

        $lineItem = $this->lineItemFactory->create([
            'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
            'referencedId' => $productId,
            'quantity' => $quantity,
        ], $context);

        $cart = $this->cartService->getCart($token, $context);
        $cart = $this->cartService->add($cart, $lineItem, $context);

        return new JsonResponse($this->serializeCart($cart));
    }

    /**
     * Change quantity on an existing line item. Body: `{ line_item_id,
     * quantity }` where quantity must be > 0; pass 0 to /cart/remove
     * instead.
     *
     * @Route(
     *     "/wiswes/_mcp/cart/update",
     *     name="wiswes._mcp.cart.update",
     *     methods={"POST"}
     * )
     */
    public function update(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $lineItemId = trim((string) ($body['line_item_id'] ?? ''));
        $quantity = (int) ($body['quantity'] ?? 0);

        if ($lineItemId === '') {
            return new JsonResponse(['error' => 'line_item_id is required'], 400);
        }
        if ($quantity < 1) {
            return new JsonResponse(['error' => 'quantity must be >= 1; use /cart/remove to delete a line'], 400);
        }

        try {
            [$context, $token] = $this->loadContextAndToken($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }

        $cart = $this->cartService->getCart($token, $context);
        $line = $cart->get($lineItemId);
        if ($line === null) {
            return new JsonResponse(['error' => 'line_item_id not found in cart'], 404);
        }

        $line->setQuantity($quantity);
        $cart = $this->cartService->recalculate($cart, $context);

        return new JsonResponse($this->serializeCart($cart));
    }

    /**
     * Remove one line item by its id.
     *
     * @Route(
     *     "/wiswes/_mcp/cart/remove",
     *     name="wiswes._mcp.cart.remove",
     *     methods={"POST"}
     * )
     */
    public function remove(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $lineItemId = trim((string) ($body['line_item_id'] ?? ''));

        if ($lineItemId === '') {
            return new JsonResponse(['error' => 'line_item_id is required'], 400);
        }

        try {
            [$context, $token] = $this->loadContextAndToken($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }

        $cart = $this->cartService->getCart($token, $context);
        if ($cart->get($lineItemId) === null) {
            return new JsonResponse(['error' => 'line_item_id not found in cart'], 404);
        }
        $cart = $this->cartService->remove($cart, $lineItemId, $context);

        return new JsonResponse($this->serializeCart($cart));
    }

    // -- helpers ---------------------------------------------------------

    /**
     * Build a SalesChannelContext from the request and return it
     * alongside the explicit cart token chat_agent wants to use.
     *
     * The cart token is what `CartService::getCart($token, $context)`
     * keys against in the persister — it's how a follow-up call lands
     * on the SAME cart created by a prior call. SalesChannelContext
     * itself can carry a different token internally (Shopware's
     * context service may regenerate); what matters for cart
     * continuity is the explicit $token we hand to getCart().
     *
     * Throws when sales_channel_id can't be resolved.
     *
     * @return array{0: SalesChannelContext, 1: string}
     */
    private function loadContextAndToken(Request $request): array
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

        $context = $this->contextService->get(new SalesChannelContextServiceParameters(
            $salesChannelId,
            $token,
        ));

        // Pin the context under our explicit token so a follow-up
        // call lands on the same persisted state. Without this Shopware
        // mints a fresh token in the next request, the cart's row gets
        // orphaned, and chat_agent sees an empty cart on every read.
        $this->contextPersister->save($token, [], $salesChannelId);

        return [$context, $token];
    }

    /**
     * Boil the cart down to the fields the LLM will quote back to the
     * shopper. Stripping the rest avoids inflating tool-result tokens
     * with internal state (deltas, errors-already-shown, etc.).
     *
     * @return array<string, mixed>
     */
    private function serializeCart(Cart $cart): array
    {
        $lineItems = [];
        foreach ($cart->getLineItems() as $line) {
            $price = $line->getPrice();
            $lineItems[] = [
                'id' => $line->getId(),
                'product_id' => $line->getReferencedId(),
                'name' => $line->getLabel(),
                'quantity' => $line->getQuantity(),
                'unit_price' => $price?->getUnitPrice(),
                'total_price' => $price?->getTotalPrice(),
            ];
        }
        $errors = [];
        foreach ($cart->getErrors() as $err) {
            // Cart errors are how Shopware reports "product not sellable",
            // "stock exhausted", "min/max quantity violations" etc. They
            // don't throw — they accumulate on the cart and the LLM
            // needs to see them to recover (e.g. lower the quantity).
            $errors[] = [
                'level' => method_exists($err, 'getLevel') ? $err->getLevel() : null,
                'key' => method_exists($err, 'getMessageKey') ? $err->getMessageKey() : null,
                'message' => method_exists($err, 'getMessage') ? $err->getMessage() : (string) $err,
            ];
        }

        $price = $cart->getPrice();
        return [
            'token' => $cart->getToken(),
            'line_items' => $lineItems,
            'item_count' => count($lineItems),
            'total_price' => $price->getTotalPrice(),
            'net_price' => $price->getNetPrice(),
            'errors' => $errors,
        ];
    }
}
