<?php

declare(strict_types=1);

use App\Enums\ProductSource;
use App\Scraping\Parsers\AmazonParser;

beforeEach(function () {
    $this->parser = new AmazonParser;
    $this->url = 'https://www.amazon.eg/dp/B01LR8CIRC';
});

describe('parsing a real product page', function () {
    it('reports the storefront it handles', function () {
        expect($this->parser->source())->toBe(ProductSource::Amazon);
    });

    it('extracts the title and trims Amazon\'s ragged whitespace', function () {
        $product = $this->parser->parse(fixtureHtml('amazon-product.html'), $this->url);

        expect($product->title)
            ->toBe('Anker PowerCore 20000mAh Portable Charger, Ultra High Capacity Power Bank');
    });

    it('reads the price from the offscreen span', function () {
        $product = $this->parser->parse(fixtureHtml('amazon-product.html'), $this->url);

        expect($product->price)->toBe(4_999);
    });

    it('extracts the landing image', function () {
        $product = $this->parser->parse(fixtureHtml('amazon-product.html'), $this->url);

        expect($product->imageUrl)->toBeAbsoluteUrl()
            ->and($product->imageUrl)->toContain('media-amazon.com');
    });
});

describe('detecting the bot check', function () {
    // A CAPTCHA is Amazon's most common non-product response. Detecting it
    // explicitly matters because "we were blocked" and "the layout changed"
    // call for completely different responses from an operator.
    it('returns null for a CAPTCHA page instead of parsing it as a product', function () {
        expect($this->parser->parse(fixtureHtml('amazon-captcha.html'), $this->url))->toBeNull();
    });

    it('recognises each CAPTCHA marker independently', function (string $marker) {
        $html = "<html><body><p>{$marker}</p><span id='productTitle'>X</span>
                 <span class='a-price'><span class='a-offscreen'>\$10.00</span></span></body></html>";

        expect($this->parser->parse($html, $this->url))->toBeNull();
    })->with([
        'prompt text' => 'Enter the characters you see below',
        'support email' => 'api-services-support@amazon.com',
        'form action' => '/errors/validateCaptcha',
    ]);
});

describe('the split price layout', function () {
    // An older Amazon layout renders "49" and "99" in separate elements, so
    // neither alone is a usable price.
    it('reassembles a price split across whole and fraction elements', function () {
        $html = <<<'HTML'
        <html><body>
            <span id="productTitle">Split Price Product</span>
            <span class="a-price-whole">49<span class="a-price-decimal">.</span></span>
            <span class="a-price-fraction">99</span>
        </body></html>
        HTML;

        expect($this->parser->parse($html, $this->url)->price)->toBe(4_999);
    });

    it('assumes zero cents when only the whole part is present', function () {
        $html = <<<'HTML'
        <html><body>
            <span id="productTitle">Whole Only</span>
            <span class="a-price-whole">120</span>
        </body></html>
        HTML;

        expect($this->parser->parse($html, $this->url)->price)->toBe(12_000);
    });

    it('prefers the offscreen span over the split elements when both exist', function () {
        $html = <<<'HTML'
        <html><body>
            <span id="productTitle">Both Layouts</span>
            <span class="a-price"><span class="a-offscreen">$75.50</span></span>
            <span class="a-price-whole">99</span>
            <span class="a-price-fraction">99</span>
        </body></html>
        HTML;

        expect($this->parser->parse($html, $this->url)->price)->toBe(7_550);
    });
});

describe('currency by marketplace', function () {
    // Amazon rarely states the currency machine-readably, but the domain
    // determines it reliably.
    it('infers the currency from the marketplace domain', function (string $url, string $expected) {
        $html = <<<'HTML'
        <html><body>
            <span id="productTitle">Product</span>
            <span class="a-price"><span class="a-offscreen">10.00</span></span>
        </body></html>
        HTML;

        expect($this->parser->parse($html, $url)->currency)->toBe($expected);
    })->with([
        'egypt' => ['https://www.amazon.eg/dp/B1', 'EGP'],
        'uk' => ['https://www.amazon.co.uk/dp/B1', 'GBP'],
        'germany' => ['https://www.amazon.de/dp/B1', 'EUR'],
        'saudi' => ['https://www.amazon.sa/dp/B1', 'SAR'],
        'us' => ['https://www.amazon.com/dp/B1', 'USD'],
    ]);
});

describe('failing soft', function () {
    it('returns null for empty input', function () {
        expect($this->parser->parse('', $this->url))->toBeNull();
    });

    it('returns null when there is no product on the page', function () {
        expect($this->parser->parse('<html><body><p>Nothing here</p></body></html>', $this->url))->toBeNull();
    });

    it('returns null when a title exists but no price does', function () {
        $html = '<html><body><span id="productTitle">Titled but priceless</span></body></html>';

        expect($this->parser->parse($html, $this->url))->toBeNull();
    });

    it('does not throw on malformed HTML', function () {
        $this->parser->parse('<html><body><span id="productTitle">Unclosed', $this->url);
    })->throwsNoExceptions();

    it('falls back to the meta title tag when productTitle is missing', function () {
        $html = <<<'HTML'
        <html><head><meta name="title" content="Meta Titled Product"></head>
        <body><span class="a-price"><span class="a-offscreen">$15.00</span></span></body></html>
        HTML;

        expect($this->parser->parse($html, $this->url)->title)->toBe('Meta Titled Product');
    });
});
