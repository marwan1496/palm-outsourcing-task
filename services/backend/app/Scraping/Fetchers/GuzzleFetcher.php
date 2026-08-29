<?php

declare(strict_types=1);

namespace App\Scraping\Fetchers;

use App\Scraping\Contracts\PageFetcher;
use App\Scraping\DTO\FetchedPage;
use App\Scraping\Pipeline\ScraperPipeline;

/**
 * The normal way pages are fetched: Guzzle, through the middleware pipeline.
 *
 * This is a thin adapter. All the interesting work — user-agent rotation,
 * proxy rotation, retry with backoff — already happens inside ScraperPipeline;
 * this just presents the result as a FetchedPage so the browser fallback can
 * be swapped in behind the same interface.
 */
class GuzzleFetcher implements PageFetcher
{
    public function __construct(
        private readonly ScraperPipeline $pipeline,
    ) {}

    public function fetch(string $url): FetchedPage
    {
        $response = $this->pipeline->fetch($url);

        return new FetchedPage(
            status: $response->status(),
            html: $response->body(),
            fetchedBy: $this->name(),
        );
    }

    public function name(): string
    {
        return 'guzzle';
    }
}
