<?php

declare(strict_types=1);

namespace App\Scraping\Parsers;

use App\Enums\ProductSource;
use App\Scraping\Contracts\ProductParser;
use App\Scraping\DTO\ScrapedProduct;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Extracts a product from a Jumia product page.
 *
 * Strategy, in order of preference:
 *
 *   1. JSON-LD structured data. Jumia embeds a schema.org Product block for
 *      search engines. It is far more stable than CSS classes, which change
 *      whenever the site is restyled, so it is always tried first.
 *   2. CSS selectors against the rendered markup, as a fallback.
 *
 * Preferring structured data is the single biggest reason this parser keeps
 * working across site redesigns.
 *
 * Every extraction step returns null rather than throwing when it finds
 * nothing. A blocked page or a CAPTCHA is an expected outcome for a scraper,
 * not an exceptional one.
 */
class JumiaParser implements ProductParser
{
    use ParsesProductPages;

    public function source(): ProductSource
    {
        return ProductSource::Jumia;
    }

    /**
     * Parse a Jumia product page.
     */
    public function parse(string $html, string $url): ?ScrapedProduct
    {
        if (trim($html) === '') {
            return null;
        }

        $crawler = new Crawler($html, $url);

        $data = $this->structuredData($crawler) ?? [];

        $title = $this->firstNonEmpty(
            $data['name'] ?? null,
            $this->textOf($crawler, 'h1.-fs20'),
            $this->textOf($crawler, 'h1'),
            $this->attributeOf($crawler, 'meta[property="og:title"]', 'content'),
        );

        $priceText = $this->firstNonEmpty(
            $this->structuredPrice($data),
            $this->textOf($crawler, 'span.-b.-ltr.-tal.-fs24'),
            $this->textOf($crawler, '[data-price]'),
            $this->attributeOf($crawler, 'meta[property="product:price:amount"]', 'content'),
        );

        // A product without a title or a price is not a product we can store.
        if ($title === null || $priceText === null) {
            return null;
        }

        $price = $this->parsePriceToMinorUnits($priceText);

        if ($price === null) {
            return null;
        }

        $imageUrl = $this->firstNonEmpty(
            $this->structuredImage($data),
            $this->attributeOf($crawler, 'meta[property="og:image"]', 'content'),
            $this->attributeOf($crawler, 'img#imgProduct', 'data-src'),
            $this->attributeOf($crawler, 'img#imgProduct', 'src'),
        );

        return new ScrapedProduct(
            title: $this->normaliseWhitespace($title),
            price: $price,
            currency: $this->firstNonEmpty(
                is_string($data['offers']['priceCurrency'] ?? null) ? $data['offers']['priceCurrency'] : null,
                $this->attributeOf($crawler, 'meta[property="product:price:currency"]', 'content'),
            ) ?? 'EGP',
            imageUrl: $imageUrl === null ? null : $this->absoluteUrl($imageUrl, $url),
            source: $this->source(),
            sourceUrl: $url,
        );
    }
}
