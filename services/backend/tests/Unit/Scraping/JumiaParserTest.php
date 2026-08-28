<?php

declare(strict_types=1);

use App\Enums\ProductSource;
use App\Scraping\Parsers\JumiaParser;

beforeEach(function () {
    $this->parser = new JumiaParser;
    $this->url = 'https://www.jumia.com.eg/samsung-galaxy-a55.html';
});

describe('parsing a real product page', function () {
    it('reports the storefront it handles', function () {
        expect($this->parser->source())->toBe(ProductSource::Jumia);
    });

    it('extracts the product title', function () {
        $product = $this->parser->parse(fixtureHtml('jumia-product.html'), $this->url);

        expect($product->title)->toBe('Samsung Galaxy A55 5G Dual SIM 256GB - 8GB RAM - Awesome Navy');
    });

    // 18,499.00 EGP is 1,849,900 piastres. Storing minor units keeps money
    // exact - see the Product model.
    it('extracts the price in minor units', function () {
        $product = $this->parser->parse(fixtureHtml('jumia-product.html'), $this->url);

        expect($product->price)->toBe(1_849_900);
    });

    it('extracts the currency', function () {
        $product = $this->parser->parse(fixtureHtml('jumia-product.html'), $this->url);

        expect($product->currency)->toBe('EGP');
    });

    it('extracts an absolute image URL', function () {
        $product = $this->parser->parse(fixtureHtml('jumia-product.html'), $this->url);

        expect($product->imageUrl)->toBeAbsoluteUrl()
            ->and($product->imageUrl)->toContain('jumia.is');
    });

    it('records the URL it was scraped from', function () {
        $product = $this->parser->parse(fixtureHtml('jumia-product.html'), $this->url);

        expect($product->sourceUrl)->toBe($this->url)
            ->and($product->source)->toBe(ProductSource::Jumia);
    });
});

describe('failing soft', function () {
    // A scraper meets these constantly. Returning null rather than throwing
    // means one bad page cannot fail a whole batch.
    it('returns null for a block page with no product on it', function () {
        expect($this->parser->parse(fixtureHtml('jumia-blocked.html'), $this->url))->toBeNull();
    });

    it('returns null for empty input', function () {
        expect($this->parser->parse('', $this->url))->toBeNull();
    });

    it('returns null for whitespace-only input', function () {
        expect($this->parser->parse("  \n\t ", $this->url))->toBeNull();
    });

    it('returns null for HTML that is not a product page', function () {
        expect($this->parser->parse('<html><body><p>Hello</p></body></html>', $this->url))->toBeNull();
    });

    it('returns null when a title is present but no price is', function () {
        $html = '<html><body><h1>A Product With No Price</h1></body></html>';

        expect($this->parser->parse($html, $this->url))->toBeNull();
    });

    it('returns null when a price is present but no title is', function () {
        $html = '<html><body><span class="-b -ltr -tal -fs24">EGP 100.00</span></body></html>';

        expect($this->parser->parse($html, $this->url))->toBeNull();
    });

    it('does not throw on malformed HTML', function () {
        $this->parser->parse('<html><body><div><h1>Unclosed', $this->url);
    })->throwsNoExceptions();
});

