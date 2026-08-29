<?php

declare(strict_types=1);

namespace App\Scraping\Contracts;

use App\Scraping\DTO\FetchedPage;
use Illuminate\Http\Client\ConnectionException;

/**
 * Gets the HTML for a URL.
 *
 * Two implementations: GuzzleFetcher, which is the normal path and satisfies
 * the brief's requirement to use Guzzle, and BrowserFetcher, which drives a
 * real Chrome and is only used when the first one is turned away.
 *
 * The seam exists because those two are wildly different — one is an HTTP
 * request, the other is a browser session — but everything downstream only
 * wants HTML. Keeping them behind one interface means the parsers, the item
 * pipeline and persistence never learn that a fallback exists.
 */
interface PageFetcher
{
    /**
     * Fetch a page.
     *
     * @throws ConnectionException when the request cannot be made at all.
     */
    public function fetch(string $url): FetchedPage;

    /**
     * Short name for logs, e.g. "guzzle" or "browser".
     */
    public function name(): string;
}
