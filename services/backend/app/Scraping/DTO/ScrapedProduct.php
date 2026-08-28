<?php

declare(strict_types=1);

namespace App\Scraping\DTO;

use App\Enums\ProductSource;

/**
 * One product, as extracted from a page but not yet stored.
 *
 * Why this exists rather than passing arrays around: a parser returning
 * `['titel' => ...]` would fail silently somewhere downstream. A readonly
 * class makes the shape explicit, gives every field a type, and cannot be
 * mutated between parsing and persistence - so what the parser produced is
 * exactly what gets stored.
 */
final readonly class ScrapedProduct
{
    /**
     * @param  string  $title  Product title, already trimmed.
     * @param  int  $price  Price in minor units (piastres/cents).
     * @param  string  $currency  ISO 4217 code, uppercase.
     * @param  string|null  $imageUrl  Absolute URL, or null when the page had no image.
     * @param  ProductSource  $source  Which storefront this came from.
     * @param  string  $sourceUrl  The page this was scraped from; the upsert key.
     */
    public function __construct(
        public string $title,
        public int $price,
        public string $currency,
        public ?string $imageUrl,
        public ProductSource $source,
        public string $sourceUrl,
    ) {}

    /**
     * Convert to the array shape the Product model expects.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'title' => $this->title,
            'price' => $this->price,
            'currency' => $this->currency,
            'image_url' => $this->imageUrl,
            'source' => $this->source,
            'source_url' => $this->sourceUrl,
            'scraped_at' => now(),
        ];
    }
}
