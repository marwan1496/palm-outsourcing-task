<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds defence-in-depth response headers to every API response.
 *
 * None of these are the primary defence for anything - authentication, the
 * URL guard and validation do the real work. They close off the ways a
 * response can still be misused if something else goes wrong, which is cheap
 * insurance for one small class.
 */
class SecurityHeaders
{
    /**
     * Attach the headers on the way out.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Stop browsers guessing a content type. Without this, a JSON response
        // containing attacker-controlled text can be sniffed as HTML and
        // executed - our product titles come from scraped pages, so this is a
        // real consideration rather than a theoretical one.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // The API returns no HTML, so it should never be framed.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Do not leak our URLs (which can contain query parameters) to
        // third-party sites through the Referer header.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // This is a JSON API: it needs no camera, microphone or geolocation.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // A JSON response should never execute anything. This CSP is
        // restrictive precisely because there is nothing legitimate to allow.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        // Do not let intermediaries cache authenticated responses under a
        // different user's identity. The product endpoints set their own,
        // more permissive Cache-Control, which is respected here.
        if (! $response->headers->has('Cache-Control')) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
