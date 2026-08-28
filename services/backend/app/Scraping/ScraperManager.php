<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\ProductSource;
use App\Models\Product;
use App\Scraping\Contracts\ProductParser;
use App\Scraping\Exceptions\ScrapeFailedException;
use App\Scraping\Exceptions\UnsafeUrlException;
use App\Scraping\Pipeline\ItemPipeline;
use App\Scraping\Pipeline\ScraperPipeline;
use App\Scraping\Support\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Psr\Log\LoggerInterface;

/**
 * The scraping module's front door.
 *
 * Everything else in App\Scraping is a part; this is the class that puts them
 * in order. Both the artisan command and the queued job call scrape() and
 * nothing else, so there is exactly one code path from a URL to a stored
 * product.
 *
 * The order matters and is the whole story of the module:
 *
 *   1. UrlGuard        Refuse unsafe URLs before any request is made (SSRF).
 *   2. parserFor()     Pick the parser for that storefront.
 *   3. ScraperPipeline Fetch, rotating user-agent and proxy, retrying on failure.
 *   4. parse()         Turn HTML into a ScrapedProduct.
 *   5. ItemPipeline    Validate, normalise, and upsert into MySQL.
 */
class ScraperManager
{
    /**
     * @param  list<ProductParser>  $parsers  One per supported storefront.
     */
    public function __construct(
        private readonly ScraperPipeline $pipeline,
        private readonly ItemPipeline $items,
        private readonly UrlGuard $urlGuard,
        private readonly array $parsers,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Scrape a product page and store the result.
     *
     * @throws UnsafeUrlException when the URL is rejected.
     * @throws ScrapeFailedException when the page yields no storable product.
     */
    public function scrape(string $url): Product
    {
        // Step 1. Never fetch a URL we have not vetted. This happens first,
        // before any network call, because the whole point is to not make one.
        $this->urlGuard->assertSafe($url);

        // Step 2. Choose the parser before fetching: if we cannot understand
        // the page, there is no reason to download it.
        $parser = $this->parserFor($url);

        if ($parser === null) {
            throw ScrapeFailedException::noParserFor($url);
        }

        $this->logger->info('Scraping product page.', [
            'url' => $url,
            'parser' => $parser::class,
        ]);

        // Step 3. Fetch through the middleware chain.
        try {
            $response = $this->pipeline->fetch($url);
        } catch (ConnectionException $e) {
            throw ScrapeFailedException::connectionFailed($url, $e->getMessage());
        }

        if (! $response->successful()) {
            throw ScrapeFailedException::badResponse($url, $response->status());
        }

        // Step 4. Extract. A null here means the page loaded but held no
        // product - typically a block page or a layout change.
        $scraped = $parser->parse($response->body(), $url);

        if ($scraped === null) {
            throw ScrapeFailedException::couldNotParse($url);
        }

        // Step 5. Validate, normalise and store.
        $product = $this->items->process($scraped);

        $this->logger->info('Stored scraped product.', [
            'product_id' => $product->id,
            'title' => $product->title,
        ]);

        return $product;
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
     * Whether this URL can be scraped: it is safe and a parser exists.
     *
     * Used by validation so the API can reject a URL with a helpful message
     * before queueing a job that would only fail.
     */
    public function canScrape(string $url): bool
    {
        return $this->urlGuard->isSafe($url) && $this->parserFor($url) !== null;
    }
}
