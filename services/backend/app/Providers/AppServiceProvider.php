<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ProductSource;
use App\Models\Product;
use App\Observers\ProductObserver;
use App\Scraping\Contracts\ProxyProvider;
use App\Scraping\Middleware\RetryWithBackoff;
use App\Scraping\Middleware\RotateProxy;
use App\Scraping\Middleware\RotateUserAgent;
use App\Scraping\Pipeline\ItemPipeline;
use App\Scraping\Pipeline\ScraperPipeline;
use App\Scraping\Proxy\DirectProxyProvider;
use App\Scraping\Proxy\GoProxyManagerProvider;
use App\Scraping\ScraperManager;
use App\Scraping\Support\UrlGuard;
use App\Scraping\Support\UserAgentPool;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the scraping module together.
 *
 * All of the assembly lives here, in plain readable bindings, rather than
 * being spread across auto-discovery or a dedicated provider per concern.
 * One file answers "how is the scraper put together?".
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register container bindings.
     */
    public function register(): void
    {
        $this->registerProxyProvider();
        $this->registerUrlGuard();
        $this->registerPipeline();
        $this->registerScraperManager();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Keeps the cached product listing correct: any write to a product
        // invalidates it. See ProductObserver.
        Product::observe(ProductObserver::class);
    }

    /**
     * Bind the proxy source.
     *
     * Disabled by default, so a fresh checkout runs without the Go service
     * and without any proxies - requests simply go out directly.
     */
    private function registerProxyProvider(): void
    {
        $this->app->singleton(ProxyProvider::class, function (): ProxyProvider {
            $config = config('scraping.proxy_manager');

            if (! $config['enabled']) {
                return new DirectProxyProvider;
            }

            return new GoProxyManagerProvider(
                http: $this->app->make(HttpFactory::class),
                cache: Cache::store(),
                logger: Log::channel(),
                baseUrl: $config['url'],
                apiKey: $config['key'],
                timeoutSeconds: $config['timeout'],
                failureThreshold: $config['failure_threshold'],
                breakerCooldown: $config['breaker_cooldown'],
            );
        });
    }

    /**
     * Bind the SSRF guard.
     *
     * When no explicit allowlist is configured it defaults to the hosts of
     * the storefronts we have parsers for - there is no reason to allow
     * fetching anything we cannot parse.
     */
    private function registerUrlGuard(): void
    {
        $this->app->singleton(UrlGuard::class, function (): UrlGuard {
            $config = config('scraping.url_guard');

            return new UrlGuard(
                allowedHosts: $config['allowed_hosts'] ?? ProductSource::allHostPatterns(),
                allowedSchemes: $config['allowed_schemes'],
                verifyDns: $config['verify_dns'],
            );
        });
    }

    /**
     * Bind the fetch pipeline, building its middleware chain from config.
     */
    private function registerPipeline(): void
    {
        // The user-agent pool is a singleton so its round-robin cursor is
        // shared: two scrapes in one process get different user-agents.
        $this->app->singleton(
            UserAgentPool::class,
            fn (): UserAgentPool => new UserAgentPool(config('scraping.user_agents', [])),
        );

        $this->app->bind(RotateUserAgent::class, fn (): RotateUserAgent => new RotateUserAgent(
            $this->app->make(UserAgentPool::class),
        ));

        $this->app->bind(RotateProxy::class, fn (): RotateProxy => new RotateProxy(
            $this->app->make(ProxyProvider::class),
        ));

        $this->app->bind(RetryWithBackoff::class, fn (): RetryWithBackoff => new RetryWithBackoff(
            logger: Log::channel(),
            maxAttempts: config('scraping.retry.max_attempts'),
            baseDelayMs: config('scraping.retry.base_delay_ms'),
        ));

        $this->app->bind(ScraperPipeline::class, function (): ScraperPipeline {
            // Resolved through the container so each middleware gets its own
            // dependencies injected.
            $middleware = array_map(
                fn (string $class): object => $this->app->make($class),
                config('scraping.middleware', []),
            );

            return new ScraperPipeline(
                http: $this->app->make(HttpFactory::class),
                middleware: $middleware,
                timeoutSeconds: config('scraping.http.timeout'),
                delayMs: config('scraping.http.delay_ms'),
            );
        });
    }

    /**
     * Bind the module's entry point.
     */
    private function registerScraperManager(): void
    {
        $this->app->bind(ScraperManager::class, function (): ScraperManager {
            $parsers = array_map(
                fn (string $class): object => $this->app->make($class),
                config('scraping.parsers', []),
            );

            return new ScraperManager(
                pipeline: $this->app->make(ScraperPipeline::class),
                items: $this->app->make(ItemPipeline::class),
                urlGuard: $this->app->make(UrlGuard::class),
                parsers: $parsers,
                logger: Log::channel(),
            );
        });
    }
}
