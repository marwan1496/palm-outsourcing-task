<?php

declare(strict_types=1);

use App\Enums\ProductSource;
use App\Models\Product;
use App\Scraping\DTO\ScrapedProduct;
use App\Scraping\Exceptions\ScrapeFailedException;
use App\Scraping\Pipeline\ItemPipeline;

/**
 * Build a ScrapedProduct with sensible defaults, overriding what a test cares
 * about. Keeps each test focused on the one field under examination.
 */
function scraped(array $overrides = []): ScrapedProduct
{
    return new ScrapedProduct(
        title: $overrides['title'] ?? 'A Test Product',
        price: $overrides['price'] ?? 129_900,
        currency: $overrides['currency'] ?? 'EGP',
        imageUrl: array_key_exists('imageUrl', $overrides) ? $overrides['imageUrl'] : 'https://cdn.test/img.jpg',
        source: $overrides['source'] ?? ProductSource::Jumia,
        sourceUrl: $overrides['sourceUrl'] ?? 'https://www.jumia.com.eg/product.html',
    );
}

beforeEach(function () {
    $this->pipeline = new ItemPipeline;
});

describe('persisting', function () {
    it('stores a valid product', function () {
        $product = $this->pipeline->process(scraped());

        expect($product)->toBeInstanceOf(Product::class)
            ->and($product->exists)->toBeTrue()
            ->and($product->title)->toBe('A Test Product')
            ->and($product->price)->toBe(129_900);

        $this->assertDatabaseCount('products', 1);
    });

    // The unique index on source_url is what makes re-scraping idempotent.
    it('updates the existing row when the same URL is scraped again', function () {
        $first = $this->pipeline->process(scraped(['title' => 'Original Title']));
        $second = $this->pipeline->process(scraped(['title' => 'Updated Title']));

        expect($second->id)->toBe($first->id)
            ->and($second->title)->toBe('Updated Title');

        $this->assertDatabaseCount('products', 1);
    });

    it('stores separate rows for different URLs', function () {
        $this->pipeline->process(scraped(['sourceUrl' => 'https://www.jumia.com.eg/a.html']));
        $this->pipeline->process(scraped(['sourceUrl' => 'https://www.jumia.com.eg/b.html']));

        $this->assertDatabaseCount('products', 2);
    });

    it('records when the product was scraped', function () {
        expect($this->pipeline->process(scraped())->scraped_at)->not->toBeNull();
    });
});

describe('normalising', function () {
    it('trims whitespace from the title', function () {
        expect($this->pipeline->normalise(scraped(['title' => '  Padded Title  ']))->title)
            ->toBe('Padded Title');
    });

    it('uppercases the currency', function () {
        expect($this->pipeline->normalise(scraped(['currency' => 'egp']))->currency)->toBe('EGP');
    });

    // The column is a 255-character VARCHAR; truncating here deliberately
    // beats MySQL doing it silently.
    it('truncates an over-long title', function () {
        $long = str_repeat('word ', 100); // 500 characters

        $result = $this->pipeline->normalise(scraped(['title' => $long]));

        expect(mb_strlen($result->title))->toBeLessThanOrEqual(255);
    });

    it('truncates on a word boundary rather than mid-word', function () {
        $long = str_repeat('alpha ', 100);

        $result = $this->pipeline->normalise(scraped(['title' => $long]));

        expect($result->title)->not->toEndWith('alph')
            ->and(str_ends_with(trim($result->title), 'alpha'))->toBeTrue();
    });

    it('keeps a title that is already short enough untouched', function () {
        expect($this->pipeline->normalise(scraped(['title' => 'Short Title']))->title)
            ->toBe('Short Title');
    });

    it('discards an image URL that is not usable', function (mixed $url) {
        expect($this->pipeline->normalise(scraped(['imageUrl' => $url]))->imageUrl)->toBeNull();
    })->with([
        'null' => null,
        'empty' => '',
        'whitespace' => '   ',
        'not a url' => 'not-a-url',
        'javascript' => 'javascript:alert(1)',
        'data uri' => 'data:image/gif;base64,R0lGOD',
        'relative path' => '/images/product.jpg',
    ]);

    it('keeps a valid absolute image URL', function () {
        expect($this->pipeline->normalise(scraped(['imageUrl' => 'https://cdn.test/a.jpg']))->imageUrl)
            ->toBe('https://cdn.test/a.jpg');
    });
});

describe('validating', function () {
    it('rejects an empty title', function () {
        expect(fn () => $this->pipeline->process(scraped(['title' => '   '])))
            ->toThrow(ScrapeFailedException::class, 'no title');

        $this->assertDatabaseCount('products', 0);
    });

    it('rejects a non-positive price', function (int $price) {
        expect(fn () => $this->pipeline->process(scraped(['price' => $price])))
            ->toThrow(ScrapeFailedException::class, 'not a positive amount');
    })->with(['zero' => 0, 'negative' => -100]);

    // A wildly large price is far more likely to be a mis-parsed string of
    // digits than a real listing.
    it('rejects an implausibly large price as a parse error', function () {
        expect(fn () => $this->pipeline->process(scraped(['price' => 99_999_999_999_999])))
            ->toThrow(ScrapeFailedException::class, 'implausibly large');
    });

    it('rejects a currency that is not a three-letter code', function (string $currency) {
        expect(fn () => $this->pipeline->process(scraped(['currency' => $currency])))
            ->toThrow(ScrapeFailedException::class, 'ISO 4217');
    })->with([
        'too short' => 'EG',
        'too long' => 'EGPX',
        'symbol' => '$',
        'empty' => '',
        'digits' => '123',
    ]);

    it('accepts a lowercase currency by normalising it first', function () {
        expect($this->pipeline->process(scraped(['currency' => 'usd']))->currency)->toBe('USD');
    });

    it('rejects an invalid source URL', function () {
        expect(fn () => $this->pipeline->process(scraped(['sourceUrl' => 'not-a-url'])))
            ->toThrow(ScrapeFailedException::class, 'valid source URL');
    });
});

describe('ScrapedProduct DTO', function () {
    it('exposes the attributes the model expects', function () {
        $attributes = scraped()->toAttributes();

        expect($attributes)->toHaveKeys([
            'title', 'price', 'currency', 'image_url', 'source', 'source_url', 'scraped_at',
        ]);
    });

    it('is immutable once constructed', function () {
        $product = scraped();

        expect(fn () => $product->title = 'Changed')->toThrow(Error::class);
    });
});
