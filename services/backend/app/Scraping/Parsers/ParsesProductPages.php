<?php

declare(strict_types=1);

namespace App\Scraping\Parsers;

use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Extraction helpers shared by every parser.
 *
 * These are the parts that are genuinely identical between storefronts -
 * reading a selector safely, understanding JSON-LD, turning "EGP 1,299.00"
 * into an integer. Only the selectors themselves differ per site, and those
 * live in the individual parser classes.
 *
 * A trait rather than a base class: parsers implement ProductParser and share
 * behaviour, but there is no meaningful "abstract parser" in the domain, and
 * inheritance would invite putting site-specific logic in a shared parent.
 */
trait ParsesProductPages
{
    /**
     * The first argument that is a non-empty string, or null.
     *
     * Parsers list several selectors per field in preference order; this
     * collapses that list into a single value without a stack of if-blocks.
     */
    protected function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Text content of the first node matching a CSS selector, or null.
     *
     * DomCrawler throws when a selector matches nothing, which would make
     * every lookup need its own try/catch. This wraps that once.
     */
    protected function textOf(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector);

            return $node->count() > 0 ? $node->first()->text() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * An attribute of the first node matching a CSS selector, or null.
     */
    protected function attributeOf(Crawler $crawler, string $selector, string $attribute): ?string
    {
        try {
            $node = $crawler->filter($selector);

            return $node->count() > 0 ? $node->first()->attr($attribute) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The schema.org Product block embedded in the page, if there is one.
     *
     * Storefronts publish this for search engines, which makes it both the
     * most reliable source of product data and the least likely to change
     * when the site is restyled.
     *
     * @return array<string, mixed>|null
     */
    protected function structuredData(Crawler $crawler): ?array
    {
        try {
            $scripts = $crawler->filter('script[type="application/ld+json"]');
        } catch (Throwable) {
            return null;
        }

        foreach ($scripts as $script) {
            $decoded = json_decode((string) $script->textContent, true);

            if (! is_array($decoded)) {
                continue;
            }

            // A category or search page carries a Product block for every item
            // it lists. Reading the first one would store an unrelated product
            // under whatever URL was requested, and because that product is
            // perfectly valid the job would report success. Refusing to read
            // listings at all is the only safe answer.
            if ($this->looksLikeListing($decoded)) {
                return null;
            }

            // A page may ship several blocks (Product, BreadcrumbList, …), and
            // they may be wrapped in a @graph array.
            foreach ($this->candidateNodes($decoded) as $node) {
                if (($node['@type'] ?? null) === 'Product') {
                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * Whether this JSON-LD describes a list of products rather than one product.
     *
     * Two signals, either of which is conclusive:
     *
     *   - A container type. `CollectionPage`, `ItemList` and `SearchResultsPage`
     *     all mean "here are several things".
     *   - More than one Product node. A product page describes one product;
     *     anything describing several is a listing.
     *
     * This matters because a dead product URL on Jumia redirects to a category
     * page that returns 200 and carries ten Product blocks. Reading the first
     * one stores a completely unrelated item under the requested URL, and since
     * it validates cleanly the job reports success. The failure is silent,
     * which makes it worse than a crash.
     *
     * @param  array<mixed>  $decoded
     */
    private function looksLikeListing(array $decoded): bool
    {
        $json = json_encode($decoded);

        if ($json === false) {
            return false;
        }

        foreach (['"ItemList"', '"CollectionPage"', '"SearchResultsPage"'] as $containerType) {
            if (str_contains($json, $containerType)) {
                return true;
            }
        }

        return substr_count($json, '"Product"') > 1;
    }

    /**
     * Flatten the shapes JSON-LD legitimately takes into a list of nodes.
     *
     * @param  array<mixed>  $decoded
     * @return list<array<string, mixed>>
     */
    private function candidateNodes(array $decoded): array
    {
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            return array_values(array_filter($decoded['@graph'], 'is_array'));
        }

        // A bare list of nodes.
        if (array_is_list($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }

        return [$decoded];
    }

    /**
     * The price from a structured-data block.
     *
     * The offers key is either a single object or a list of them, depending on
     * whether the product has multiple sellers.
     *
     * @param  array<string, mixed>  $data
     */
    protected function structuredPrice(array $data): ?string
    {
        $offers = $data['offers'] ?? null;

        if (! is_array($offers)) {
            return null;
        }

        // Several offers: take the first that quotes a price.
        if (array_is_list($offers)) {
            foreach ($offers as $offer) {
                if (is_array($offer) && isset($offer['price'])) {
                    return (string) $offer['price'];
                }
            }

            return null;
        }

        return isset($offers['price']) ? (string) $offers['price'] : null;
    }

    /**
     * The image from a structured-data block, which may be a string or a list.
     *
     * @param  array<string, mixed>  $data
     */
    protected function structuredImage(array $data): ?string
    {
        $image = $data['image'] ?? null;

        if (is_string($image)) {
            return $image;
        }

        if (is_array($image) && isset($image[0]) && is_string($image[0])) {
            return $image[0];
        }

        return null;
    }

    /**
     * Convert a displayed price into an integer number of minor units.
     *
     * Handles the formats storefronts actually use:
     *
     *   "EGP 1,299.00"  ->  129900   (comma thousands, dot decimal)
     *   "1.299,00 EGP"  ->  129900   (European: dot thousands, comma decimal)
     *   "1,299"         ->  129900   (thousands separator, no decimals)
     *   "19.99"         ->    1999
     *   "2,50"          ->     250   (comma as decimal separator)
     *
     * Returns null when no number can be found, which the parsers treat as
     * "this is not a product page".
     */
    protected function parsePriceToMinorUnits(string $raw): ?int
    {
        // Strip everything except digits and the two separator characters.
        $cleaned = preg_replace('/[^0-9.,]/', '', $raw) ?? '';

        if ($cleaned === '' || preg_match('/\d/', $cleaned) !== 1) {
            return null;
        }

        $lastDot = strrpos($cleaned, '.');
        $lastComma = strrpos($cleaned, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Both present: whichever comes last is the decimal separator.
            $decimalPos = max($lastDot, $lastComma);
            $normalised = $this->applyDecimalSeparator($cleaned, $decimalPos);
        } elseif ($lastDot !== false || $lastComma !== false) {
            $position = $lastDot !== false ? $lastDot : $lastComma;
            $digitsAfter = strlen($cleaned) - $position - 1;

            // Exactly one or two digits after a single separator means it is a
            // decimal point. Three means a thousands separator ("1,299").
            $normalised = ($digitsAfter === 1 || $digitsAfter === 2)
                ? $this->applyDecimalSeparator($cleaned, $position)
                : str_replace([',', '.'], '', $cleaned);
        } else {
            $normalised = $cleaned;
        }

        if ($normalised === '' || ! is_numeric($normalised)) {
            return null;
        }

        return (int) round(((float) $normalised) * 100);
    }

    /**
     * Rebuild a numeric string with the separator at $position as the decimal
     * point, discarding every other separator as a thousands marker.
     */
    private function applyDecimalSeparator(string $cleaned, int $position): string
    {
        $whole = str_replace([',', '.'], '', substr($cleaned, 0, $position));
        $fraction = str_replace([',', '.'], '', substr($cleaned, $position + 1));

        return $whole.'.'.$fraction;
    }

    /**
     * Collapse runs of whitespace into single spaces and trim.
     *
     * Scraped titles routinely arrive wrapped across lines with tabs and
     * non-breaking spaces in them.
     */
    protected function normaliseWhitespace(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * Resolve a possibly-relative URL against the page it came from.
     *
     * The frontend renders image_url straight into an <img> tag, so a relative
     * or protocol-relative path stored in the database would simply not load.
     */
    protected function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = trim($url);

        // Already absolute.
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        // Protocol-relative: "//img.jumia.is/product.jpg"
        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        if ($host === '') {
            return $url;
        }

        // Root-relative: "/product.jpg"
        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        // Path-relative: "images/product.jpg"
        $path = $base['path'] ?? '/';
        $directory = rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/');

        return $scheme.'://'.$host.$directory.'/'.$url;
    }
}
