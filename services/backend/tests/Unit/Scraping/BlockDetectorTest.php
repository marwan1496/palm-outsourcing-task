<?php

declare(strict_types=1);

use App\Scraping\Support\BlockDetector;

/**
 * The Cloudflare fixture here isn't invented. It's the actual 403 body Jumia
 * returned to this scraper, saved so the behaviour stays testable after the
 * block lifts.
 */
beforeEach(function () {
    $this->detector = new BlockDetector;
});

describe('recognising a real Cloudflare challenge', function () {
    it('spots the captured Jumia 403', function () {
        $body = fixtureHtml('cloudflare-challenge.html');

        expect($this->detector->looksLikeCloudflareChallenge($body))->toBeTrue()
            ->and($this->detector->isBlocked(403, $body))->toBeTrue();
    });

    it('names Cloudflare as the reason', function () {
        expect($this->detector->reason(403, fixtureHtml('cloudflare-challenge.html')))
            ->toBe('Cloudflare challenge page');
    });

    // A browser might clear a JavaScript challenge, so this one is worth
    // retrying differently rather than just giving up.
    it('considers it worth retrying in a browser', function () {
        expect($this->detector->isWorthRetryingInBrowser(403, fixtureHtml('cloudflare-challenge.html')))
            ->toBeTrue();
    });

    it('recognises each Cloudflare marker on its own', function (string $marker) {
        expect($this->detector->looksLikeCloudflareChallenge("<html><body>{$marker}</body></html>"))->toBeTrue();
    })->with([
        'interstitial title' => 'Just a moment...',
        'verification div' => 'cf-browser-verification',
        'challenge options' => 'cf_chl_opt',
        'challenge platform' => '/cdn-cgi/challenge-platform',
        'browser check text' => 'Checking your browser before accessing',
    ]);
});

describe('recognising CAPTCHAs', function () {
    it('spots Amazon\'s bot check', function () {
        $body = fixtureHtml('amazon-captcha.html');

        expect($this->detector->looksLikeCaptcha($body))->toBeTrue()
            ->and($this->detector->reason(200, $body))->toBe('CAPTCHA page');
    });

    // Amazon serves its CAPTCHA with a 200, so status alone would miss it
    // entirely. This is why the body is inspected rather than trusted.
    it('detects a CAPTCHA even behind a 200 status', function () {
        expect($this->detector->isBlocked(200, fixtureHtml('amazon-captcha.html')))->toBeTrue();
    });

    it('recognises each CAPTCHA marker on its own', function (string $marker) {
        expect($this->detector->looksLikeCaptcha("<html><body>{$marker}</body></html>"))->toBeTrue();
    })->with([
        'prompt' => 'Enter the characters you see below',
        'support address' => 'api-services-support@amazon.com',
        'validate path' => '/errors/validateCaptcha',
    ]);
});

describe('recognising generic anti-bot pages', function () {
    it('spots the interstitials other vendors serve', function (string $marker) {
        $body = "<html><body><h1>{$marker}</h1></body></html>";

        expect($this->detector->isBlocked(200, $body))->toBeTrue()
            ->and($this->detector->reason(200, $body))->toBe('Anti-bot interstitial');
    })->with([
        'pardon our interruption' => 'Pardon Our Interruption',
        'access denied' => 'Access Denied',
        'unusual traffic' => 'unusual traffic from your computer',
        'incapsula' => 'Request unsuccessful. Incapsula',
    ]);

    it('spots the blocked-page fixture already in the suite', function () {
        expect($this->detector->isBlocked(200, fixtureHtml('jumia-blocked.html')))->toBeTrue();
    });
});

describe('status codes', function () {
    it('treats auth and rate-limit statuses as blocks', function (int $status) {
        expect($this->detector->isBlocked($status, '<html><body>nothing here</body></html>'))->toBeTrue();
    })->with([401, 403, 429]);

    it('does not treat a missing page as a block', function () {
        // A 404 is the site answering honestly, not turning us away.
        expect($this->detector->isBlocked(404, '<html><body>Not found</body></html>'))->toBeFalse();
    });

    it('does not treat a server error as a block', function () {
        expect($this->detector->isBlocked(500, '<html><body>Server Error</body></html>'))->toBeFalse();
    });
});

describe('not crying wolf', function () {
    // The expensive mistake: treating a real product page as a block and
    // launching a browser for nothing.
    it('leaves a genuine product page alone', function (string $fixture) {
        $body = fixtureHtml($fixture);

        expect($this->detector->isBlocked(200, $body))->toBeFalse()
            ->and($this->detector->reason(200, $body))->toBeNull();
    })->with(['jumia-product.html', 'amazon-product.html']);

    it('does not flag an empty body on markers alone', function () {
        expect($this->detector->looksLikeCloudflareChallenge(''))->toBeFalse()
            ->and($this->detector->looksLikeCaptcha(''))->toBeFalse();
    });

    it('does not flag ordinary HTML', function () {
        expect($this->detector->isBlocked(200, '<html><body><h1>Hello</h1></body></html>'))->toBeFalse();
    });
});

describe('deciding whether a browser would help', function () {
    // Rate limiting is about how often we ask, not how we ask. Retrying
    // instantly in a browser just spends another request against the limit.
    it('will not retry a plain rate limit in a browser', function () {
        expect($this->detector->isWorthRetryingInBrowser(429, '<html><body>Slow down</body></html>'))
            ->toBeFalse();
    });

    it('will retry a bare 403 in a browser', function () {
        expect($this->detector->isWorthRetryingInBrowser(403, '<html><body>Forbidden</body></html>'))
            ->toBeTrue();
    });

    it('will retry a CAPTCHA served with a 200', function () {
        expect($this->detector->isWorthRetryingInBrowser(200, fixtureHtml('amazon-captcha.html')))
            ->toBeTrue();
    });

    it('will not launch a browser for a page that loaded fine', function () {
        expect($this->detector->isWorthRetryingInBrowser(200, fixtureHtml('jumia-product.html')))
            ->toBeFalse();
    });
});
