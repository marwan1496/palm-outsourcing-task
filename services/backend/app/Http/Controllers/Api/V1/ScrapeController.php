<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ScrapeProductRequest;
use App\Http\Resources\V1\ScrapeJobResource;
use App\Scraping\ScrapeBatchDispatcher;
use Illuminate\Http\JsonResponse;

/**
 * Accepts URLs to scrape.
 *
 * The work is queued rather than done inline. Scraping takes seconds — a proxy
 * handshake, a slow page, up to three retries with backoff — and holding an
 * HTTP connection open for that times the client out and blocks a PHP worker.
 * So this returns 202 immediately and the queue does the work, while the jobs
 * screen polls for progress.
 */
class ScrapeController extends Controller
{
    public function __construct(
        private readonly ScrapeBatchDispatcher $dispatcher,
    ) {}

    /**
     * Queue one or more URLs.
     *
     * Returns 202 with both an accepted and a rejected list. A batch is allowed
     * to partly succeed: if someone pastes ten URLs and one has a typo, the
     * other nine still run. Only a batch where nothing at all was accepted is
     * a 422, because then there's no work to report progress on.
     */
    public function store(ScrapeProductRequest $request): JsonResponse
    {
        $result = $this->dispatcher->dispatch($request->urls());

        if ($result->isCompleteFailure()) {
            return response()->json([
                'message' => 'None of the submitted URLs could be scraped.',
                'errors' => ['urls' => array_column($result->rejected, 'reason')],
                'rejected' => $result->rejected,
            ], 422);
        }

        return response()->json([
            'message' => sprintf(
                '%d URL(s) queued for scraping%s.',
                $result->acceptedCount(),
                $result->rejectedCount() > 0 ? sprintf(', %d rejected', $result->rejectedCount()) : '',
            ),
            'batch_id' => $result->batchId,
            'accepted' => ScrapeJobResource::collection($result->accepted)->resolve(),
            'rejected' => $result->rejected,
        ], 202);
    }
}
