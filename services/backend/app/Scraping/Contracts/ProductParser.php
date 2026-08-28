<?php

declare(strict_types=1);

namespace App\Scraping\Contracts;

use App\Enums\ProductSource;
use App\Scraping\DTO\ScrapedProduct;

/**
 * Turns one storefront's HTML into a ScrapedProduct.
 *
 * Why this seam exists: every site marks up prices and titles differently, but
 * nothing else about scraping changes between them. Isolating the
 * site-specific part behind this interface means supporting a new storefront
 * is exactly one new class plus one entry in config/scraping.php - the
 * pipeline, proxy rotation and persistence are untouched.
 */
interface ProductParser
{
    /**
     * Which storefront this parser understands.
     */
    public function source(): ProductSource;

    /**
     * Extract a product from a page.
     *
     * @param  string  $html  The raw page body.
     * @param  string  $url  The URL it was fetched from, used to resolve
     *                       relative image paths and to set sourceUrl.
     * @return ScrapedProduct|null Null when the page has no recognisable
     *                             product - a blocked page, a CAPTCHA, or a
     *                             layout change. Returning null rather than
     *                             throwing keeps one bad page from failing a
     *                             whole batch.
     */
    public function parse(string $html, string $url): ?ScrapedProduct;
}
