<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ScrapeJob;
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
 * The job stays thin: it validates nothing and parses nothing, it just calls
 * ScraperManager and records what happened on the ScrapeJob row so the jobs
 * screen can show it. All the actual logic lives in the scraping module, which
 * means the queued path and the artisan command behave identically.
 *
 * Retry strategy: the pipeline already retries individual HTTP requests, so
 * job-level retries are few and widely spaced. They exist for a proxy pool
 * that's briefly exhausted, not for a single flaky request.
 */
class ScrapeProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Wait a minute, then five, before retrying.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    /**
     * @param  string  $url  The page to scrape.
     * @param  int|null  $scrapeJobId  The tracking row to update. Null when the
     *                                 job is dispatched from the artisan
     *                                 command, which reports to the console
     *                                 instead and has nothing to track.
     */
    public function __construct(
        public readonly string $url,
        public readonly ?int $scrapeJobId = null,
    ) {}

    /**
     * Run the scrape and record the outcome.
     */
    public function handle(ScraperManager $scraper): void
    {
        $record = $this->record();
        $record?->markRunning();

        try {
            $product = $scraper->scrape($this->url);

            $record?->markCompleted($product);

            Log::info('Scrape job stored a product.', [
                'url' => $this->url,
                'product_id' => $product->id,
            ]);
        } catch (UnsafeUrlException $e) {
            // The URL will never be acceptable, so retrying is pointless.
            // fail() stops the job immediately instead of burning two retries.
            $record?->markFailed($e->getMessage());

            Log::warning('Scrape job refused an unsafe URL.', [
                'url' => $this->url,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);
        } catch (ScrapeFailedException $e) {
            // Might succeed later: a block can lift, a proxy can recover. Let
            // the queue retry, and only record failure once attempts run out
            // (see failed() below) so the UI doesn't flash "failed" between
            // attempts that are still coming.
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
        $this->record()?->markFailed(
            $exception?->getMessage() ?? 'The job failed without reporting a reason.',
        );

        Log::error('Scrape job exhausted all attempts.', [
            'url' => $this->url,
            'reason' => $exception?->getMessage(),
        ]);
    }

    /**
     * The tracking row, if this job has one.
     *
     * Looked up fresh each time rather than serialised into the job, so the
     * worker always sees current state even if the row changed after dispatch.
     */
    private function record(): ?ScrapeJob
    {
        return $this->scrapeJobId === null
            ? null
            : ScrapeJob::find($this->scrapeJobId);
    }
}
