<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ScrapeController;
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

        // Read endpoints. Cached, ETagged, and polled every 30s by the frontend.
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

        // Write endpoint. Queues a scrape; far more expensive, so it carries
        // its own tighter rate limit on top of the group's.
        Route::post('/scrape', [ScrapeController::class, 'store'])
            ->middleware('throttle:scrape')
            ->name('scrape.store');
    });

/*
| The task brief names /api/products specifically. The canonical route is the
| versioned one above; this alias keeps the literal path in the brief working
| so both URLs resolve to the same controller.
*/
Route::get('/products', [ProductController::class, 'index'])
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('products.alias');

/*
| Who am I - lets the frontend verify at startup that its token is valid,
| rather than discovering it is not on the first product fetch.
*/
Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum')
    ->name('user.show');
