<?php

declare(strict_types=1);

namespace App\Scraping\Proxy;

use App\Scraping\Contracts\ProxyProvider;

/**
 * A ProxyProvider that never provides a proxy.
 *
 * This is the Null Object pattern, and it is what makes the "Go service is
 * down" path free of special cases. RotateProxy already handles "no proxy
 * available" as an ordinary outcome, so swapping this in makes every request
 * go out directly with no branching anywhere else in the codebase.
 *
 * It is used in two situations:
 *   - PROXY_MANAGER_ENABLED=false, the default for local development.
 *   - As the fallback inside GoProxyManagerProvider when the Go service is
 *     unreachable.
 */
class DirectProxyProvider implements ProxyProvider
{
    /**
     * Always null: connect directly.
     */
    public function next(): ?array
    {
        return null;
    }

    /**
     * Nothing to report to - there is no proxy and no pool.
     */
    public function report(string $proxyId, bool $success, int $latencyMs): void
    {
        // Intentionally empty.
    }
}
