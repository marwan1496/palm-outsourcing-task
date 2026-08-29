<?php

declare(strict_types=1);

use App\Scraping\Contracts\PageFetcher;
use App\Scraping\DTO\FetchedPage;
use App\Scraping\Exceptions\ScrapeFailedException;
use App\Scraping\Parsers\JumiaParser;
use App\Scraping\Pipeline\ItemPipeline;
use App\Scraping\ScraperManager;
use App\Scraping\Support\BlockDetector;
use App\Scraping\Support\UrlGuard;
use Illuminate\Support\Facades\Log;

/**
 * Tests for the "Guzzle got blocked, try a browser" path.
 *
 * Both fetchers are fakes, so none of this launches Chrome or touches the
 * network. What's under test is the decision to fall back, not the browser
 * itself — BrowserFetcher is verified by hand against a real blocked page,
 * which is documented in docs/testing.md.
 */

/**
 * A fetcher that returns whatever you tell it to, and counts its calls.
 */
function fakeFetcher(?int $status, string $html, string $name = 'fake'): PageFetcher
{
    return new class($status, $html, $name) implements PageFetcher
    {
        public int $calls = 0;

        public function __construct(
            private readonly ?int $status,
            private readonly string $html,
            private readonly string $name,
        ) {}

        public function fetch(string $url): FetchedPage
        {
            $this->calls++;

            return new FetchedPage($this->status, $this->html, $this->name);
        }

        public function name(): string
        {
            return $this->name;
        }
    };
}

/**
 * Build a manager with the given primary and optional fallback fetcher.
 */
function managerWith(PageFetcher $primary, ?PageFetcher $fallback = null): ScraperManager
{
    return new ScraperManager(
        fetcher: $primary,
        items: app(ItemPipeline::class),
        urlGuard: new UrlGuard(['jumia.com.eg'], ['https'], verifyDns: false),
        blockDetector: new BlockDetector,
        parsers: [new JumiaParser],
        logger: Log::channel(),
        browserFetcher: $fallback,
    );
}

const BLOCKED_URL = 'https://www.jumia.com.eg/product.html';

describe('falling back to the browser', function () {
    it('retries in the browser when Cloudflare blocks Guzzle', function () {
        $guzzle = fakeFetcher(403, fixtureHtml('cloudflare-challenge.html'), 'guzzle');
        $browser = fakeFetcher(null, fixtureHtml('jumia-product.html'), 'browser');

        $product = managerWith($guzzle, $browser)->scrape(BLOCKED_URL);

        // The browser rescued a scrape that Guzzle alone would have lost.
        expect($product->title)->toContain('Samsung Galaxy A55')
            ->and($guzzle->calls)->toBe(1)
            ->and($browser->calls)->toBe(1);
    });

    it('retries when a CAPTCHA is served with a 200', function () {
        $guzzle = fakeFetcher(200, fixtureHtml('amazon-captcha.html'), 'guzzle');
        $browser = fakeFetcher(null, fixtureHtml('jumia-product.html'), 'browser');

        managerWith($guzzle, $browser)->scrape(BLOCKED_URL);

        expect($browser->calls)->toBe(1);
    });

    it('retries a bare 403 with no recognisable challenge', function () {
        $guzzle = fakeFetcher(403, '<html><body>Forbidden</body></html>', 'guzzle');
        $browser = fakeFetcher(null, fixtureHtml('jumia-product.html'), 'browser');

        managerWith($guzzle, $browser)->scrape(BLOCKED_URL);

        expect($browser->calls)->toBe(1);
    });
});

describe('not falling back unnecessarily', function () {
    // Launching Chrome costs seconds and hundreds of megabytes. Doing it for a
    // page that loaded perfectly well would be the expensive mistake here.
    it('does not touch the browser when Guzzle succeeds', function () {
        $guzzle = fakeFetcher(200, fixtureHtml('jumia-product.html'), 'guzzle');
        $browser = fakeFetcher(null, fixtureHtml('jumia-product.html'), 'browser');

        managerWith($guzzle, $browser)->scrape(BLOCKED_URL);

        expect($guzzle->calls)->toBe(1)
            ->and($browser->calls)->toBe(0);
    });

    it('does not use the browser for a 404', function () {
        // The page genuinely isn't there. A browser would find the same nothing.
        $guzzle = fakeFetcher(404, '<html><body>Not found</body></html>', 'guzzle');
        $browser = fakeFetcher(null, fixtureHtml('jumia-product.html'), 'browser');

        expect(fn () => managerWith($guzzle, $browser)->scrape(BLOCKED_URL))
            ->toThrow(ScrapeFailedException::class, 'HTTP 404');

        expect($browser->calls)->toBe(0);
    });

    it('does not use the browser for rate limiting', function () {
        // 429 is about how often we ask, not how. Retrying instantly through a
        // browser just spends another request against the limit.
        $guzzle = fakeFetcher(429, '<html><body>Slow down</body></html>', 'guzzle');
        $browser = fakeFetcher(null, fixtureHtml('jumia-product.html'), 'browser');

        expect(fn () => managerWith($guzzle, $browser)->scrape(BLOCKED_URL))
            ->toThrow(ScrapeFailedException::class);

        expect($browser->calls)->toBe(0);
    });
});

describe('when the fallback is disabled', function () {
    // The default. Nothing about existing behaviour changes unless it is
    // explicitly switched on.
    it('never launches a browser', function () {
        $guzzle = fakeFetcher(403, fixtureHtml('cloudflare-challenge.html'), 'guzzle');

        expect(fn () => managerWith($guzzle, null)->scrape(BLOCKED_URL))
            ->toThrow(ScrapeFailedException::class, 'Cloudflare challenge page');
    });

    // Pinned off in phpunit.xml, so no test can start a browser and the suite
    // behaves identically whatever a developer has in their local .env.
    it('is off while the test suite runs', function () {
        expect(config('scraping.browser_fallback.enabled'))->toBeFalse();
    });
});

describe('when the browser also fails', function () {
    it('reports the original block rather than an empty page', function () {
        // A browser that comes back with nothing is less informative than the
        // 403 we already had, so the original response is what gets reported.
        $guzzle = fakeFetcher(403, fixtureHtml('cloudflare-challenge.html'), 'guzzle');
        $browser = fakeFetcher(null, '', 'browser');

        expect(fn () => managerWith($guzzle, $browser)->scrape(BLOCKED_URL))
            ->toThrow(ScrapeFailedException::class, 'Cloudflare challenge page');

        expect($browser->calls)->toBe(1);
    });

    it('reports a block when the browser gets challenged too', function () {
        $guzzle = fakeFetcher(403, fixtureHtml('cloudflare-challenge.html'), 'guzzle');
        $browser = fakeFetcher(null, fixtureHtml('cloudflare-challenge.html'), 'browser');

        expect(fn () => managerWith($guzzle, $browser)->scrape(BLOCKED_URL))
            ->toThrow(ScrapeFailedException::class, 'Cloudflare challenge page');
    });
});
