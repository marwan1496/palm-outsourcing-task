<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes every API request behave as though it asked for JSON.
 *
 * Why: without an Accept: application/json header, Laravel renders errors as
 * HTML. A client calling the API with curl and no headers would get an HTML
 * error page for a 401 or a 422, which is unusable and leaks framework markup.
 *
 * Forcing the header on the way in means the API answers with JSON no matter
 * how a client is configured.
 */
class ForceJsonResponse
{
    /**
     * Rewrite the Accept header before the request is handled.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
