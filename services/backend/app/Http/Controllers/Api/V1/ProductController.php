<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProductResource;
use App\Models\Product;
use App\Support\ProductCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serves the stored products.
 *
 * This endpoint is polled by every open browser tab every 30 seconds, so it is
 * the hottest route in the system and gets three layers of caching:
 *
 *   1. Application cache  The query result, via ProductCache (stale-while-
 *                         revalidate, so nobody waits for a rebuild).
 *   2. ETag               A repeat request with a matching ETag gets a 304
 *                         with an empty body - no JSON serialised, no bytes
 *                         on the wire.
 *   3. Cache-Control      Lets the browser reuse the response without asking
 *                         at all for a few seconds.
 *
 * The X-Cache header reports HIT or MISS, which makes the caching visible in
 * a terminal during a demo.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductCache $cache,
    ) {}

    /**
     * List products, newest first.
     *
     * Query parameters:
     *   per_page  1-100, default 24 (a multiple of 2, 3 and 4, so the grid
     *             fills evenly at every breakpoint)
     *   page      1-based page number
     *   source    optional filter: "jumia" or "amazon"
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'source' => ['sometimes', 'string', 'in:'.implode(',', array_column(ProductSource::cases(), 'value'))],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 24);
        $page = (int) ($validated['page'] ?? 1);
        $source = $validated['source'] ?? null;

        $cacheKey = $this->cache->keyForQuery([
            'per_page' => $perPage,
            'page' => $page,
            'source' => $source,
        ]);

        // Track whether the closure actually ran, so the X-Cache header can
        // report the truth rather than a guess.
        $wasMiss = false;

        $payload = $this->cache->remember($cacheKey, function () use ($perPage, $page, $source, &$wasMiss): array {
            $wasMiss = true;

            $products = Product::query()
                ->when($source !== null, fn ($query) => $query->where('source', $source))
                ->latest('created_at')
                ->latest('id') // Deterministic order when timestamps collide.
                ->paginate(perPage: $perPage, page: $page);

            return [
                'data' => ProductResource::collection($products->items())->resolve(),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
            ];
        });

        return $this->cacheableJson($request, $payload, $wasMiss);
    }

    /**
     * Show one product.
     *
     * Route-model binding means a missing id returns 404 automatically.
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        return $this->cacheableJson(
            $request,
            ['data' => (new ProductResource($product))->resolve($request)],
            wasMiss: true,
        );
    }

    /**
     * Attach ETag and Cache-Control headers, and short-circuit to 304 when the
     * client already holds this exact payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function cacheableJson(Request $request, array $payload, bool $wasMiss): JsonResponse
    {
        $body = (string) json_encode($payload);
        $etag = '"'.md5($body).'"';

        $maxAge = (int) config('scraping.cache.http_max_age', 15);
        $staleWhileRevalidate = (int) config('scraping.cache.stale_seconds', 120);

        $headers = [
            'ETag' => $etag,
            'Cache-Control' => sprintf(
                'public, max-age=%d, stale-while-revalidate=%d',
                $maxAge,
                $staleWhileRevalidate,
            ),
            'X-Cache' => $wasMiss ? 'MISS' : 'HIT',
        ];

        // If-None-Match may carry several ETags, or the weak "W/" prefix.
        $clientEtags = array_map(
            static fn (string $tag): string => trim(str_replace('W/', '', trim($tag))),
            explode(',', (string) $request->header('If-None-Match', '')),
        );

        if (in_array($etag, $clientEtags, true)) {
            // 304 must not carry a body - that is the entire saving.
            return response()->json(null, 304, $headers);
        }

        return response()->json($payload, 200, $headers);
    }
}
