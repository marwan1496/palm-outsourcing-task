<?php

declare(strict_types=1);

use App\Enums\ProductSource;
use App\Models\Product;
use App\Scraping\Exceptions\ScrapeFailedException;
use App\Scraping\Exceptions\UnsafeUrlException;
use App\Scraping\Parsers\AmazonParser;
use App\Scraping\Parsers\JumiaParser;
use App\Scraping\Pipeline\ItemPipeline;
use App\Scraping\Pipeline\ScraperPipeline;
use App\Scraping\ScraperManager;
use App\Scraping\Support\UrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * End-to-end tests for the scraping module, with the network faked.
 *
 * These exercise the real pipeline - middleware chain, parser selection,
 * validation and persistence - and only replace the outbound HTTP call.
 */
beforeEach(function () {
    $this->url = 'https://www.jumia.com.eg/samsung-galaxy-a55.html';
    $this->manager = app(ScraperManager::class);
});

describe('the happy path', function () {
    it('scrapes a page and stores the product', function () {
        Http::fake([
            '*' => Http::response(fixtureHtml('jumia-product.html')),
        ]);

        $product = $this->manager->scrape($this->url);

        expect($product)->toBeInstanceOf(Product::class)
            ->and($product->exists)->toBeTrue()
            ->and($product->title)->toContain('Samsung Galaxy A55')
            ->and($product->price)->toBe(1_849_900)
            ->and($product->currency)->toBe('EGP')
            ->and($product->source)->toBe(ProductSource::Jumia)
            ->and($product->source_url)->toBe($this->url);

        $this->assertDatabaseCount('products', 1);
    });

    it('records when the product was scraped', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-product.html'))]);

        expect($this->manager->scrape($this->url)->scraped_at)->not->toBeNull();
    });

    // Idempotency is what makes the scraper safe to run repeatedly - the
    // unique index on source_url turns a re-scrape into an update.
    it('updates the existing row instead of duplicating on a re-scrape', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-product.html'))]);

        $first = $this->manager->scrape($this->url);
        $second = $this->manager->scrape($this->url);

        expect($second->id)->toBe($first->id);
        $this->assertDatabaseCount('products', 1);
    });

    it('picks the Amazon parser for an Amazon URL', function () {
        Http::fake(['*' => Http::response(fixtureHtml('amazon-product.html'))]);

        $product = $this->manager->scrape('https://www.amazon.eg/dp/B01LR8CIRC');

        expect($product->source)->toBe(ProductSource::Amazon)
            ->and($product->price)->toBe(4_999);
    });
});

describe('user-agent rotation', function () {
    // The brief asks for rotating user-agents; this is the test that proves
    // the requirement is actually met at the request level.
    it('sends a browser user-agent rather than the default client one', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-product.html'))]);

        $this->manager->scrape($this->url);

        Http::assertSent(function ($request) {
            $agent = $request->header('User-Agent')[0] ?? '';

            return str_starts_with($agent, 'Mozilla/5.0')
                && ! str_contains($agent, 'GuzzleHttp');
        });
    });

    it('sends a different user-agent on each successive request', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-product.html'))]);

        $this->manager->scrape($this->url);
        $this->manager->scrape('https://www.jumia.com.eg/another-product.html');

        $agents = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->header('User-Agent')[0] ?? '')
            ->all();

        expect($agents)->toHaveCount(2)
            ->and($agents[0])->not->toBe($agents[1]);
    });

    it('sends the supporting browser headers alongside the user-agent', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-product.html'))]);

        $this->manager->scrape($this->url);

        Http::assertSent(fn ($request) => $request->hasHeader('Accept-Language')
            && $request->hasHeader('Sec-Fetch-Mode'));
    });
});

describe('rejecting unsafe URLs', function () {
    // The guard runs before any request is made, so nothing should be sent.
    it('refuses a URL outside the allowlist without making a request', function () {
        Http::fake();

        expect(fn () => $this->manager->scrape('https://evil.test/product.html'))
            ->toThrow(UnsafeUrlException::class);

        Http::assertNothingSent();
    });

    it('refuses a non-https URL without making a request', function () {
        Http::fake();

        expect(fn () => $this->manager->scrape('http://www.jumia.com.eg/x.html'))
            ->toThrow(UnsafeUrlException::class);

        Http::assertNothingSent();
    });

    it('refuses a URL pointing at an internal port', function () {
        Http::fake();

        expect(fn () => $this->manager->scrape('https://www.jumia.com.eg:3306/x'))
            ->toThrow(UnsafeUrlException::class);

        Http::assertNothingSent();
    });
});

