<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ScrapeProductRequest;
use App\Jobs\ScrapeProductJob;
use Illuminate\Http\JsonResponse;

/**
 * Accepts a URL to scrape.
 *
 * The work is queued rather than done inline. Scraping takes seconds - a
 * proxy handshake, a slow page, up to three retries with backoff - and holding
 * an HTTP connection open for that is a bad trade: the client times out, the
 * PHP worker is blocked, and a retry means starting over.
 *
 * So this returns 202 Accepted immediately and the queue does the work. The
 * frontend polls /products every 30 seconds anyway, so a newly scraped product
 * appears on its own without the client tracking a job id.
 */
class ScrapeController extends Controller
{
    /**
     * Queue a scrape.
     *
     * By the time this method runs, ScrapeProductRequest has already confirmed
     * the URL is well-formed, passes the SSRF guard, and has a parser - so a
     * queued job is never certain-to-fail work.
     */
    public function store(ScrapeProductRequest $request): JsonResponse
    {
        $url = (string) $request->validated('url');

        ScrapeProductJob::dispatch($url);

        return response()->json([
            'message' => 'The product page has been queued for scraping.',
            'url' => $url,
        ], 202);
    }
}
