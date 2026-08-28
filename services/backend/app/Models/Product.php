<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductSource;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A product scraped from a storefront.
 *
 * Prices are stored as integers in minor units (piastres for EGP, cents for
 * USD). Floating-point money accumulates rounding errors, so the database
 * holds exact integers and formatting happens at the edges - see
 * priceInMajorUnits() below and formatPrice() on the frontend.
 *
 * @property int $id
 * @property string $title
 * @property int $price Minor units, e.g. 1999 means 19.99
 * @property string $currency ISO 4217 code, e.g. "EGP"
 * @property string|null $image_url
 * @property ProductSource $source
 * @property string $source_url
 * @property Carbon|null $scraped_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
// The #[Fillable] attribute is Laravel 13's replacement for the $fillable
// property. It is an explicit allowlist rather than `$guarded = []`: scraped
// data is untrusted input, and an allowlist means a future column cannot be
// written by accident just because a parser happened to return that key.
#[Fillable(['title', 'price', 'currency', 'image_url', 'source', 'source_url', 'scraped_at'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Attribute casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'source' => ProductSource::class,
            'scraped_at' => 'datetime',
        ];
    }

    /**
     * The price as a decimal in major units, for display.
     *
     * Kept as a plain method rather than an accessor so it never leaks into
     * the database layer or gets persisted by accident.
     */
    public function priceInMajorUnits(): float
    {
        return $this->price / 100;
    }
}
