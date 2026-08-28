<?php

declare(strict_types=1);

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Scraping\Exceptions\ScrapeFailedException;
use App\Scraping\Exceptions\UnsafeUrlException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Application bootstrap
|--------------------------------------------------------------------------
|
| Laravel 13's slim skeleton configures middleware, rate limiting and
| exception rendering here. There is no app/Http/Kernel.php any more - this
| file replaces it, and it is the one place to look for how requests are
| handled.
|
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Applied to every /api route.
        $middleware->api(append: [
            ForceJsonResponse::class,
            SecurityHeaders::class,
        ]);

        /*
         * Never redirect an unauthenticated API request to a login page.
         *
         * By default Laravel redirects guests to a route named "login". This
         * application has no such route - it is a token API with no web
         * session - so that redirect throws RouteNotFoundException and turns a
         * clean 401 into a 500.
         *
         * Returning null for API routes makes the auth middleware respond with
         * 401 instead of attempting a redirect. This is more reliable than
         * depending on the client sending an Accept header, because Symfony
         * caches the parsed Accept values and a client may send none at all.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always answer API routes with JSON, never an HTML error page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * A rejected URL is the caller's mistake, not a server fault, so it
         * is reported as 422 rather than 500. The guard's message names the
         * rule that failed without revealing internal addresses.
         */
        $exceptions->render(function (UnsafeUrlException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'The provided URL was rejected.',
                    'errors' => ['url' => [$e->getMessage()]],
                ], 422);
            }

            return null;
        });

        /*
         * The scrape was attempted and did not produce a product. 502 is the
         * honest code: an upstream site, not this API, is the thing that
         * failed.
         */
        $exceptions->render(function (ScrapeFailedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'The product could not be scraped.',
                    'reason' => $e->getMessage(),
                ], 502);
            }

            return null;
        });
    })
    ->booted(function (): void {
        /*
        |----------------------------------------------------------------------
        | Rate limiting
        |----------------------------------------------------------------------
        |
        | Two limiters, because the two kinds of endpoint cost very different
        | amounts to serve.
        |
        | "api" covers reads. 60/minute is comfortably above the frontend's
        | 30-second poll (2/minute per tab) while still stopping a scraper
        | from enumerating the catalogue.
        |
        | "scrape" covers writes. Each request causes an outbound fetch through
        | a proxy, so it is far more expensive - both for us and for the site
        | being scraped - and is limited far more tightly.
        |
        | Both key on the authenticated token where possible, so one noisy
        | client cannot exhaust the limit for everyone behind a shared IP.
        |
        */
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        RateLimiter::for('scrape', function (Request $request): Limit {
            return Limit::perMinute(10)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
    })
    ->create();
