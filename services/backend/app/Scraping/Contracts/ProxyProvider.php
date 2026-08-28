<?php

declare(strict_types=1);

namespace App\Scraping\Contracts;

/**
 * Supplies the proxy to use for the next request, and takes feedback on how it
 * performed.
 *
 * Two implementations exist:
 *
 *   GoProxyManagerProvider  Asks the Go microservice for a rotating proxy.
 *   DirectProxyProvider     A null object that always says "no proxy".
 *
 * The null object is the important half. When the Go service is down,
 * scraping degrades to direct connections instead of failing, and no calling
 * code needs a single `if ($proxy === null)` branch.
 */
interface ProxyProvider
{
    /**
     * The proxy to use next.
     *
     * @return array{id: string, url: string}|null Null means "connect
     *                                             directly" - an expected
     *                                             outcome, not an error.
     */
    public function next(): ?array;

    /**
     * Report how a request through the given proxy went.
     *
     * This feedback is what lets the pool bench a failing proxy before a user
     * notices it. Implementations must never throw: reporting is best-effort
     * telemetry and must not turn a successful scrape into a failed one.
     *
     * @param  string  $proxyId  The id returned by next().
     * @param  bool  $success  Whether the request succeeded.
     * @param  int  $latencyMs  How long it took, in milliseconds.
     */
    public function report(string $proxyId, bool $success, int $latencyMs): void;
}
