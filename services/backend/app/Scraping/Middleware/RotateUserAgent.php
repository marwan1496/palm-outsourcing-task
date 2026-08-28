<?php

declare(strict_types=1);

namespace App\Scraping\Middleware;

use App\Scraping\Contracts\ScraperMiddleware;
use App\Scraping\Support\UserAgentPool;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Sends a different browser User-Agent on each request.
 *
 * This is the middleware that satisfies the brief's user-agent rotation
 * requirement. It also sets the companion headers a real browser always sends
 * - Accept, Accept-Language, and so on. A request with a convincing
 * User-Agent but none of those headers is trivially identifiable as a bot,
 * so rotating the User-Agent alone would achieve very little.
 */
class RotateUserAgent implements ScraperMiddleware
{
    public function __construct(
        private readonly UserAgentPool $pool,
    ) {}

    /**
     * Attach a rotated User-Agent plus supporting browser headers.
     */
    public function handle(PendingRequest $request, Closure $next): Response
    {
        return $next($request->withHeaders([
            'User-Agent' => $this->pool->next(),

            // The header set a real browser sends alongside its User-Agent.
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9,ar;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Upgrade-Insecure-Requests' => '1',

            // Fetch metadata headers, sent by every modern browser on a
            // top-level navigation. Their absence is a common bot signal.
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',

            'Cache-Control' => 'max-age=0',
        ]));
    }
}
