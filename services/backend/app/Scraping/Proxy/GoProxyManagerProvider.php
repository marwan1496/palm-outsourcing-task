<?php

declare(strict_types=1);

namespace App\Scraping\Proxy;

use App\Scraping\Contracts\ProxyProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Asks the Go proxy-manager microservice which proxy to use next.
 *
 * The contract with the Go service is deliberately forgiving. Losing proxy
 * rotation must never stop us scraping, so every failure mode here - timeout,
 * connection refused, 503, malformed body - resolves to "no proxy", and the
 * request goes out directly instead.
 *
 * A circuit breaker sits in front of the calls. Without it, a stopped Go
 * service would add the full connect timeout to *every single scrape*. After
 * a few consecutive failures the breaker opens and calls are skipped entirely
 * for a cooldown period, so a dead dependency costs nothing rather than
 * slowing the system down.
 */
class GoProxyManagerProvider implements ProxyProvider
{
    /**
     * Cache key holding the consecutive failure count.
     */
    private const FAILURE_KEY = 'proxy-manager:failures';

    /**
     * Cache key that, while present, means the breaker is open.
     */
    private const BREAKER_KEY = 'proxy-manager:circuit-open';

    /**
     * @param  string  $baseUrl  Root URL of the Go service, e.g. http://127.0.0.1:8081
     * @param  string  $apiKey  Shared secret sent as X-Proxy-Key.
     * @param  float  $timeoutSeconds  Kept very short: this call sits on the hot
     *                                 path of every scrape, and going without a
     *                                 proxy beats waiting.
     * @param  int  $failureThreshold  Consecutive failures before the breaker opens.
     * @param  int  $breakerCooldown  Seconds the breaker stays open.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly float $timeoutSeconds = 0.5,
        private readonly int $failureThreshold = 3,
        private readonly int $breakerCooldown = 60,
    ) {}

    /**
     * Fetch the next proxy, or null if none is available for any reason.
     *
     * @return array{id: string, url: string}|null
     */
    public function next(): ?array
    {
        if ($this->circuitIsOpen()) {
            return null;
        }

        try {
            $response = $this->request()->get('/v1/proxy/next');
        } catch (Throwable $e) {
            $this->recordFailure('The proxy manager could not be reached.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // 503 is the documented "no healthy proxy" answer. The service is
        // working correctly, so it must not count towards the breaker.
        if ($response->serverError() && $response->status() !== 503) {
            $this->recordFailure('The proxy manager returned a server error.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $this->recordSuccess();

        if (! $response->successful()) {
            return null;
        }

        $id = $response->json('id');
        $url = $response->json('url');

        if (! is_string($id) || ! is_string($url) || $id === '' || $url === '') {
            $this->logger->warning('The proxy manager returned an unusable payload.', [
                'body' => $response->body(),
            ]);

            return null;
        }

        return ['id' => $id, 'url' => $url];
    }

    /**
     * Tell the Go service how a proxy performed.
     *
     * Failures are logged and swallowed: this is telemetry, and it must never
     * turn a successful scrape into a failed one.
     */
    public function report(string $proxyId, bool $success, int $latencyMs): void
    {
        if ($this->circuitIsOpen()) {
            return;
        }

        try {
            $this->request()->post("/v1/proxy/{$proxyId}/report", [
                'success' => $success,
                'latency_ms' => $latencyMs,
            ]);
        } catch (Throwable $e) {
            $this->logger->debug('Could not report a proxy outcome.', [
                'proxy' => $proxyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fetch pool statistics, used by the health/status command.
     *
     * @return array<string, mixed>|null
     */
    public function stats(): ?array
    {
        try {
            $response = $this->request()->get('/v1/proxies');

            return $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A pre-configured HTTP client for the Go service.
     */
    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->timeoutSeconds)
            ->withHeaders(['X-Proxy-Key' => $this->apiKey])
            ->acceptJson();
    }

    /**
     * Whether the breaker is currently open (calls suppressed).
     */
    private function circuitIsOpen(): bool
    {
        return (bool) $this->cache->get(self::BREAKER_KEY, false);
    }

    /**
     * Count a failure and open the breaker once the threshold is reached.
     *
     * @param  array<string, mixed>  $context
     */
    private function recordFailure(string $message, array $context = []): void
    {
        $failures = ((int) $this->cache->get(self::FAILURE_KEY, 0)) + 1;
        $this->cache->set(self::FAILURE_KEY, $failures, $this->breakerCooldown);

        if ($failures >= $this->failureThreshold) {
            $this->cache->set(self::BREAKER_KEY, true, $this->breakerCooldown);

            $this->logger->warning('Proxy manager circuit breaker opened; scraping directly.', [
                'failures' => $failures,
                'cooldown_seconds' => $this->breakerCooldown,
            ]);

            return;
        }

        $this->logger->debug($message, $context);
    }

    /**
     * Clear the failure count after a call succeeds.
     */
    private function recordSuccess(): void
    {
        $this->cache->delete(self::FAILURE_KEY);
    }
}
