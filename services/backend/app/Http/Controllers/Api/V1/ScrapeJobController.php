<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ScrapeJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ScrapeJobResource;
use App\Models\ScrapeJob;
use App\Scraping\ScrapeBatchDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports on scrape jobs.
 *
 * Deliberately not cached, unlike the products endpoint. Job status is the one
 * thing in this API that changes second to second, and a stale jobs list is
 * worse than a slow one: it looks like the queue has stalled.
 */
class ScrapeJobController extends Controller
{
    public function __construct(
        private readonly ScrapeBatchDispatcher $dispatcher,
    ) {}

    /**
     * List jobs, newest first.
     *
     * `unfinished` in the response is what drives the frontend's polling
     * speed: while anything is still pending or running it polls every few
     * seconds, then backs off once everything has settled. Sending the count
     * saves the client from working it out from the current page, which would
     * be wrong as soon as there's more than one page.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:'.implode(',', ScrapeJobStatus::values())],
            'batch_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $jobs = ScrapeJob::query()
            // Eager loaded so rendering N jobs stays two queries rather than N+1.
            ->with('product')
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->when(isset($validated['batch_id']), fn ($q) => $q->where('batch_id', $validated['batch_id']))
            ->latest('id')
            ->paginate(perPage: (int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => ScrapeJobResource::collection($jobs->items())->resolve(),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
                'unfinished' => ScrapeJob::query()->unfinished()->count(),
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * One job. Route-model binding gives a 404 for an unknown id.
     */
    public function show(ScrapeJob $scrapeJob): JsonResponse
    {
        $scrapeJob->load('product');

        return response()->json(
            ['data' => (new ScrapeJobResource($scrapeJob))->resolve()],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Put a failed job back on the queue.
     *
     * Only failed jobs can be retried: a completed one would just re-scrape a
     * page we already have, and one still in flight will finish on its own.
     * Returns 409 rather than 422 because the request is well formed, it's the
     * job's current state that makes it impossible.
     */
    public function retry(ScrapeJob $scrapeJob): JsonResponse
    {
        if (! $scrapeJob->status->isRetryable()) {
            return response()->json([
                'message' => sprintf(
                    'Only failed jobs can be retried. This one is %s.',
                    $scrapeJob->status->value,
                ),
            ], 409);
        }

        $this->dispatcher->retry($scrapeJob);

        return response()->json([
            'message' => 'The job has been queued again.',
            'data' => (new ScrapeJobResource($scrapeJob->fresh() ?? $scrapeJob))->resolve(),
        ], 202, ['Cache-Control' => 'no-store']);
    }
}
