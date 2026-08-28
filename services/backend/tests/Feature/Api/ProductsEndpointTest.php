<?php

declare(strict_types=1);

use App\Enums\ProductSource;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('authentication', function () {
    // The catalogue is the product of work paid for in proxy traffic. An
    // unauthenticated listing endpoint is a free API for anyone who finds it.
    it('rejects an unauthenticated request with 401', function () {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    });

    it('rejects a bogus bearer token with 401', function () {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/products')
            ->assertUnauthorized();
    });

    it('allows a request carrying a valid token', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/products')->assertOk();
    });

    it('protects the single-product endpoint too', function () {
        $product = Product::factory()->create();

        $this->getJson("/api/v1/products/{$product->id}")->assertUnauthorized();
    });

    it('protects the /api/products alias named in the brief', function () {
        $this->getJson('/api/products')->assertUnauthorized();
    });
});

describe('listing products', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
    });

    it('returns the stored products as JSON', function () {
        Product::factory()->count(3)->create();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'title', 'price', 'price_formatted', 'currency', 'image_url', 'source', 'source_url', 'created_at']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    });

    it('returns an empty list rather than an error when there are no products', function () {
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    });

    it('orders products newest first', function () {
        $older = Product::factory()->create(['created_at' => now()->subDay()]);
        $newer = Product::factory()->create(['created_at' => now()]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    });

    it('exposes the price as exact minor units and a formatted string', function () {
        Product::factory()->create(['price' => 129_900, 'currency' => 'EGP']);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.price', 129_900)
            ->assertJsonPath('data.0.price_formatted', 'EGP 1,299.00');
    });

    it('never leaks columns that are not in the resource', function () {
        Product::factory()->create();

        $keys = array_keys($this->getJson('/api/v1/products')->json('data.0'));

        expect($keys)->not->toContain('updated_at');
    });

    it('serves the /api/products alias from the same controller', function () {
        Product::factory()->count(2)->create();

        $this->getJson('/api/products')->assertOk()->assertJsonCount(2, 'data');
    });
});

describe('pagination and filtering', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
    });

    it('paginates with a default page size of 24', function () {
        Product::factory()->count(30)->create();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(24, 'data')
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('accepts a custom page size', function () {
        Product::factory()->count(10)->create();

        $this->getJson('/api/v1/products?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    });

    it('returns the requested page', function () {
        Product::factory()->count(10)->create();

        $this->getJson('/api/v1/products?per_page=5&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2);
    });

    it('filters by storefront', function () {
        Product::factory()->count(2)->jumia()->create();
        Product::factory()->count(3)->amazon()->create();

        $this->getJson('/api/v1/products?source=jumia')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', ProductSource::Jumia->value);
    });

    it('rejects invalid query parameters', function (string $query) {
        $this->getJson("/api/v1/products?{$query}")->assertStatus(422);
    })->with([
        'per_page above the cap' => 'per_page=500',
        'per_page of zero' => 'per_page=0',
        'negative page' => 'page=-1',
        'unknown source' => 'source=ebay',
    ]);
});

describe('single product', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
    });

    it('returns one product', function () {
        $product = Product::factory()->create(['title' => 'A Specific Product']);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'A Specific Product');
    });

    it('returns 404 for an id that does not exist', function () {
        $this->getJson('/api/v1/products/999999')->assertNotFound();
    });
});

describe('caching', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
    });

    it('reports a cache miss on the first request and a hit on the second', function () {
        Product::factory()->count(2)->create();

        $this->getJson('/api/v1/products')->assertOk()->assertHeader('X-Cache', 'MISS');
        $this->getJson('/api/v1/products')->assertOk()->assertHeader('X-Cache', 'HIT');
    });

    it('sends an ETag and a Cache-Control header', function () {
        Product::factory()->create();

        $response = $this->getJson('/api/v1/products')->assertOk();

        expect($response->headers->get('ETag'))->not->toBeEmpty()
            ->and($response->headers->get('Cache-Control'))->toContain('max-age');
    });

    // The whole point of an ETag: a repeat request serialises no JSON and
    // sends no body.
    it('returns 304 with an empty body when the ETag matches', function () {
        Product::factory()->create();

        $etag = $this->getJson('/api/v1/products')->headers->get('ETag');

        $response = $this->withHeader('If-None-Match', $etag)
            ->getJson('/api/v1/products')
            ->assertStatus(304);

        expect($response->getContent())->toBe('');
    });

    it('returns a fresh 200 when the ETag does not match', function () {
        Product::factory()->create();

        $this->withHeader('If-None-Match', '"stale-etag"')
            ->getJson('/api/v1/products')
            ->assertOk();
    });

    it('caches each page separately', function () {
        Product::factory()->count(30)->create();

        $this->getJson('/api/v1/products?page=1')->assertHeader('X-Cache', 'MISS');
        $this->getJson('/api/v1/products?page=2')->assertHeader('X-Cache', 'MISS');
        $this->getJson('/api/v1/products?page=1')->assertHeader('X-Cache', 'HIT');
    });

    // Without invalidation a freshly scraped product would be invisible for up
    // to two minutes, which during a demo reads as "the scraper is broken".
    it('invalidates the cache as soon as a product is stored', function () {
        Product::factory()->count(2)->create();

        $this->getJson('/api/v1/products')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/products')->assertHeader('X-Cache', 'HIT');

        Product::factory()->create();

        $this->getJson('/api/v1/products')
            ->assertHeader('X-Cache', 'MISS')
            ->assertJsonCount(3, 'data');
    });

    it('invalidates the cache when a product is deleted', function () {
        $products = Product::factory()->count(3)->create();

        $this->getJson('/api/v1/products')->assertJsonCount(3, 'data');

        $products->first()->delete();

        $this->getJson('/api/v1/products')
            ->assertHeader('X-Cache', 'MISS')
            ->assertJsonCount(2, 'data');
    });
});

describe('security headers', function () {
    it('sets defence-in-depth headers on API responses', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    });

    // Without Accept: application/json, Laravel renders errors as HTML.
    // ForceJsonResponse makes the API answer JSON regardless of client setup.
    it('answers with JSON even when the client sends no Accept header', function () {
        $response = $this->get('/api/v1/products');

        $response->assertUnauthorized();
        expect($response->headers->get('Content-Type'))->toContain('json');
    });
});
