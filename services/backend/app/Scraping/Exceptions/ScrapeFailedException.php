<?php

declare(strict_types=1);

namespace App\Scraping\Exceptions;

use RuntimeException;

/**
 * Thrown when a scrape was attempted but did not produce a storable product.
 *
 * Distinct from UnsafeUrlException, which means we refused to make the request
 * at all. This one means we tried: the site blocked us, the page had no
 * product on it, or the values we extracted failed validation.
 */
final class ScrapeFailedException extends RuntimeException
{
    /**
     * The site returned something, but no product could be read from it.
     *
     * Usually a CAPTCHA, a "product unavailable" page, or a layout change.
     */
    public static function couldNotParse(string $url): self
    {
        return new self(sprintf(
            'No product could be parsed from [%s]. The page may be blocked, removed, or newly restyled.',
            $url,
        ));
    }

    /**
     * The site answered with an error status.
     */
    public static function badResponse(string $url, int $status): self
    {
        return new self(sprintf('Fetching [%s] returned HTTP %d.', $url, $status));
    }

    /**
     * The site actively turned us away, rather than simply not having the page.
     *
     * Worth its own message: "blocked by Cloudflare" and "the layout changed"
     * look identical from the outside but call for completely different fixes.
     */
    public static function blocked(string $url, string $reason): self
    {
        return new self(sprintf('Blocked while fetching [%s]: %s.', $url, $reason));
    }

    /**
     * No parser is registered for this URL's storefront.
     */
    public static function noParserFor(string $url): self
    {
        return new self(sprintf('No parser is registered for [%s].', $url));
    }

    /**
     * The request never completed - DNS, TLS, timeout, or a dead proxy.
     */
    public static function connectionFailed(string $url, string $reason): self
    {
        return new self(sprintf('Could not connect to [%s]: %s', $url, $reason));
    }
}
