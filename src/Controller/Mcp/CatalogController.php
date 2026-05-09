<?php declare(strict_types=1);

namespace Wiswes\Widget\Controller\Mcp;

use Shopware\Core\Content\Category\SalesChannel\AbstractNavigationRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\AbstractProductListingRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * MCP catalog tools — what `chat_agent` calls when the LLM needs to
 * find or describe products or categories.
 *
 * Mirrors Magento's Mcp/Tool/Catalog/{ProductFilter,ProductGet,
 * CategoryList,ProductFilterOptions}Tool, delegating to Shopware's
 * Store API routes (`ProductListingRoute`, `ProductDetailRoute`,
 * `NavigationRoute`) so the data shape matches what the storefront
 * itself sees. Read-only — no cart-context quirk like Phase 2.
 *
 * Auth: handled upstream by `BearerTokenSubscriber`. By the time a
 * method here runs, the bearer token has already been verified.
 *
 * @Route(defaults={"_routeScope"={"storefront"}, "_loginRequired"=false})
 */
class CatalogController extends AbstractController
{
    public function __construct(
        private readonly AbstractProductListingRoute $listingRoute,
        private readonly AbstractProductDetailRoute $detailRoute,
        private readonly AbstractNavigationRoute $navigationRoute,
        private readonly SalesChannelContextService $contextService,
    ) {
    }

