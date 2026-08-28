<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches the product listing, and invalidates it correctly on any write.
 *
 * THE INVALIDATION PROBLEM
 *
 * The obvious approach is cache tags - Cache::tags('products')->flush(). But
 * tags are only supported by Redis and Memcached, and this project runs on the
 * database cache driver so it works with nothing installed but MySQL. Calling
 * tags() on the database store throws.
 *
 * So invalidation uses a *version key* instead. Every cache key embeds a
 * version number, and a write bumps that number:
 *
 *     products:v1:<hash>   <- everything reads this
 *     ...a product is saved, version becomes 2...
 *     products:v2:<hash>   <- every read now misses and rebuilds
 *
 * The old v1 entries are orphaned and expire on their own. This works on every
 * driver, needs no tag support, and means switching to Redis later is a
 * one-line .env change with no code to rewrite.
 *
 * FLEXIBLE CACHING
 *
 * Reads use Cache::flexible([$fresh, $stale]), Laravel's stale-while-revalidate
 * helper. Within $fresh seconds the cached value is returned directly. Between
 * $fresh and $stale it is still returned immediately, but a refresh is queued
 * to run after the response is sent. A user therefore never waits for a cache
 * rebuild - the slowest case is served stale data and a background refresh.
 */
class ProductCache
{
    /**
     * Key holding the current version number.
     */
    private const VERSION_KEY = 'products:version';

    /**
     * Remember a value under a versioned key.
     *
     * @template TValue
     *
     * @param  string  $key  A key unique to the query being cached.
     * @param  Closure(): TValue  $callback  Builds the value on a miss.
     * @return TValue
     */
    public function remember(string $key, Closure $callback): mixed
    {
        if (! config('scraping.cache.enabled', true)) {
            return $callback();
        }

        return Cache::flexible(
            $this->versionedKey($key),
            [
                config('scraping.cache.fresh_seconds', 30),
                config('scraping.cache.stale_seconds', 120),
            ],
            $callback,
        );
    }

    /**
     * Invalidate every cached listing by moving to a new version.
     *
     * Called by ProductObserver whenever a product is written or deleted.
     */
    public function flush(): void
    {
        Cache::increment(self::VERSION_KEY);
    }

    /**
     * The current cache version, starting at 1.
     */
    public function version(): int
    {
        $version = Cache::get(self::VERSION_KEY);

        if ($version === null) {
            // forever() rather than a TTL: if this key expired on its own,
            // the version would reset and stale entries from an earlier
            // generation could be served again.
            Cache::forever(self::VERSION_KEY, 1);

            return 1;
        }

        return (int) $version;
    }

    /**
     * Build the full cache key for a query.
     */
    public function versionedKey(string $key): string
    {
        return sprintf('products:v%d:%s', $this->version(), $key);
    }

    /**
     * A stable, short key for a set of query parameters.
     *
     * Sorted so that ?page=2&per_page=10 and ?per_page=10&page=2 - the same
     * query written two ways - share one cache entry.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function keyForQuery(array $parameters): string
    {
        ksort($parameters);

        return md5((string) json_encode($parameters));
    }
}
