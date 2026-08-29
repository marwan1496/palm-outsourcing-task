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
     * Queue URLs for scraping
     *
     * Send either a single `url`, or a `urls` array of up to 10:
     *
     * ```json
     * { "url": "https://www.jumia.com.eg/some-product.html" }
     * ```
     * ```json
     * { "urls": ["https://www.jumia.com.eg/a.html", "https://www.amazon.eg/dp/B01LR8CIRC"] }
     * ```
     *
     * A batch is allowed to partly succeed. Paste ten URLs with one typo in them and the other nine
     * still run, so the 202 carries an `accepted` list of queued jobs alongside a `rejected` list
     * with a reason for each. Only a batch where nothing at all was accepted is a 422, because then
     * there is no work to report progress on.
     *
     * Every URL has to be HTTPS, from a supported storefront, and pass the SSRF guard. Try
     * `https://169.254.169.254/latest/meta-data/` to watch that last one refuse a request before it
     * is made.
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
