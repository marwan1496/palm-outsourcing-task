<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Enums\ScrapeJobStatus;
use App\Jobs\ScrapeProductJob;
use App\Models\ScrapeJob;
use App\Scraping\DTO\ScrapeBatchResult;
use App\Scraping\Exceptions\UnsafeUrlException;
use App\Scraping\Support\UrlGuard;
use Illuminate\Support\Str;

/**
 * Takes a list of URLs, decides which are worth queueing, and queues them.
 *
 * Every URL is checked before anything is dispatched. Queueing work that is
 * certain to fail wastes a worker and hides the real problem from whoever
 * submitted it: they'd see "failed" a minute later instead of "that URL isn't
 * a storefront we support" immediately.
 *
 * Checks run cheapest first, and each URL is judged independently so one bad
 * entry doesn't sink the rest of the batch.
 */
class ScrapeBatchDispatcher
{
    /**
     * Most URLs accepted in one submission.
     *
     * Each one is a real outbound fetch through a proxy, so this is a politeness
     * limit as much as a technical one.
     */
    public const MAX_URLS = 10;

    public function __construct(
        private readonly ScraperManager $scraper,
        private readonly UrlGuard $urlGuard,
    ) {}

    /**
     * Queue whichever of these URLs we can actually scrape.
     *
     * @param  list<string>  $urls
     */
    public function dispatch(array $urls): ScrapeBatchResult
    {
        $batchId = (string) Str::uuid();

        $accepted = [];
        $rejected = [];
        $seen = [];

        foreach ($urls as $url) {
            $url = trim($url);

            if ($url === '') {
                continue;
            }

            // Someone pasting a list will duplicate a line sooner or later.
            // Silently scraping the same page twice in one batch is just waste.
            if (isset($seen[$url])) {
                $rejected[] = ['url' => $url, 'reason' => 'Duplicate URL in this batch.'];

                continue;
            }
            $seen[$url] = true;

            $reason = $this->rejectionReason($url);

            if ($reason !== null) {
                $rejected[] = ['url' => $url, 'reason' => $reason];

                continue;
            }

            $accepted[] = $this->queue($url, $batchId);
        }

        return new ScrapeBatchResult($batchId, $accepted, $rejected);
    }

    /**
     * Why this URL can't be scraped, or null if it can.
     *
     * The message is shown directly to whoever submitted it, so it names the
     * problem in plain language. UrlGuard's own messages are already written
     * for that, and never leak which internal addresses exist.
     */
    public function rejectionReason(string $url): ?string
    {
        if (mb_strlen($url) > 512) {
            return 'The URL is too long (maximum 512 characters).';
        }

        try {
            $this->urlGuard->assertSafe($url);
        } catch (UnsafeUrlException $e) {
            return $e->getMessage();
        }

        if ($this->scraper->parserFor($url) === null) {
            return 'No parser is available for this storefront. Supported sites: Jumia, Amazon.';
        }

        return null;
    }

    /**
     * Create the tracking row and put the work on the queue.
     */
    private function queue(string $url, string $batchId): ScrapeJob
    {
        $job = ScrapeJob::create([
            'batch_id' => $batchId,
            'url' => $url,
            'status' => ScrapeJobStatus::Pending,
        ]);

        ScrapeProductJob::dispatch($url, $job->id);

        return $job;
    }

    /**
     * Put a failed job back on the queue, reusing its existing row so the
     * history stays in one place rather than creating a duplicate.
     */
    public function retry(ScrapeJob $job): void
    {
        $job->markQueuedAgain();

        ScrapeProductJob::dispatch($job->url, $job->id);
    }
}
