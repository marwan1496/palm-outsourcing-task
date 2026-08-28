<?php

declare(strict_types=1);

namespace App\Scraping\Middleware;

use App\Scraping\Contracts\ProxyProvider;
use App\Scraping\Contracts\ScraperMiddleware;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Routes the request through a proxy chosen by the Go proxy-manager, then
 * reports back how that proxy performed.
 *
 * This is the middleware that shows why the pipeline is two-way: the proxy is
 * selected on the way in, and the outcome is reported on the way out, in one
 * readable class.
 *
 * The proxy option is handed to Guzzle directly via withOptions(['proxy' => …]).
 * Laravel's Http facade is a wrapper around Guzzle, so this is a real Guzzle
 * request option, not a Laravel abstraction over one.
 *
 * When no proxy is available - the pool is empty, or the Go service is down -
 * the request simply goes out directly. Losing proxy rotation degrades
 * anonymity, not availability.
 */
class RotateProxy implements ScraperMiddleware
{
    public function __construct(
        private readonly ProxyProvider $proxies,
    ) {}

    /**
     * Attach a proxy if one is available, and report the outcome afterwards.
     */
    public function handle(PendingRequest $request, Closure $next): Response
    {
        $proxy = $this->proxies->next();

        if ($proxy === null) {
            // No proxy available: connect directly. This is the normal path
            // when running locally without a proxy pool.
            return $next($request);
        }

        $request = $request->withOptions(['proxy' => $proxy['url']]);

        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // A transport-level failure is the clearest possible signal that
            // this proxy is unhealthy, so report it before rethrowing.
            $this->report($proxy['id'], false, $startedAt);

            throw $e;
        }

        // A 5xx means the proxy or the route through it is struggling. A 4xx
        // is the target site's verdict on us and says nothing about the proxy,
        // so it is not counted against it.
        $this->report($proxy['id'], ! $response->serverError(), $startedAt);

        return $response;
    }

    /**
     * Send the outcome to the proxy manager.
     *
     * Reporting is best-effort telemetry: the ProxyProvider contract requires
     * implementations to swallow their own errors, so a successful scrape is
     * never turned into a failure by a reporting problem.
     */
    private function report(string $proxyId, bool $success, float $startedAt): void
    {
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->proxies->report($proxyId, $success, $latencyMs);
    }
}