    /**
     * Search/filter products. Body: `{ query?: string, category_id?: hex,
     * limit?: int (default 12, max 50), page?: int (default 1) }`.
     *
     * @Route(
     *     "/wiswes/_mcp/catalog/product_filter",
     *     name="wiswes._mcp.catalog.product_filter",
     *     methods={"POST"}
     * )
     */
    public function productFilter(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?: [];
        try {
            $context = $this->loadContext($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }

        $limit = max(1, min(50, (int) ($body['limit'] ?? 12)));
        $page = max(1, (int) ($body['page'] ?? 1));
        $query = trim((string) ($body['query'] ?? ''));
        $categoryId = trim((string) ($body['category_id'] ?? ''));

        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->setOffset(($page - 1) * $limit);

        // Use a query string when we have one; otherwise the listing
        // route falls back to the active sales channel's full catalog.
        if ($query !== '') {
            $listingRequest = new Request([], [], [], [], [], [], '');
            $listingRequest->request->set('search', $query);
            $listingRequest->query->set('search', $query);
        } else {
            $listingRequest = new Request();
        }
        $listingRequest->request->set('limit', $limit);
        $listingRequest->request->set('p', $page);

        // Category id is required by ProductListingRoute. Fall back to
        // the sales channel's navigation root when the caller doesn't
        // specify one — that's the "all products" view a storefront
        // visitor would see if they hit the home page.
        if ($categoryId === '') {
            $categoryId = $context->getSalesChannel()->getNavigationCategoryId();
        }

        $result = $this->listingRoute->load($categoryId, $listingRequest, $context, $criteria)->getResult();

        $products = [];
        foreach ($result->getEntities() as $product) {
            $price = $product->getCalculatedPrice();
            $products[] = [
                'id' => $product->getId(),
                'product_number' => $product->getProductNumber(),
                'name' => $product->getTranslation('name') ?? $product->getName(),
                'description_short' => substr(strip_tags((string) ($product->getTranslation('description') ?? $product->getDescription() ?? '')), 0, 280),
                'price' => $price?->getTotalPrice(),
                'available' => $product->getAvailable(),
                'stock' => $product->getAvailableStock(),
                'cover_image' => $product->getCover()?->getMedia()?->getUrl(),
            ];
        }

        return new JsonResponse([
            'products' => $products,
            'total' => $result->getTotal(),
            'page' => $page,
            'limit' => $limit,
            'query' => $query,
        ]);
    }

    /**
     * Fetch one product by its 32-char hex id.
     *
     * @Route(
     *     "/wiswes/_mcp/catalog/product_get",
     *     name="wiswes._mcp.catalog.product_get",
     *     methods={"POST"}
     * )
     */
    public function productGet(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $productId = trim((string) ($body['product_id'] ?? ''));
        if ($productId === '') {
            return new JsonResponse(['error' => 'product_id is required'], 400);
        }

        try {
            $context = $this->loadContext($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }

        try {
            $product = $this->detailRoute->load($productId, new Request(), $context, new Criteria())->getProduct();
        } catch (\Throwable $exc) {
            return new JsonResponse(['error' => 'product not found', 'product_id' => $productId], 404);
        }

        $price = $product->getCalculatedPrice();
        return new JsonResponse([
            'product' => [
                'id' => $product->getId(),
                'product_number' => $product->getProductNumber(),
                'name' => $product->getTranslation('name') ?? $product->getName(),
                'description' => strip_tags((string) ($product->getTranslation('description') ?? $product->getDescription() ?? '')),
                'price' => $price?->getTotalPrice(),
                'available' => $product->getAvailable(),
                'stock' => $product->getAvailableStock(),
                'manufacturer' => $product->getManufacturer()?->getName(),
                'cover_image' => $product->getCover()?->getMedia()?->getUrl(),
                'product_url' => $product->getSeoUrls()?->first()?->getSeoPathInfo(),
            ],
        ]);
    }

    /**
     * List the active sales channel's category tree, two levels deep
     * — what the storefront mega-menu would render. Returns
     * `{ categories: [{ id, name, child_count }] }`.
     *
     * @Route(
     *     "/wiswes/_mcp/catalog/category_list",
     *     name="wiswes._mcp.catalog.category_list",
     *     methods={"POST", "GET"}
     * )
     */
    public function categoryList(Request $request): JsonResponse
    {
        try {
            $context = $this->loadContext($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }

        $rootId = $context->getSalesChannel()->getNavigationCategoryId();
        $criteria = new Criteria();
        $criteria->setLimit(50);
        $result = $this->navigationRoute->load(
            $rootId,
            $rootId,
            new Request(['depth' => 2]),
            $context,
            $criteria,
        )->getCategories();

        $categories = [];
        foreach ($result as $cat) {
            $categories[] = [
                'id' => $cat->getId(),
                'name' => $cat->getTranslation('name') ?? $cat->getName(),
                'child_count' => $cat->getChildCount() ?? 0,
                'parent_id' => $cat->getParentId(),
            ];
        }

        return new JsonResponse(['categories' => $categories]);
    }

    /**
     * Available filter facets for a query — manufacturer, properties,
     * price range. Same ProductListingRoute as `product_filter` but
     * we return only the aggregations (no product rows) so the LLM
     * can ask follow-up questions like "what colors are available?".
     *
     * @Route(
     *     "/wiswes/_mcp/catalog/product_filter_options",
     *     name="wiswes._mcp.catalog.product_filter_options",
     *     methods={"POST"}
     * )
     */
    public function productFilterOptions(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true) ?: [];
        try {
            $context = $this->loadContext($request);
        } catch (\InvalidArgumentException $exc) {
            return new JsonResponse(['error' => $exc->getMessage()], 400);
        }

        $query = trim((string) ($body['query'] ?? ''));
        $categoryId = trim((string) ($body['category_id'] ?? ''));
        if ($categoryId === '') {
            $categoryId = $context->getSalesChannel()->getNavigationCategoryId();
        }

        $listingRequest = new Request();
        if ($query !== '') {
            $listingRequest->query->set('search', $query);
            $listingRequest->request->set('search', $query);
        }
        $listingRequest->request->set('limit', 1);

        $criteria = new Criteria();
        $criteria->setLimit(1);

        $result = $this->listingRoute->load($categoryId, $listingRequest, $context, $criteria)->getResult();

        $facets = [];
        foreach ($result->getAggregations() as $name => $agg) {
            $facets[$name] = json_decode(json_encode($agg, JSON_PARTIAL_OUTPUT_ON_ERROR), true) ?: [];
        }

        return new JsonResponse([
            'facets' => $facets,
            'total_matching_products' => $result->getTotal(),
            'query' => $query,
        ]);
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
