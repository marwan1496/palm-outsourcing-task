<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductSource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds Product rows for tests and demo seeding.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Default attributes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $source = fake()->randomElement(ProductSource::cases());

        return [
            'title' => ucwords(fake()->words(4, true)),

            // 50.00 to 25,000.00 in minor units - a realistic retail spread.
            'price' => fake()->numberBetween(5_000, 2_500_000),
            'currency' => $source === ProductSource::Jumia ? 'EGP' : 'USD',

            'image_url' => 'https://picsum.photos/seed/'.Str::random(10).'/600/600',

            'source' => $source,
            // Unique, because source_url carries a unique index.
            'source_url' => 'https://www.'.$this->hostFor($source).'/product/'.Str::uuid().'.html',

            'scraped_at' => now(),
        ];
    }

    /**
     * A product from Jumia.
     */
    public function jumia(): static
    {
        return $this->state(fn (): array => [
            'source' => ProductSource::Jumia,
            'currency' => 'EGP',
            'source_url' => 'https://www.jumia.com.eg/product/'.Str::uuid().'.html',
        ]);
    }

    /**
     * A product from Amazon.
     */
    public function amazon(): static
    {
        return $this->state(fn (): array => [
            'source' => ProductSource::Amazon,
            'currency' => 'USD',
            'source_url' => 'https://www.amazon.eg/dp/'.Str::upper(Str::random(10)),
        ]);
    }

    /**
     * A product with no image, to exercise the frontend's placeholder.
     */
    public function withoutImage(): static
    {
        return $this->state(fn (): array => ['image_url' => null]);
    }

    /**
     * The primary domain for a storefront.
     */
    private function hostFor(ProductSource $source): string
    {
        return $source->hostPatterns()[0];
    }
}
