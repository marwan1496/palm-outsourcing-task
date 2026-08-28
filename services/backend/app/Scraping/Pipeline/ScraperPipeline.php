<?php

declare(strict_types=1);

namespace App\Scraping\Pipeline;

use App\Scraping\Contracts\ScraperMiddleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Pipeline\Pipeline as LaravelPipeline;

/**
 * Fetches a page by sending it through the configured middleware chain.
 *
 * This class is deliberately tiny, because it delegates the hard part to
 * Laravel's own Pipeline - the same class that runs the framework's HTTP
 * middleware stack. Reimplementing a middleware runner would have been another
 * fifty lines to write, test and explain, for behaviour the framework already
 * ships.
 *
 * The chain is built from config/scraping.php, so the order of middleware is a
 * configuration decision. That ordering matters:
 *
 *     RetryWithBackoff   <- outermost, so a retry re-enters everything below
 *       RotateProxy      <- picks a fresh proxy per attempt
 *         RotateUserAgent  <- picks a fresh user-agent per attempt
 *           the actual GET
 *
 * Because retry sits outermost, a second attempt is not the same request sent
 * twice - it goes out through a different proxy with a different user-agent.
 *
 * On Guzzle: Laravel's Http client is a wrapper around Guzzle, and
 * withOptions() passes options straight through to it. The proxy option set by
 * RotateProxy is a native Guzzle request option.
 */
class ScraperPipeline
{
    /**
     * @param  list<ScraperMiddleware>  $middleware  Outermost first.
     * @param  int  $timeoutSeconds  Total time allowed for one attempt.
     * @param  int  $delayMs  Politeness pause before each request. A config
     *                        value rather than a middleware class, because it
     *                        needs no request or response - one less moving
     *                        part in the chain.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $middleware,
        private readonly int $timeoutSeconds = 20,
        private readonly int $delayMs = 0,
    ) {}

    /**
     * Fetch a URL through the full middleware chain.
     *
     * @throws ConnectionException when every attempt fails.
     */
    public function fetch(string $url): Response
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $request = $this->baseRequest();

        return (new LaravelPipeline)
            ->send($request)
            ->through($this->middleware)
            ->then(fn (PendingRequest $request): Response => $request->get($url));
    }

    /**
     * The request every scrape starts from, before middleware decorate it.
     */
    private function baseRequest(): PendingRequest
    {
        // Note: no ->throw() call here, deliberately. Laravel's HTTP client
        // does not throw on 4xx/5xx unless asked to, and we want those
        // responses returned rather than thrown - a blocked page is data the
        // pipeline needs to inspect (RetryWithBackoff decides whether a 503 is
        // worth another attempt), not an exception to unwind through.
        return $this->http
            ->timeout($this->timeoutSeconds)
            // Storefronts redirect heavily (locale, canonical URLs, http->https).
            ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => true]]);
    }

    /**
     * The middleware in this pipeline, exposed for tests and diagnostics.
     *
     * @return list<ScraperMiddleware>
     */
    public function middleware(): array
    {
        return $this->middleware;
    }
}
