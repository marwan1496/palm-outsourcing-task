<?php

declare(strict_types=1);

use App\Enums\ProductSource;

describe('fromUrl', function () {
    it('identifies the storefront from a product URL', function (string $url, ProductSource $expected) {
        expect(ProductSource::fromUrl($url))->toBe($expected);
    })->with([
        'jumia egypt' => ['https://www.jumia.com.eg/product/abc.html', ProductSource::Jumia],
        'jumia nigeria' => ['https://www.jumia.com.ng/product/abc.html', ProductSource::Jumia],
        'jumia apex' => ['https://jumia.com.eg/product/abc.html', ProductSource::Jumia],
        'amazon egypt' => ['https://www.amazon.eg/dp/B01234', ProductSource::Amazon],
        'amazon uk' => ['https://www.amazon.co.uk/dp/B01234', ProductSource::Amazon],
    ]);

    it('returns null for an unsupported storefront', function () {
        expect(ProductSource::fromUrl('https://www.ebay.com/itm/123'))->toBeNull();
    });

    it('returns null for a URL with no host', function () {
        expect(ProductSource::fromUrl('not-a-url'))->toBeNull();
    });

    it('matches the host case-insensitively', function () {
        expect(ProductSource::fromUrl('https://WWW.JUMIA.COM.EG/x'))->toBe(ProductSource::Jumia);
    });

    // Matching must be on a dot boundary. A lookalike domain that merely
    // starts with an allowed one belongs to whoever registered it.
    it('rejects a lookalike domain that only starts with an allowed host', function () {
        expect(ProductSource::fromUrl('https://jumia.com.eg.evil.test/x'))->toBeNull();
    });

    it('rejects a domain that merely contains an allowed host', function () {
        expect(ProductSource::fromUrl('https://notjumia.com.eg/x'))->toBeNull();
    });
});

describe('labels and host patterns', function () {
    it('gives every case a human-readable label', function (ProductSource $source) {
        expect($source->label())->not->toBeEmpty();
    })->with(ProductSource::cases());

    it('gives every case at least one host pattern', function (ProductSource $source) {
        expect($source->hostPatterns())->not->toBeEmpty();
    })->with(ProductSource::cases());

    it('collects every host pattern across all sources', function () {
        $all = ProductSource::allHostPatterns();

        expect($all)->toContain('jumia.com.eg')
            ->and($all)->toContain('amazon.eg')
            ->and(count($all))->toBeGreaterThanOrEqual(
                count(ProductSource::Jumia->hostPatterns()) + count(ProductSource::Amazon->hostPatterns())
            );
    });

    it('has no duplicate host patterns across sources', function () {
        $all = ProductSource::allHostPatterns();

        expect($all)->toHaveCount(count(array_unique($all)));
    });
});
