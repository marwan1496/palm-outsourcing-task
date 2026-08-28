<?php

declare(strict_types=1);

use App\Scraping\Middleware\RetryWithBackoff;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Build the middleware with a sleeper that records delays instead of waiting,
 * so backoff timing can be asserted without slowing the suite down.
 */
function retryMiddleware(int $maxAttempts = 3, array &$delays = []): RetryWithBackoff
{
    return new RetryWithBackoff(
        logger: Log::channel(),
        maxAttempts: $maxAttempts,
        baseDelayMs: 500,
        sleeper: function (int $ms) use (&$delays): void {
            $delays[] = $ms;
        },
    );
}

/**
 * Run a request through the middleware.
 */
function runRetry(RetryWithBackoff $middleware): Response
{
    return $middleware->handle(
        app(HttpFactory::class)->timeout(5),
        fn ($request) => $request->get('https://example.test'),
    );
}

describe('retrying transient failures', function () {
    it('retries a retryable status and returns the eventual success', function (int $status) {
        Http::fakeSequence()->pushStatus($status)->push('recovered', 200);

        $response = runRetry(retryMiddleware());

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('recovered');
        Http::assertSentCount(2);
    })->with([
        'request timeout' => 408,
        'too early' => 425,
        'rate limited' => 429,
        'server error' => 500,
        'bad gateway' => 502,
        'unavailable' => 503,
        'gateway timeout' => 504,
    ]);

    it('retries a connection failure', function () {
        Http::fakeSequence()
            ->pushFailedConnection()
            ->push('recovered', 200);

        expect(runRetry(retryMiddleware())->status())->toBe(200);
    });

    it('gives up after the configured number of attempts', function () {
        Http::fake(['*' => Http::response('down', 503)]);

        $response = runRetry(retryMiddleware(maxAttempts: 3));

        // The last response is returned rather than thrown, so the caller can
        // decide what to do with it.
        expect($response->status())->toBe(503);
        Http::assertSentCount(3);
    });

    it('rethrows a connection exception once attempts run out', function () {
        Http::fake(fn () => throw new ConnectionException('unreachable'));

        expect(fn () => runRetry(retryMiddleware(maxAttempts: 2)))
            ->toThrow(ConnectionException::class);
    });
});

describe('not retrying permanent failures', function () {
    // A 404 will still be a 404 next time; retrying wastes a proxy and a second.
    it('returns immediately for a status that will not change', function (int $status) {
        Http::fake(['*' => Http::response('nope', $status)]);

        expect(runRetry(retryMiddleware())->status())->toBe($status);
        Http::assertSentCount(1);
    })->with([
        'bad request' => 400,
        'unauthorized' => 401,
        'forbidden' => 403,
        'not found' => 404,
        'gone' => 410,
    ]);

    it('returns a success on the first attempt without retrying', function () {
        Http::fake(['*' => Http::response('ok', 200)]);

        expect(runRetry(retryMiddleware())->status())->toBe(200);
        Http::assertSentCount(1);
    });
});

describe('backoff timing', function () {
    // Doubling matters: retrying at a fixed interval keeps the pressure on a
    // site that is already rate limiting us and extends the block.
    it('doubles the delay after each attempt', function () {
        expect(retryMiddleware()->delayForAttempt(1))->toBe(500)
            ->and(retryMiddleware()->delayForAttempt(2))->toBe(1000)
            ->and(retryMiddleware()->delayForAttempt(3))->toBe(2000)
            ->and(retryMiddleware()->delayForAttempt(4))->toBe(4000);
    });

    it('waits between attempts using the growing delays', function () {
        Http::fake(['*' => Http::response('down', 503)]);

        $delays = [];
        runRetry(retryMiddleware(maxAttempts: 3, delays: $delays));

        // Two waits for three attempts.
        expect($delays)->toBe([500, 1000]);
    });

    it('does not wait at all when the first attempt succeeds', function () {
        Http::fake(['*' => Http::response('ok', 200)]);

        $delays = [];
        runRetry(retryMiddleware(delays: $delays));

        expect($delays)->toBeEmpty();
    });
});
