<?php

declare(strict_types=1);

namespace App\Scraping\Pipeline;

use App\Models\Product;
use App\Scraping\DTO\ScrapedProduct;
use App\Scraping\Exceptions\ScrapeFailedException;

/**
 * Validates, normalises and stores a scraped product.
 *
 * Why this is separate from the parsers: parsers should only understand HTML.
 * Everything that is true of *every* product regardless of which site it came
 * from - the title cannot be empty, the price must be positive, the currency
 * is three uppercase letters - belongs in one place, so the rules cannot drift
 * apart between storefronts.
 *
 * The name mirrors the item pipelines in Scrapy and Roach PHP, which solve the
 * same problem the same way.
 */
class ItemPipeline
{
    /**
     * Longest title we will store. The column is a 255-character VARCHAR, and
     * silently truncating in the database would be worse than doing it here
     * deliberately.
     */
    private const MAX_TITLE_LENGTH = 255;

    /**
     * A price above this is treated as a parsing error rather than a product.
     * 100 million major units - far beyond any real retail listing, but low
     * enough to catch a mis-parsed string of digits.
     */
    private const MAX_PRICE_MINOR_UNITS = 10_000_000_000;

    /**
     * Run a scraped product through validation, normalisation and storage.
     *
     * @throws ScrapeFailedException when the product cannot be stored.
     */
    public function process(ScrapedProduct $product): Product
    {
        $normalised = $this->normalise($product);

        $this->validate($normalised);

        return $this->persist($normalised);
    }

    /**
     * Clean up the values a parser produced.
     */
    public function normalise(ScrapedProduct $product): ScrapedProduct
    {
        $title = trim($product->title);

        // Truncate on a word boundary where possible, so a cut title still
        // reads as words rather than ending mid-syllable.
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            $title = mb_substr($title, 0, self::MAX_TITLE_LENGTH);

            $lastSpace = mb_strrpos($title, ' ');
            if ($lastSpace !== false && $lastSpace > self::MAX_TITLE_LENGTH - 30) {
                $title = mb_substr($title, 0, $lastSpace);
            }
        }

        return new ScrapedProduct(
            title: $title,
            price: $product->price,
            currency: strtoupper(trim($product->currency)),
            imageUrl: $this->normaliseImageUrl($product->imageUrl),
            source: $product->source,
            sourceUrl: trim($product->sourceUrl),
        );
    }

    /**
     * Reject anything that would store a meaningless product.
     *
     * @throws ScrapeFailedException
     */
    public function validate(ScrapedProduct $product): void
    {
        if ($product->title === '') {
            throw new ScrapeFailedException('The scraped product has no title.');
        }

        if ($product->price <= 0) {
            throw new ScrapeFailedException(
                sprintf('The scraped price (%d) is not a positive amount.', $product->price),
            );
        }

        if ($product->price > self::MAX_PRICE_MINOR_UNITS) {
            throw new ScrapeFailedException(
                sprintf('The scraped price (%d) is implausibly large; treating it as a parse error.', $product->price),
            );
        }

        if (preg_match('/^[A-Z]{3}$/', $product->currency) !== 1) {
            throw new ScrapeFailedException(
                sprintf('The currency [%s] is not a three-letter ISO 4217 code.', $product->currency),
            );
        }

        if (filter_var($product->sourceUrl, FILTER_VALIDATE_URL) === false) {
            throw new ScrapeFailedException('The product has no valid source URL.');
        }
    }

    /**
     * Insert or update the product, keyed on its source URL.
     *
     * updateOrCreate against the unique source_url column is what makes
     * scraping idempotent: running the same URL a hundred times refreshes one
     * row rather than creating a hundred.
     */
    private function persist(ScrapedProduct $product): Product
    {
        return Product::updateOrCreate(
            ['source_url' => $product->sourceUrl],
            $product->toAttributes(),
        );
    }

    /**
     * Drop an image URL that is not a usable absolute HTTP(S) address.
     *
     * A null image is fine - the frontend renders a placeholder. A malformed
     * one produces a broken image, which looks worse.
     */
    private function normaliseImageUrl(?string $imageUrl): ?string
    {
        if ($imageUrl === null || trim($imageUrl) === '') {
            return null;
        }

        $imageUrl = trim($imageUrl);

        if (filter_var($imageUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $imageUrl : null;
    }
}
