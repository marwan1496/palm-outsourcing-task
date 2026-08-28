<?php

declare(strict_types=1);

namespace App\Scraping\Contracts;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * One step in the scraping pipeline.
 *
 * Middleware wrap the outgoing HTTP request the same way Laravel's own HTTP
 * middleware wrap an incoming one: each receives the request, may change it,
 * calls the next step, and may then inspect the response on the way back out.
 *
 * That two-way shape is what makes proxy rotation work in a single class -
 * RotateProxy picks a proxy on the way in and reports the outcome on the way
 * back out.
 *
 * The chain is listed in config/scraping.php, so reordering or disabling a
 * step is a configuration change rather than a code change.
 */
interface ScraperMiddleware
{
    /**
     * Handle the outgoing request.
     *
     * @param  PendingRequest  $request  The Guzzle-backed request being built.
     * @param  Closure(PendingRequest): Response  $next  The next step in the chain.
     */
    public function handle(PendingRequest $request, Closure $next): Response;
}
