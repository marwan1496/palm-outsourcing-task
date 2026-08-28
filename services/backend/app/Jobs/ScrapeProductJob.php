<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Scraping\Exceptions\ScrapeFailedException;
use App\Scraping\Exceptions\UnsafeUrlException;
use App\Scraping\ScraperManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scrapes one product page in the background.
 *
 * Note how thin this is: it validates nothing and parses nothing, it just
 * calls ScraperManager. All the logic lives in the scraping module, so the
 * queued job and the artisan command behave identically - there is no second
 * code path that could drift.
 *
 * Retry strategy: the job retries transient failures, but the pipeline already
 * retries individual HTTP requests. Job-level retries are therefore few and
 * widely spaced, to cover a proxy pool that is briefly exhausted rather than a
 * single flaky request.
 */
class ScrapeProductJob implements ShouldQueue
{
    use Queueable;

    /**
     * How many times to attempt the job.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before each retry: 1 minute, then 5.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    /**
     * Give up on a single attempt after two minutes.
     */
    public int $timeout = 120;

    public function __construct(
        public readonly string $url,
    ) {}

    /**
     * Run the scrape.
     */
    public function handle(ScraperManager $scraper): void
    {
        try {
            $product = $scraper->scrape($this->url);

            Log::info('Scrape job stored a product.', [
                'url' => $this->url,
                'product_id' => $product->id,
            ]);
        } catch (UnsafeUrlException $e) {
            // The URL will never be acceptable, so retrying is pointless.
            // fail() stops the job immediately rather than burning two retries.
            Log::warning('Scrape job refused an unsafe URL.', [
                'url' => $this->url,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);
        } catch (ScrapeFailedException $e) {
            // Might succeed later - a block can lift, a proxy can recover -
            // so this one is allowed to retry.
            Log::warning('Scrape job failed.', [
                'url' => $this->url,
                'attempt' => $this->attempts(),
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Called once every attempt has been exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Scrape job exhausted all attempts.', [
            'url' => $this->url,
            'reason' => $exception?->getMessage(),
        ]);
    }
}
