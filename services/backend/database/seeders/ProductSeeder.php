<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Fills the catalogue with believable products.
 *
 * The point is a demo that doesn't open on an empty grid. Scraping real pages
 * takes time and depends on the sites cooperating, so seeded data gives you
 * something to look at immediately, and live scraping can then be shown as the
 * thing it is rather than as the only way to get any data at all.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Roughly the mix you'd get from real use, and enough to fill the grid
        // at every breakpoint without needing to scroll.
        Product::factory()->count(9)->jumia()->create();
        Product::factory()->count(6)->amazon()->create();

        // One without an image, so the frontend's placeholder path is visible
        // in the demo rather than only in a test.
        Product::factory()->withoutImage()->jumia()->create();
    }
}
