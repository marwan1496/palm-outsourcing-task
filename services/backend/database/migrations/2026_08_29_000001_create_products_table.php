<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the products table.
     *
     * The brief asks for id, title, price, image_url and created_at. The extra
     * columns each earn their place:
     *
     *   source_url  Unique, and the key we upsert on. Re-scraping the same page
     *               updates the existing row instead of creating duplicates,
     *               which is what makes the scraper safe to run repeatedly.
     *   source      Which storefront the page came from, so the right parser
     *               can be chosen when re-scraping.
     *   currency    A price without a currency is not a price. Jumia Egypt
     *               quotes EGP, Amazon UK quotes GBP.
     *   scraped_at  Distinct from created_at: when we last *looked*, versus
     *               when we first stored it. The frontend shows freshness.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            $table->string('title');

            // Stored as an unsigned integer in minor units (piastres, cents).
            // Why not DECIMAL: money in floats accumulates rounding errors,
            // and integers make comparisons and sums exact.
            $table->unsignedBigInteger('price');
            $table->string('currency', 3)->default('EGP');

            // Image URLs from CDNs routinely exceed 255 characters once signing
            // parameters are appended, so this is a TEXT column.
            $table->text('image_url')->nullable();

            $table->string('source', 32);

            // 512 is comfortably above real product URLs while staying inside
            // MySQL's index key length limit for utf8mb4.
            $table->string('source_url', 512)->unique();

            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();

            // The products listing is ordered newest-first and often filtered
            // by source, so both columns are indexed.
            $table->index('created_at');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
