<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Product;
use App\Support\ProductCache;

/**
 * Keeps the cached product listing honest.
 *
 * Any write to a product invalidates every cached listing, by bumping the
 * cache version. Without this, a freshly scraped product would be invisible
 * for up to two minutes - and during a demo that reads as "the scraper is
 * broken".
 *
 * saved() covers both creation and update, so the common case - the scraper
 * upserting a product - is handled by a single hook.
 */
class ProductObserver
{
    public function __construct(
        private readonly ProductCache $cache,
    ) {}

    /**
     * Handle creation and update alike.
     */
    public function saved(Product $product): void
    {
        $this->cache->flush();
    }

    /**
     * Handle deletion.
     */
    public function deleted(Product $product): void
    {
        $this->cache->flush();
    }
}