describe('fallback selectors', function () {
    // Structured data is preferred, but it is not always present. These
    // fallbacks are what keep the parser working after a redesign.
    it('falls back to Open Graph tags when there is no JSON-LD', function () {
        $html = <<<'HTML'
        <html><head>
            <meta property="og:title" content="Fallback Product">
            <meta property="product:price:amount" content="250.50">
            <meta property="product:price:currency" content="EGP">
            <meta property="og:image" content="https://eg.jumia.is/image.jpg">
        </head><body></body></html>
        HTML;

        $product = $this->parser->parse($html, $this->url);

        expect($product->title)->toBe('Fallback Product')
            ->and($product->price)->toBe(25_050)
            ->and($product->currency)->toBe('EGP');
    });

    it('falls back to the h1 and price span when there are no meta tags', function () {
        $html = <<<'HTML'
        <html><body>
            <h1>Markup Only Product</h1>
            <span class="-b -ltr -tal -fs24">EGP 1,299.00</span>
        </body></html>
        HTML;

        $product = $this->parser->parse($html, $this->url);

        expect($product->title)->toBe('Markup Only Product')
            ->and($product->price)->toBe(129_900);
    });

    it('defaults to EGP when the page states no currency', function () {
        $html = '<html><body><h1>No Currency</h1><span class="-b -ltr -tal -fs24">99.00</span></body></html>';

        expect($this->parser->parse($html, $this->url)->currency)->toBe('EGP');
    });

    it('collapses messy whitespace in a title', function () {
        $html = "<html><body><h1>Spaced   \n\t  Out    Title</h1><span class=\"-b -ltr -tal -fs24\">10.00</span></body></html>";

        expect($this->parser->parse($html, $this->url)->title)->toBe('Spaced Out Title');
    });

    it('returns a null image rather than failing when the page has none', function () {
        $html = '<html><body><h1>No Image</h1><span class="-b -ltr -tal -fs24">10.00</span></body></html>';

        $product = $this->parser->parse($html, $this->url);

        expect($product)->not->toBeNull()
            ->and($product->imageUrl)->toBeNull();
    });
});

describe('resolving image URLs', function () {
    // image_url is rendered straight into an <img> tag, so a relative path
    // stored in the database would simply not load.
    it('makes a protocol-relative image URL absolute', function () {
        $html = <<<'HTML'
        <html><head><meta property="og:image" content="//eg.jumia.is/image.jpg"></head>
        <body><h1>Product</h1><span class="-b -ltr -tal -fs24">10.00</span></body></html>
        HTML;

        expect($this->parser->parse($html, $this->url)->imageUrl)
            ->toBe('https://eg.jumia.is/image.jpg');
    });

    it('makes a root-relative image URL absolute', function () {
        $html = <<<'HTML'
        <html><head><meta property="og:image" content="/static/image.jpg"></head>
        <body><h1>Product</h1><span class="-b -ltr -tal -fs24">10.00</span></body></html>
        HTML;

        expect($this->parser->parse($html, $this->url)->imageUrl)
            ->toBe('https://www.jumia.com.eg/static/image.jpg');
    });

    it('leaves an already-absolute image URL untouched', function () {
        $html = <<<'HTML'
        <html><head><meta property="og:image" content="https://cdn.example.com/image.jpg"></head>
        <body><h1>Product</h1><span class="-b -ltr -tal -fs24">10.00</span></body></html>
        HTML;

        expect($this->parser->parse($html, $this->url)->imageUrl)
            ->toBe('https://cdn.example.com/image.jpg');
    });
});

describe('price formats', function () {
    // Storefronts write prices in more ways than one, and getting this wrong
    // is silently, expensively incorrect rather than a visible crash.
    it('converts displayed prices into exact minor units', function (string $displayed, int $expected) {
        $html = sprintf(
            '<html><body><h1>Product</h1><span class="-b -ltr -tal -fs24">%s</span></body></html>',
            $displayed,
        );

        expect($this->parser->parse($html, $this->url)->price)->toBe($expected);
    })->with([
        'plain decimal' => ['19.99', 1_999],
        'comma thousands' => ['EGP 1,299.00', 129_900],
        'comma thousands no cents' => ['1,299', 129_900],
        'european format' => ['1.299,00', 129_900],
        'comma as decimal' => ['2,50', 250],
        'currency after' => ['450.00 EGP', 45_000],
        'whole number' => ['500', 50_000],
        'large amount' => ['1,234,567.89', 123_456_789],
        'single decimal digit' => ['9.5', 950],
        'leading whitespace' => ['   75.25   ', 7_525],
    ]);

    it('returns null when the price element holds no digits', function () {
        $html = '<html><body><h1>Product</h1><span class="-b -ltr -tal -fs24">Out of stock</span></body></html>';

        expect($this->parser->parse($html, $this->url))->toBeNull();
    });
});
