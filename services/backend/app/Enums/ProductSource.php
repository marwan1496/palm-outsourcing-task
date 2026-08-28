<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The storefront a product was scraped from.
 *
 * Why an enum rather than a plain string column: the source decides which
 * parser handles a page, and a typo in a string ("jumla") would silently
 * produce products nothing can re-scrape. A backed enum makes the set of valid
 * sources explicit and lets PHP reject anything outside it.
 */
enum ProductSource: string
{
    case Jumia = 'jumia';
    case Amazon = 'amazon';

    /**
     * A human-readable name, for API responses and the frontend.
     */
    public function label(): string
    {
        return match ($this) {
            self::Jumia => 'Jumia',
            self::Amazon => 'Amazon',
        };
    }

    /**
     * Find the source that handles a given URL, or null when none does.
     *
     * This is the single place that maps a hostname to a storefront, so adding
     * a site means touching this method and adding a parser - nothing else.
     */
    public static function fromUrl(string $url): ?self
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        foreach (self::cases() as $source) {
            foreach ($source->hostPatterns() as $pattern) {
                // Match the exact domain or any subdomain of it, so
                // "www.jumia.com.eg" matches but "jumia.com.eg.evil.com"
                // does not.
                if ($host === $pattern || str_ends_with($host, '.'.$pattern)) {
                    return $source;
                }
            }
        }

        return null;
    }

    /**
     * The domains this storefront is served from.
     *
     * @return list<string>
     */
    public function hostPatterns(): array
    {
        return match ($this) {
            self::Jumia => ['jumia.com.eg', 'jumia.com', 'jumia.com.ng', 'jumia.co.ke'],
            self::Amazon => ['amazon.eg', 'amazon.com', 'amazon.co.uk', 'amazon.de', 'amazon.sa'],
        };
    }

    /**
     * Every host pattern across every source, used by the SSRF allowlist.
     *
     * @return list<string>
     */
    public static function allHostPatterns(): array
    {
        return array_merge(...array_map(
            static fn (self $source): array => $source->hostPatterns(),
            self::cases(),
        ));
    }
}
