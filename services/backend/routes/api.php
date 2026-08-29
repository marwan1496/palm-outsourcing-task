<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ScrapeController;
use App\Http\Controllers\Api\V1\ScrapeJobController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Every route is behind auth:sanctum. There are no public endpoints: the
| catalogue is the product of work we paid for in proxy traffic, and an
| unauthenticated listing endpoint is simply a free API for anyone who finds
| it.
|
| The frontend authenticates from its server-side BFF route, never from the
| browser, so the token is never exposed to a client. See
| services/frontend/src/app/api/products/route.ts.
|
*/

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->group(function (): void {

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

        // Accepts a single "url" or a "urls" array of up to 10. Carries its own
        // tighter rate limit because each URL is a real outbound fetch.
        Route::post('/scrape', [ScrapeController::class, 'store'])
            ->middleware('throttle:scrape')
            ->name('scrape.store');

        Route::get('/scrape-jobs', [ScrapeJobController::class, 'index'])->name('scrape-jobs.index');
        Route::get('/scrape-jobs/{scrapeJob}', [ScrapeJobController::class, 'show'])->name('scrape-jobs.show');

        Route::post('/scrape-jobs/{scrapeJob}/retry', [ScrapeJobController::class, 'retry'])
            ->middleware('throttle:scrape')
            ->name('scrape-jobs.retry');
    });

Route::get('/products', [ProductController::class, 'index'])
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('products.alias');

Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum')
    ->name('user.show');
