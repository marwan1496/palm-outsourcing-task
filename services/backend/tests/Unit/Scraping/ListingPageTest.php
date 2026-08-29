<?php

declare(strict_types=1);

use App\Scraping\Parsers\JumiaParser;

/**
 * A listing page must never be read as a product.
 *
 * The failure mode here is worse than a crash. A dead Jumia product URL
 * redirects to a category page which returns 200 and carries a Product block
 * for every item on it. Read the first one and you store a completely
 * unrelated item under the URL that was requested — and because that product
 * is perfectly valid, the job reports success. Nothing errors and nothing logs;
 * the database just quietly holds the wrong thing.
 */
beforeEach(function () {
    $this->parser = new JumiaParser;
    $this->deadUrl = 'https://www.jumia.com.eg/this-product-does-not-exist-99999999.html';
});

describe('the real category page a dead URL redirects to', function () {
    it('is not read as a product', function () {
        expect($this->parser->parse(fixtureHtml('jumia-category.html'), $this->deadUrl))->toBeNull();
    });

    it('does not store the first item from the listing', function () {
        $result = $this->parser->parse(fixtureHtml('jumia-category.html'), $this->deadUrl);

        expect($result)->toBeNull(
            'A category page must not yield whichever product it happens to list first.',
        );
    });
});

describe('container types in structured data', function () {
    it('refuses a page whose JSON-LD is a listing container', function (string $type) {
        $html = <<<HTML
        <html><head><script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "{$type}",
          "itemListElement": [
            { "@type": "Product", "name": "First Listed Item",
              "offers": { "@type": "Offer", "price": "199.00", "priceCurrency": "EGP" } }
          ]
        }
        </script></head>
        <body><h1>Some Category</h1></body></html>
        HTML;

        expect($this->parser->parse($html, $this->deadUrl))->toBeNull();
    })->with(['ItemList', 'CollectionPage', 'SearchResultsPage']);

    // A product page describes one product. Several means it is a list.
    it('refuses a page carrying more than one Product block', function () {
        $html = <<<'HTML'
        <html><head><script type="application/ld+json">
        [
          { "@type": "Product", "name": "First",  "offers": { "price": "10.00", "priceCurrency": "EGP" } },
          { "@type": "Product", "name": "Second", "offers": { "price": "20.00", "priceCurrency": "EGP" } }
        ]
        </script></head><body></body></html>
        HTML;

        expect($this->parser->parse($html, 'https://www.jumia.com.eg/x.html'))->toBeNull();
    });
});

describe('not breaking the normal path', function () {
    // The guard is worthless if it also rejects real products, so the existing
    // fixtures have to keep behaving exactly as they did.
    it('still parses a genuine single-product page', function () {
        $product = $this->parser->parse(
            fixtureHtml('jumia-product.html'),
            'https://www.jumia.com.eg/samsung-galaxy-a55.html',
        );

        expect($product)->not->toBeNull()
            ->and($product->title)->toContain('Samsung Galaxy A55')
            ->and($product->price)->toBe(1_849_900);
    });

    it('still parses a product page carrying a breadcrumb', function () {
        // Breadcrumbs are a list, but not a list of products, so they must not
        // trip the guard.
        $html = <<<'HTML'
        <html><head><script type="application/ld+json">
        { "@type": "Product", "name": "Real Product",
          "offers": { "@type": "Offer", "price": "499.00", "priceCurrency": "EGP" } }
        </script>
        <script type="application/ld+json">
        { "@type": "BreadcrumbList", "itemListElement": [] }
        </script></head><body></body></html>
        HTML;

        $product = $this->parser->parse($html, 'https://www.jumia.com.eg/real.html');

        expect($product)->not->toBeNull()
            ->and($product->title)->toBe('Real Product');
    });
});
