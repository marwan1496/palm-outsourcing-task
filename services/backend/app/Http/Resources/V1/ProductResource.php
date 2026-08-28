<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a Product for the API.
 *
 * Why a Resource rather than returning the model: a model returned directly
 * serialises every column, so any future column is published the moment it is
 * added. This is an explicit contract - the frontend depends on these exact
 * keys, and nothing reaches a client unless it is listed here.
 *
 * The model is held in a typed property rather than reached through
 * JsonResource's magic __get. That keeps the class honest about what it wraps,
 * and means static analysis can actually check these field accesses instead of
 * seeing an untyped mixed.
 *
 * Prices are exposed twice on purpose:
 *   price           integer minor units - exact, for any arithmetic
 *   price_formatted string - already localised, so the frontend does not have
 *                   to know that EGP has two decimal places
 */
class ProductResource extends JsonResource
{
    /**
     * The product being presented.
     */
    private readonly Product $product;

    public function __construct(Product $resource)
    {
        parent::__construct($resource);

        $this->product = $resource;
    }

    /**
     * Transform the product into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->product->id,
            'title' => $this->product->title,

            'price' => $this->product->price,
            'price_formatted' => $this->formatPrice(),
            'currency' => $this->product->currency,

            'image_url' => $this->product->image_url,

            'source' => $this->product->source->value,
            'source_label' => $this->product->source->label(),
            'source_url' => $this->product->source_url,

            'scraped_at' => $this->product->scraped_at?->toIso8601String(),
            'created_at' => $this->product->created_at->toIso8601String(),
        ];
    }

    /**
     * Format the price for display, e.g. "EGP 1,299.00".
     */
    private function formatPrice(): string
    {
        return sprintf(
            '%s %s',
            $this->product->currency,
            number_format($this->product->priceInMajorUnits(), 2),
        );
    }
}
