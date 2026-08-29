<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\ProductSource;
use App\Models\Product;
use App\Scraping\Contracts\PageFetcher;
use App\Scraping\Contracts\ProductParser;
use App\Scraping\DTO\FetchedPage;
use App\Scraping\Exceptions\ScrapeFailedException;
use App\Scraping\Exceptions\UnsafeUrlException;
use App\Scraping\Pipeline\ItemPipeline;
use App\Scraping\Support\BlockDetector;
use App\Scraping\Support\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Psr\Log\LoggerInterface;

/**
 * The scraping module's front door.
 *
 * Everything else in App\Scraping is a part; this is the class that puts them
 * in order. The artisan command and the queued job both call scrape() and
 * nothing else, so there is exactly one path from a URL to a stored product.
 *
 * The order matters and is the whole story of the module:
 *
 *   1. UrlGuard        Refuse unsafe URLs before any request is made (SSRF).
 *   2. parserFor()     Pick the parser. No parser means no point downloading.
 *   3. fetch           Guzzle, rotating user-agent and proxy, retrying.
 *      └ fallback      If we were blocked, optionally retry in a real browser.
 *   4. parse()         Turn HTML into a ScrapedProduct.
 *   5. ItemPipeline    Validate, normalise, upsert into MySQL.
 */
class ScraperManager
{
    /**
     * @param  list<ProductParser>  $parsers  One per supported storefront.
     * @param  PageFetcher|null  $browserFetcher  Null when the fallback is off,
     *                                            which is the default.
     */
    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly ItemPipeline $items,
        private readonly UrlGuard $urlGuard,
        private readonly BlockDetector $blockDetector,
        private readonly array $parsers,
        private readonly LoggerInterface $logger,
        private readonly ?PageFetcher $browserFetcher = null,
    ) {}

    /**
     * Scrape a product page and store the result.
     *
     * @throws UnsafeUrlException when the URL is rejected.
     * @throws ScrapeFailedException when the page yields no storable product.
     */
    public function scrape(string $url): Product
    {
        // Step 1. Never fetch a URL we haven't vetted. This runs before any
        // network call, because the entire point is not to make one.
        $this->urlGuard->assertSafe($url);

        // Step 2. Choose the parser first: if we can't read the page, there's
        // no reason to download it.
        $parser = $this->parserFor($url);

        if ($parser === null) {
            throw ScrapeFailedException::noParserFor($url);
        }

        $this->logger->info('Scraping product page.', ['url' => $url, 'parser' => $parser::class]);

        // Step 3. Fetch, with a browser retry if we get turned away.
        $page = $this->fetchWithFallback($url);

        // A bad status stops us here. Report it as a block when we can tell
        // that's what it is, because "blocked by Cloudflare" and "HTTP 404"
        // send an operator in completely different directions.
        if (! $page->isSuccessful()) {
            $reason = $this->blockDetector->reason($page->status, $page->html);

            throw $reason === null
                ? ScrapeFailedException::badResponse($url, $page->status ?? 0)
                : ScrapeFailedException::blocked($url, $reason);
        }

        // Step 4. Extract. Null means the page loaded but held no product,
        // typically a block page or a layout change.
        $scraped = $parser->parse($page->html, $url);

        if ($scraped === null) {
            $reason = $this->blockDetector->reason($page->status, $page->html);

            throw $reason === null
                ? ScrapeFailedException::couldNotParse($url)
                : ScrapeFailedException::blocked($url, $reason);
        }

        // Step 5. Validate, normalise and store.
        $product = $this->items->process($scraped);

        $this->logger->info('Stored scraped product.', [
            'product_id' => $product->id,
            'title' => $product->title,
            'fetched_by' => $page->fetchedBy,
        ]);

        return $product;
    }

    /**
     * Fetch with Guzzle, and retry in a browser if we were blocked.
     *
     * The browser is only tried for blocks a browser could plausibly solve — a
     * Cloudflare challenge or a CAPTCHA, both of which are really "you didn't
     * run our JavaScript". Retrying a 429 in a browser would just spend
     * another request against a rate limit.
     */
    private function fetchWithFallback(string $url): FetchedPage
    {
        try {
            $page = $this->fetcher->fetch($url);
        } catch (ConnectionException $e) {
            throw ScrapeFailedException::connectionFailed($url, $e->getMessage());
        }

        if ($this->browserFetcher === null) {
            return $page;
        }

        if (! $this->blockDetector->isWorthRetryingInBrowser($page->status, $page->html)) {
            return $page;
        }

        $this->logger->info('Blocked, retrying through a browser.', [
            'url' => $url,
            'reason' => $this->blockDetector->reason($page->status, $page->html),
        ]);

        $browserPage = $this->browserFetcher->fetch($url);

        // If the browser came back empty, the original response is still the
        // more informative one to report on.
        return $browserPage->html === '' ? $page : $browserPage;
    }

    /**
     * The parser that handles this URL's storefront, or null if none does.
     */
    public function parserFor(string $url): ?ProductParser
    {
        $source = ProductSource::fromUrl($url);

        if ($source === null) {
            return null;
        }

        foreach ($this->parsers as $parser) {
            if ($parser->source() === $source) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Whether this URL can be scraped: it's safe and a parser exists.
     *
     * Used by validation so a URL is rejected with a helpful message before a
     * job is queued that could only ever fail.
     */
    public function canScrape(string $url): bool
    {
        return $this->urlGuard->isSafe($url) && $this->parserFor($url) !== null;
    }
}
