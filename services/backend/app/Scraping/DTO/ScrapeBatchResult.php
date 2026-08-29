<?php

declare(strict_types=1);

namespace App\Scraping\DTO;

use App\Models\ScrapeJob;

/**
 * What happened when a batch of URLs was submitted.
 *
 * A batch is deliberately allowed to partly succeed. If someone pastes ten
 * URLs and one has a typo, rejecting all ten would be obnoxious, so each URL
 * is judged on its own and the caller gets both lists back.
 */
final readonly class ScrapeBatchResult
{
    /**
     * @param  string  $batchId  Groups these jobs so the UI can show them together.
     * @param  list<ScrapeJob>  $accepted  Rows created and queued.
     * @param  list<array{url: string, reason: string}>  $rejected  URLs turned away, and why.
     */
    public function __construct(
        public string $batchId,
        public array $accepted,
        public array $rejected,
    ) {}

    /**
     * Nothing was accepted. The controller turns this into a 422 rather than a
     * 202, because there is no work to report progress on.
     */
    public function isCompleteFailure(): bool
    {
        return $this->accepted === [];
    }

    public function acceptedCount(): int
    {
        return count($this->accepted);
    }

    public function rejectedCount(): int
    {
        return count($this->rejected);
    }
}
