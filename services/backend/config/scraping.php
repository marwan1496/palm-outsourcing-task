<?php

declare(strict_types=1);

use App\Scraping\Middleware\RetryWithBackoff;
use App\Scraping\Middleware\RotateProxy;
use App\Scraping\Middleware\RotateUserAgent;
use App\Scraping\Parsers\AmazonParser;
use App\Scraping\Parsers\JumiaParser;

/*
|--------------------------------------------------------------------------
| Scraping configuration
|--------------------------------------------------------------------------
|
| This file is the specification for how scraping behaves. Between the
| middleware chain, the parser list and the URL allowlist, it describes the
| whole module without needing to open a class.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Middleware chain
    |--------------------------------------------------------------------------
    |
    | Applied OUTERMOST FIRST. The order is a real design decision, not an
    | arbitrary list:
    |
    |   RetryWithBackoff    outermost, so each retry re-enters everything below
    |     RotateProxy       therefore a fresh proxy per attempt
    |       RotateUserAgent  and a fresh user-agent per attempt
    |         the GET itself
    |
    | Putting retry outermost is what makes a second attempt *look different*
    | from the first, rather than repeating an identical blocked request.
    |
    | Reordering or removing a step is a change here, not in the pipeline.
    |
    */
    'middleware' => [
        RetryWithBackoff::class,
        RotateProxy::class,
        RotateUserAgent::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Parsers
    |--------------------------------------------------------------------------
    |
    | One per supported storefront. Adding a site means writing a class that
    | implements ProductParser, adding a case to App\Enums\ProductSource, and
    | listing it here - nothing else in the module changes.
    |
    */
    'parsers' => [
        JumiaParser::class,
        AmazonParser::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP request behaviour
    |--------------------------------------------------------------------------
    */
    'http' => [
        // Total seconds allowed for one attempt. Product pages are heavy and
        // proxies add latency, so this is generous.
        'timeout' => (int) env('SCRAPER_TIMEOUT', 20),

        // Politeness pause before each request, in milliseconds. Set this
        // above zero when scraping many URLs in a batch.
        'delay_ms' => (int) env('SCRAPER_DELAY_MS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry behaviour
    |--------------------------------------------------------------------------
    |
    | Delays double after each attempt: 500ms, then 1s, then 2s. Only
    | transient failures (connection errors, 429, 5xx) are retried - a 404
    | will still be a 404 on the second attempt.
    |
    */
    'retry' => [
        'max_attempts' => (int) env('SCRAPER_MAX_ATTEMPTS', 3),
        'base_delay_ms' => (int) env('SCRAPER_RETRY_DELAY_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | User agents
    |--------------------------------------------------------------------------
    |
    | Empty means use the built-in pool in UserAgentPool::defaults(), which is
    | a spread of current desktop and mobile browsers. Override here to pin a
    | specific set.
    |
    */
    'user_agents' => [],

    /*
    |--------------------------------------------------------------------------
    | URL safety (SSRF protection)
    |--------------------------------------------------------------------------
    |
    | The scrape endpoint takes a URL and makes the server fetch it, which is a
    | Server-Side Request Forgery risk. UrlGuard enforces these rules.
    |
    | allowed_hosts is left null so it defaults to every host known to
    | App\Enums\ProductSource - the storefronts we have parsers for. There is
    | no reason to allow fetching anything else.
    |
    | verify_dns is the check that actually stops the attack: an allowlisted
    | domain whose DNS points at 169.254.169.254 would otherwise pass. Never
    | disable it outside tests.
    |
    */
    'url_guard' => [
        'allowed_hosts' => null,
        'allowed_schemes' => ['https'],
        'verify_dns' => (bool) env('SCRAPER_VERIFY_DNS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Go proxy-manager microservice
    |--------------------------------------------------------------------------
    |
    | When disabled (the default), DirectProxyProvider is bound instead and
    | every request connects directly. The whole stack therefore runs locally
    | with no proxies and no Go service.
    |
    | The circuit breaker matters: without it, a stopped Go service would add
    | the full timeout to every single scrape. After `failure_threshold`
    | consecutive failures, calls are skipped entirely for `breaker_cooldown`
    | seconds.
    |
    */
    'proxy_manager' => [
        'enabled' => (bool) env('PROXY_MANAGER_ENABLED', false),
        'url' => env('PROXY_MANAGER_URL', 'http://127.0.0.1:8081'),
        'key' => env('PROXY_MANAGER_KEY', ''),

        // Deliberately sub-second: this call is on the hot path of every
        // scrape, and going without a proxy beats waiting for one.
        'timeout' => (float) env('PROXY_MANAGER_TIMEOUT', 0.5),

        'failure_threshold' => (int) env('PROXY_MANAGER_FAILURE_THRESHOLD', 3),
        'breaker_cooldown' => (int) env('PROXY_MANAGER_BREAKER_COOLDOWN', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser fallback
    |--------------------------------------------------------------------------
    |
    | Some sites don't block on headers, they block on things a plain HTTP
    | client can't fake. Cloudflare fingerprints the TLS handshake and serves a
    | challenge that only clears if JavaScript actually runs, which is why
    | rotating user-agents alone doesn't get past it.
    |
    | With this on, a scrape that comes back as a Cloudflare challenge or a
    | CAPTCHA is retried once through a real headless Chrome (Symfony Panther).
    |
    | Off by default, for three reasons:
    |
    |   1. The brief asks for Guzzle. This is a fallback, not a replacement.
    |   2. It's slow - seconds per page instead of milliseconds - and a browser
    |      process costs far more memory than an HTTP request.
    |   3. It needs Chrome and a matching chromedriver on the machine.
    |
    | Install the driver with: vendor/bin/bdi detect drivers
    |
    */
    'browser_fallback' => [
        'enabled' => (bool) env('SCRAPER_BROWSER_FALLBACK', false),
        'timeout' => (int) env('SCRAPER_BROWSER_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Product listing cache
    |--------------------------------------------------------------------------
    |
    | Cache::flexible() takes two numbers. Inside `fresh` seconds the cached
    | value is served as-is. Between `fresh` and `stale` it is still served
    | immediately, but a background refresh is triggered - so a user never
    | waits for a cache rebuild.
    |
    | `fresh` is 30s to match the frontend's 30-second poll: a client polling
    | on schedule mostly lands on warm cache.
    |
    */
    'cache' => [
        'enabled' => (bool) env('PRODUCTS_CACHE_ENABLED', true),
        'fresh_seconds' => (int) env('PRODUCTS_CACHE_FRESH', 30),
        'stale_seconds' => (int) env('PRODUCTS_CACHE_STALE', 120),

        // Browser-facing Cache-Control lifetime, in seconds.
        'http_max_age' => (int) env('PRODUCTS_CACHE_HTTP_MAX_AGE', 15),
    ],

];
