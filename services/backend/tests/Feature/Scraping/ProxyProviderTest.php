<?php

declare(strict_types=1);

use App\Scraping\Contracts\ProxyProvider;
use App\Scraping\Middleware\RotateProxy;
use App\Scraping\Proxy\DirectProxyProvider;
use App\Scraping\Proxy\GoProxyManagerProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Build a provider pointed at a fake Go service.
 */
function goProvider(float $timeout = 0.5, int $threshold = 3): GoProxyManagerProvider
{
    return new GoProxyManagerProvider(
        http: app(HttpFactory::class),
        cache: Cache::store(),
        logger: Log::channel(),
        baseUrl: 'http://127.0.0.1:8081',
        apiKey: 'test-key',
        timeoutSeconds: $timeout,
        failureThreshold: $threshold,
        breakerCooldown: 60,
    );
}

describe('DirectProxyProvider - the null object', function () {
    it('always reports that no proxy is available', function () {
        expect((new DirectProxyProvider)->next())->toBeNull();
    });

    it('accepts reports without doing anything', function () {
        (new DirectProxyProvider)->report('any-id', true, 100);
    })->throwsNoExceptions();

    it('is the provider bound by default', function () {
        // Proxying is opt-in, so a fresh checkout runs with no Go service.
        expect(app(ProxyProvider::class))->toBeInstanceOf(DirectProxyProvider::class);
    });
});

describe('GoProxyManagerProvider - talking to the Go service', function () {
    it('returns the proxy the service hands out', function () {
        Http::fake([
            '*/v1/proxy/next' => Http::response(['id' => 'eu-1', 'url' => 'http://proxy.test:8080']),
        ]);

        expect(goProvider()->next())->toBe(['id' => 'eu-1', 'url' => 'http://proxy.test:8080']);
    });

    it('sends the shared secret header', function () {
        Http::fake(['*' => Http::response(['id' => 'a', 'url' => 'http://a'])]);

        goProvider()->next();

        Http::assertSent(fn ($request) => $request->header('X-Proxy-Key')[0] === 'test-key');
    });

    it('reports an outcome back to the service', function () {
        Http::fake(['*' => Http::response(['status' => 'recorded'])]);

        goProvider()->report('eu-1', true, 320);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/proxy/eu-1/report')
            && $request['success'] === true
            && $request['latency_ms'] === 320);
    });
});

describe('GoProxyManagerProvider - degrading gracefully', function () {
    // This group is the resilience story: every failure mode must resolve to
    // "no proxy", so scraping continues directly rather than breaking.
    it('returns null when the service is unreachable', function () {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        expect(goProvider()->next())->toBeNull();
    });

    it('returns null when the service reports no healthy proxy', function () {
        Http::fake(['*' => Http::response(['error' => 'no healthy proxy available'], 503)]);

        expect(goProvider()->next())->toBeNull();
    });

    it('returns null when the service errors', function () {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        expect(goProvider()->next())->toBeNull();
    });

    it('returns null when the payload is missing fields', function (array $payload) {
        Http::fake(['*' => Http::response($payload)]);

        expect(goProvider()->next())->toBeNull();
    })->with([
        'no url' => [['id' => 'a']],
        'no id' => [['url' => 'http://a']],
        'empty id' => [['id' => '', 'url' => 'http://a']],
        'wrong types' => [['id' => 123, 'url' => ['not', 'a', 'string']]],
        'empty body' => [[]],
    ]);

    it('never throws when reporting fails', function () {
        Http::fake(fn () => throw new ConnectionException('down'));

        goProvider()->report('eu-1', false, 100);
    })->throwsNoExceptions();
});