describe('handling failures', function () {
    it('fails when the site returns an error status', function () {
        Http::fake(['*' => Http::response('Not found', 404)]);

        expect(fn () => $this->manager->scrape($this->url))
            ->toThrow(ScrapeFailedException::class, 'HTTP 404');

        $this->assertDatabaseCount('products', 0);
    });

    it('fails when the page contains no product', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-blocked.html'))]);

        expect(fn () => $this->manager->scrape($this->url))
            ->toThrow(ScrapeFailedException::class, 'No product could be parsed');

        $this->assertDatabaseCount('products', 0);
    });

    it('fails when an Amazon CAPTCHA is served', function () {
        Http::fake(['*' => Http::response(fixtureHtml('amazon-captcha.html'))]);

        expect(fn () => $this->manager->scrape('https://www.amazon.eg/dp/B01'))
            ->toThrow(ScrapeFailedException::class);
    });

    it('reports that no parser exists for an unsupported storefront', function () {
        // Bypass the guard so we reach the parser-selection step: this proves
        // the two checks are genuinely independent.
        $manager = new ScraperManager(
            pipeline: app(ScraperPipeline::class),
            items: app(ItemPipeline::class),
            urlGuard: new UrlGuard(['ebay.com'], ['https'], verifyDns: false),
            parsers: [new JumiaParser],
            logger: Log::channel(),
        );

        expect(fn () => $manager->scrape('https://www.ebay.com/itm/123'))
            ->toThrow(ScrapeFailedException::class, 'No parser is registered');
    });
});

describe('retrying transient failures', function () {
    // Retry sits outermost in the chain, so a second attempt goes out with a
    // fresh user-agent rather than repeating an identical blocked request.
    it('retries a 503 and succeeds on a later attempt', function () {
        Http::fakeSequence()
            ->push('Service Unavailable', 503)
            ->push(fixtureHtml('jumia-product.html'), 200);

        config()->set('scraping.retry.base_delay_ms', 1); // keep the test fast

        $product = app(ScraperManager::class)->scrape($this->url);

        expect($product->title)->toContain('Samsung Galaxy A55');
        Http::assertSentCount(2);
    });

    it('does not retry a 404, because it will not change', function () {
        Http::fake(['*' => Http::response('Not found', 404)]);

        expect(fn () => $this->manager->scrape($this->url))->toThrow(ScrapeFailedException::class);

        Http::assertSentCount(1);
    });

    it('gives up after the configured number of attempts', function () {
        Http::fake(['*' => Http::response('Server Error', 500)]);
        config()->set('scraping.retry.base_delay_ms', 1);

        expect(fn () => app(ScraperManager::class)->scrape($this->url))
            ->toThrow(ScrapeFailedException::class);

        Http::assertSentCount(3); // the configured max_attempts
    });
});

describe('canScrape', function () {
    it('accepts a supported and safe URL', function () {
        expect($this->manager->canScrape($this->url))->toBeTrue();
    });

    it('rejects an unsupported storefront', function () {
        expect($this->manager->canScrape('https://www.ebay.com/itm/1'))->toBeFalse();
    });

    it('rejects an unsafe URL', function () {
        expect($this->manager->canScrape('http://www.jumia.com.eg/x'))->toBeFalse();
    });
});

describe('parserFor', function () {
    it('returns the matching parser for each storefront', function (string $url, string $expected) {
        expect($this->manager->parserFor($url))->toBeInstanceOf($expected);
    })->with([
        'jumia' => ['https://www.jumia.com.eg/x.html', JumiaParser::class],
        'amazon' => ['https://www.amazon.eg/dp/B1', AmazonParser::class],
    ]);

    it('returns null for an unsupported storefront', function () {
        expect($this->manager->parserFor('https://www.ebay.com/itm/1'))->toBeNull();
    });
});
