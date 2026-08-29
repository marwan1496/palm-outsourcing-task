<?php

declare(strict_types=1);

namespace App\Scraping\Parsers;

use App\Enums\ProductSource;
use App\Scraping\Contracts\ProductParser;
use App\Scraping\DTO\ScrapedProduct;
use App\Scraping\Support\BlockDetector;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Extracts a product from an Amazon product page.
 *
 * Amazon is markedly harder to scrape than Jumia: it serves several different
 * layouts for the same product, splits the price across separate whole and
 * fractional elements, and returns a CAPTCHA page to traffic it dislikes. This
 * parser therefore tries a longer list of selectors, and detects the CAPTCHA
 * page explicitly so a block is reported as "no product found" rather than
 * being mistaken for a layout change.
 *
 * Included to prove the parser seam works for more than one site - swapping in
 * a second storefront really is one class plus one config entry.
 */
class AmazonParser implements ProductParser
{
    use ParsesProductPages;

    public function __construct(
        private readonly BlockDetector $blockDetector = new BlockDetector,
    ) {}

    public function source(): ProductSource
    {
        return ProductSource::Amazon;
    }

    /**
     * Parse an Amazon product page.
     */
    public function parse(string $html, string $url): ?ScrapedProduct
    {
        // The CAPTCHA check lives in BlockDetector rather than here, so the
        // fetcher can react to a block before a parser is ever reached and
        // there is only one list of markers to keep current.
        if (trim($html) === '' || $this->blockDetector->looksLikeCaptcha($html)) {
            return null;
        }

        $crawler = new Crawler($html, $url);

        $title = $this->firstNonEmpty(
            $this->textOf($crawler, '#productTitle'),
            $this->textOf($crawler, 'h1#title'),
            $this->attributeOf($crawler, 'meta[name="title"]', 'content'),
        );

        $priceText = $this->firstNonEmpty(
            // The modern layout exposes the whole price in one offscreen span
            // for screen readers, which is by far the easiest to read.
            $this->textOf($crawler, 'span.a-price > span.a-offscreen'),
            $this->textOf($crawler, '#priceblock_ourprice'),
            $this->textOf($crawler, '#priceblock_dealprice'),
            $this->splitPrice($crawler),
        );

        if ($title === null || $priceText === null) {
            return null;
        }

        $price = $this->parsePriceToMinorUnits($priceText);

        if ($price === null) {
            return null;
        }

        $imageUrl = $this->firstNonEmpty(
            $this->attributeOf($crawler, '#landingImage', 'src'),
            $this->attributeOf($crawler, '#imgBlkFront', 'src'),
            $this->attributeOf($crawler, 'meta[property="og:image"]', 'content'),
        );

        return new ScrapedProduct(
            title: $this->normaliseWhitespace($title),
            price: $price,
            currency: $this->currencyForHost($url),
            imageUrl: $imageUrl === null ? null : $this->absoluteUrl($imageUrl, $url),
            source: $this->source(),
            sourceUrl: $url,
        );
    }

    /**
     * Reassemble a price split across whole and fractional elements.
     *
     * Older Amazon layouts render "19" and "99" in separate spans, so neither
     * alone is a usable price.
     */
    private function splitPrice(Crawler $crawler): ?string
    {
        $whole = $this->textOf($crawler, 'span.a-price-whole');

        if ($whole === null) {
            return null;
        }

        $fraction = $this->textOf($crawler, 'span.a-price-fraction') ?? '00';

        return rtrim(trim($whole), '.,').'.'.trim($fraction);
    }

    /**
     * Guess the currency from the Amazon domain.
     *
     * Amazon rarely states the currency in machine-readable form, but the
     * marketplace domain determines it reliably.
     */
    private function currencyForHost(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            str_ends_with($host, 'amazon.eg') => 'EGP',
            str_ends_with($host, 'amazon.co.uk') => 'GBP',
            str_ends_with($host, 'amazon.de') => 'EUR',
            str_ends_with($host, 'amazon.sa') => 'SAR',
            default => 'USD',
        };
    }
}