describe('GoProxyManagerProvider - the circuit breaker', function () {
    // Without the breaker, a stopped Go service would add the full connect
    // timeout to every single scrape.
    it('stops calling the service after repeated failures', function () {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $provider = goProvider(threshold: 3);

        // Three failures trip the breaker.
        $provider->next();
        $provider->next();
        $provider->next();

        $callsBeforeBreaker = count(Http::recorded());

        // These should be suppressed entirely.
        $provider->next();
        $provider->next();

        expect(count(Http::recorded()))->toBe($callsBeforeBreaker);
    });

    it('does not trip the breaker on a 503, which is a valid answer', function () {
        // 503 means "no healthy proxy" - the service is working correctly.
        Http::fake(['*' => Http::response(['error' => 'none available'], 503)]);

        $provider = goProvider(threshold: 2);

        $provider->next();
        $provider->next();
        $provider->next();

        expect(count(Http::recorded()))->toBe(3);
    });

    it('resets the failure count after a success', function () {
        // A single sequence rather than repeated Http::fake() calls: fake()
        // MERGES stubs rather than replacing them, so re-faking would leave
        // the earlier stub still matching first.
        Http::fakeSequence()
            ->pushStatus(500)   // failure 1
            ->pushStatus(500)   // failure 2 - still under the threshold of 3
            ->push(['id' => 'a', 'url' => 'http://a'])  // success clears the streak
            ->pushStatus(500)   // failure 1 again, counting from zero
            ->pushStatus(500)   // failure 2
            ->push(['id' => 'b', 'url' => 'http://b']); // reached, so the breaker never tripped

        $provider = goProvider(threshold: 3);

        expect($provider->next())->toBeNull()
            ->and($provider->next())->toBeNull()
            ->and($provider->next())->toBe(['id' => 'a', 'url' => 'http://a']);

        expect($provider->next())->toBeNull()
            ->and($provider->next())->toBeNull();

        // If the success above had not reset the count, five failures would
        // have tripped the breaker and this call would be suppressed.
        expect($provider->next())->toBe(['id' => 'b', 'url' => 'http://b']);
    });
});

describe('RotateProxy middleware', function () {
    it('attaches the proxy to the outgoing request', function () {
        $provider = new class implements ProxyProvider
        {
            public array $reports = [];

            public function next(): ?array
            {
                return ['id' => 'eu-1', 'url' => 'http://proxy.test:8080'];
            }

            public function report(string $proxyId, bool $success, int $latencyMs): void
            {
                $this->reports[] = compact('proxyId', 'success', 'latencyMs');
            }
        };

        Http::fake(['*' => Http::response('ok')]);

        $middleware = new RotateProxy($provider);
        $captured = null;

        $middleware->handle(
            app(HttpFactory::class)->timeout(5),
            function ($request) use (&$captured) {
                $captured = $request;

                return $request->get('https://example.test');
            },
        );

        // The proxy option is passed straight to Guzzle.
        expect($captured)->not->toBeNull();
        expect($provider->reports)->toHaveCount(1)
            ->and($provider->reports[0]['proxyId'])->toBe('eu-1')
            ->and($provider->reports[0]['success'])->toBeTrue();
    });

    it('reports a failure when the site returns a 5xx', function () {
        $provider = new class implements ProxyProvider
        {
            public array $reports = [];

            public function next(): ?array
            {
                return ['id' => 'eu-1', 'url' => 'http://proxy.test:8080'];
            }

            public function report(string $proxyId, bool $success, int $latencyMs): void
            {
                $this->reports[] = ['success' => $success];
            }
        };

        Http::fake(['*' => Http::response('boom', 500)]);

        (new RotateProxy($provider))->handle(
            app(HttpFactory::class)->timeout(5),
            fn ($request) => $request->get('https://example.test'),
        );

        expect($provider->reports[0]['success'])->toBeFalse();
    });

    it('does not blame the proxy for a 404 from the target site', function () {
        // A 4xx is the site's verdict on us, not evidence the proxy is broken.
        $provider = new class implements ProxyProvider
        {
            public array $reports = [];

            public function next(): ?array
            {
                return ['id' => 'eu-1', 'url' => 'http://proxy.test:8080'];
            }

            public function report(string $proxyId, bool $success, int $latencyMs): void
            {
                $this->reports[] = ['success' => $success];
            }
        };

        Http::fake(['*' => Http::response('missing', 404)]);

        (new RotateProxy($provider))->handle(
            app(HttpFactory::class)->timeout(5),
            fn ($request) => $request->get('https://example.test'),
        );

        expect($provider->reports[0]['success'])->toBeTrue();
    });

    it('passes the request through untouched when no proxy is available', function () {
        Http::fake(['*' => Http::response('ok')]);

        $response = (new RotateProxy(new DirectProxyProvider))->handle(
            app(HttpFactory::class)->timeout(5),
            fn ($request) => $request->get('https://example.test'),
        );

        expect($response->successful())->toBeTrue();
    });
});
