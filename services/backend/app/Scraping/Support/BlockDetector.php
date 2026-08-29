<?php

declare(strict_types=1);

namespace App\Scraping\Support;

/**
 * Works out whether a response is the site turning us away.
 *
 * This matters because "we were blocked" and "the page changed" need
 * completely different responses. A layout change means fixing a parser. A
 * block means backing off, rotating a proxy, or falling back to a real
 * browser. Guessing wrong sends you debugging selectors when the page never
 * arrived in the first place.
 *
 * We hit this for real while building: curl fetched a Jumia product page fine,
 * but the scraper got a 403 whose body was Cloudflare's "Just a moment..."
 * interstitial. The markers below come from that actual response.
 */
class BlockDetector
{
    /**
     * Statuses that mean "not today" rather than "not found".
     *
     * @var list<int>
     */
    private const BLOCKED_STATUSES = [401, 403, 429];

    /**
     * Strings that appear in Cloudflare's challenge pages.
     *
     * @var list<string>
     */
    private const CLOUDFLARE_MARKERS = [
        'Just a moment...',
        'cf-browser-verification',
        'cf_chl_opt',
        '/cdn-cgi/challenge-platform',
        'Checking your browser before accessing',
        'Enable JavaScript and cookies to continue',
    ];

    /**
     * Strings that appear on Amazon's bot check.
     *
     * @var list<string>
     */
    private const CAPTCHA_MARKERS = [
        'Enter the characters you see below',
        'api-services-support@amazon.com',
        '/errors/validateCaptcha',
        'Type the characters you see in this image',
    ];

    /**
     * Generic "you are a robot" wording used by several anti-bot vendors.
     *
     * @var list<string>
     */
    private const GENERIC_MARKERS = [
        'Pardon Our Interruption',
        'Access Denied',
        'unusual traffic from your computer',
        'Request unsuccessful. Incapsula',
    ];

    /**
     * Whether this response is a block.
     *
     * @param  int|null  $status  Null when a browser rendered the page.
     */
    public function isBlocked(?int $status, string $body): bool
    {
        return $this->reason($status, $body) !== null;
    }

    /**
     * Why we think this is a block, or null if it looks like a real page.
     *
     * Returning the reason rather than a bare bool means the log line says
     * "Cloudflare challenge" instead of just "blocked", which is the
     * difference between a useful log and a shrug.
     */
    public function reason(?int $status, string $body): ?string
    {
        if ($this->looksLikeCloudflareChallenge($body)) {
            return 'Cloudflare challenge page';
        }

        if ($this->looksLikeCaptcha($body)) {
            return 'CAPTCHA page';
        }

        if ($this->matchesAny($body, self::GENERIC_MARKERS)) {
            return 'Anti-bot interstitial';
        }

        if ($status !== null && in_array($status, self::BLOCKED_STATUSES, true)) {
            return sprintf('HTTP %d', $status);
        }

        return null;
    }

    /**
     * Cloudflare's interstitial, the one we actually hit on Jumia.
     */
    public function looksLikeCloudflareChallenge(string $body): bool
    {
        return $this->matchesAny($body, self::CLOUDFLARE_MARKERS);
    }

    /**
     * Amazon's bot check.
     *
     * Lives here rather than in AmazonParser so the fetcher can react to it
     * before the parser is ever reached, and so there is one list to maintain.
     */
    public function looksLikeCaptcha(string $body): bool
    {
        return $this->matchesAny($body, self::CAPTCHA_MARKERS);
    }

    /**
     * Whether a block is worth retrying through a browser.
     *
     * A Cloudflare challenge or a CAPTCHA is a JavaScript problem, and a real
     * browser might solve it. A plain 429 is rate limiting: retrying instantly
     * through a browser just burns another request, so it isn't worth it.
     */
    public function isWorthRetryingInBrowser(?int $status, string $body): bool
    {
        return $this->looksLikeCloudflareChallenge($body)
            || $this->looksLikeCaptcha($body)
            || $status === 403;
    }

    /**
     * @param  list<string>  $markers
     */
    private function matchesAny(string $body, array $markers): bool
    {
        if ($body === '') {
            return false;
        }

        foreach ($markers as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }
}
