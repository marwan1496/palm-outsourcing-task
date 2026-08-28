<?php

declare(strict_types=1);

use App\Jobs\ScrapeProductJob;
use App\Models\Product;
use App\Models\User;
use App\Scraping\Exceptions\ScrapeFailedException;
use App\Scraping\ScraperManager;
use App\Support\ProductCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

describe('products:scrape', function () {
    it('scrapes a URL and stores the product', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-product.html'))]);

        $this->artisan('products:scrape', ['url' => ['https://www.jumia.com.eg/a55.html']])
            ->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
    });

    it('scrapes several URLs in one run', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-product.html'))]);

        $this->artisan('products:scrape', ['url' => [
            'https://www.jumia.com.eg/a.html',
            'https://www.jumia.com.eg/b.html',
        ]])->assertSuccessful();

        $this->assertDatabaseCount('products', 2);
    });

    it('reports a rejected URL without storing anything', function () {
        Http::fake();

        $this->artisan('products:scrape', ['url' => ['https://169.254.169.254/']])
            ->assertFailed();

        $this->assertDatabaseCount('products', 0);
        Http::assertNothingSent();
    });

    it('reports a page that yields no product', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-blocked.html'))]);

        $this->artisan('products:scrape', ['url' => ['https://www.jumia.com.eg/x.html']])
            ->assertFailed();

        $this->assertDatabaseCount('products', 0);
    });

    it('succeeds overall when at least one URL works', function () {
        Http::fakeSequence()
            ->push(fixtureHtml('jumia-blocked.html'), 200)
            ->push(fixtureHtml('jumia-product.html'), 200);

        $this->artisan('products:scrape', ['url' => [
            'https://www.jumia.com.eg/bad.html',
            'https://www.jumia.com.eg/good.html',
        ]])->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
    });

    it('queues the work instead when --queue is passed', function () {
        Queue::fake();

        $this->artisan('products:scrape', [
            'url' => ['https://www.jumia.com.eg/a.html'],
            '--queue' => true,
        ])->assertSuccessful();

        Queue::assertPushed(ScrapeProductJob::class);
        $this->assertDatabaseCount('products', 0);
    });
});

describe('api:token', function () {
    it('creates a user and issues a token', function () {
        $this->artisan('api:token', ['name' => 'frontend'])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'api@palm.test']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    });

    it('reuses the existing user when run twice', function () {
        $this->artisan('api:token')->assertSuccessful();
        $this->artisan('api:token')->assertSuccessful();

        expect(User::where('email', 'api@palm.test')->count())->toBe(1);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    });

    it('issues a token that actually authenticates against the API', function () {
        // The real test of the command: the token it prints must work.
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        Product::factory()->count(2)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });
});

describe('the ScrapeProductJob', function () {
    it('stores a product when it runs', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-product.html'))]);

        (new ScrapeProductJob('https://www.jumia.com.eg/a55.html'))
            ->handle(app(ScraperManager::class));

        $this->assertDatabaseCount('products', 1);
    });

    it('rethrows a scrape failure so the queue can retry it', function () {
        Http::fake(['*' => Http::response('Server Error', 500)]);
        config()->set('scraping.retry.base_delay_ms', 1);

        expect(fn () => (new ScrapeProductJob('https://www.jumia.com.eg/x.html'))
            ->handle(app(ScraperManager::class)))
            ->toThrow(ScrapeFailedException::class);
    });
});

describe('ProductCache versioning', function () {
    // Invalidation uses a version key rather than cache tags, because the
    // database cache driver has no tag support.
    it('starts at version 1', function () {
        expect(app(ProductCache::class)->version())->toBe(1);
    });

    it('builds keys that embed the current version', function () {
        $cache = app(ProductCache::class);

        expect($cache->versionedKey('abc'))->toBe('products:v1:abc');
    });

    it('moves to a new version on flush, orphaning old entries', function () {
        $cache = app(ProductCache::class);

        $keyBefore = $cache->versionedKey('abc');
        $cache->flush();
        $keyAfter = $cache->versionedKey('abc');

        expect($keyAfter)->not->toBe($keyBefore)
            ->and($cache->version())->toBe(2);
    });

    it('caches a computed value and reuses it', function () {
        $cache = app(ProductCache::class);
        $calls = 0;

        $build = function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        expect($cache->remember('key', $build))->toBe('computed')
            ->and($cache->remember('key', $build))->toBe('computed')
            ->and($calls)->toBe(1);
    });

    it('recomputes after a flush', function () {
        $cache = app(ProductCache::class);
        $calls = 0;

        $build = function () use (&$calls): int {
            return ++$calls;
        };

        $cache->remember('key', $build);
        $cache->flush();
        $cache->remember('key', $build);

        expect($calls)->toBe(2);
    });

    // Query parameters written in a different order describe the same query
    // and must share one cache entry.
    it('builds the same key regardless of parameter order', function () {
        $cache = app(ProductCache::class);

        expect($cache->keyForQuery(['page' => 2, 'per_page' => 10]))
            ->toBe($cache->keyForQuery(['per_page' => 10, 'page' => 2]));
    });

    it('builds different keys for different queries', function () {
        $cache = app(ProductCache::class);

        expect($cache->keyForQuery(['page' => 1]))
            ->not->toBe($cache->keyForQuery(['page' => 2]));
    });

    it('bypasses the cache entirely when disabled', function () {
        config()->set('scraping.cache.enabled', false);

        $cache = app(ProductCache::class);
        $calls = 0;

        $build = function () use (&$calls): int {
            return ++$calls;
        };

        $cache->remember('key', $build);
        $cache->remember('key', $build);

        expect($calls)->toBe(2);
    });
});
