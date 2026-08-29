<?php

declare(strict_types=1);

use App\Enums\ProductSource;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductSeeder;
use Laravel\Sanctum\Sanctum;

describe('ProductSeeder', function () {
    it('creates products', function () {
        $this->seed(ProductSeeder::class);

        expect(Product::count())->toBeGreaterThan(10);
    });

    it('seeds both storefronts', function () {
        $this->seed(ProductSeeder::class);

        expect(Product::where('source', ProductSource::Jumia)->count())->toBeGreaterThan(0)
            ->and(Product::where('source', ProductSource::Amazon)->count())->toBeGreaterThan(0);
    });

    // So the placeholder path is visible during a demo, not just in a test.
    it('includes one product without an image', function () {
        $this->seed(ProductSeeder::class);

        expect(Product::whereNull('image_url')->count())->toBe(1);
    });

    it('gives every product a unique source URL', function () {
        $this->seed(ProductSeeder::class);

        expect(Product::distinct('source_url')->count('source_url'))->toBe(Product::count());
    });

    it('produces products the API can serve', function () {
        $this->seed(ProductSeeder::class);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('meta.total', Product::count())
            ->assertJsonStructure(['data' => [['id', 'title', 'price_formatted', 'source']]]);
    });
});

describe('DatabaseSeeder', function () {
    it('seeds products through the default seeder', function () {
        $this->seed(DatabaseSeeder::class);

        expect(Product::count())->toBeGreaterThan(0);
    });

    // Without model events the observer never fires, and the API would keep
    // serving an empty cached catalogue while the database is full.
    it('leaves model events enabled so the cache is invalidated', function () {
        Sanctum::actingAs(User::factory()->create());

        // Warm the cache while the table is empty.
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 0);

        $this->seed(DatabaseSeeder::class);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('meta.total', Product::count());
    });
});
