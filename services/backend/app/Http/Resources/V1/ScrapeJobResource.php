<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\ScrapeJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a ScrapeJob for the API.
 *
 * Like ProductResource, the model is held in a typed property rather than
 * reached through JsonResource's magic __get, so static analysis can actually
 * check these accesses.
 */
class ScrapeJobResource extends JsonResource
{
    private readonly ScrapeJob $job;

    public function __construct(ScrapeJob $resource)
    {
        parent::__construct($resource);

        $this->job = $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->job->id,
            'batch_id' => $this->job->batch_id,
            'url' => $this->job->url,

            'status' => $this->job->status->value,
            'status_label' => $this->job->status->label(),
            'is_terminal' => $this->job->status->isTerminal(),
            'is_retryable' => $this->job->status->isRetryable(),

            'error' => $this->job->error,
            'attempts' => $this->job->attempts,
            'duration_ms' => $this->job->durationMs(),

            // Included so the UI can link straight to what the job produced,
            // without a second request. Only loaded when the relation is
            // already in memory, so listing jobs stays one query.
            'product' => $this->whenLoaded(
                'product',
                fn () => $this->job->product === null
                    ? null
                    : new ProductResource($this->job->product),
            ),

            'started_at' => $this->job->started_at?->toIso8601String(),
            'finished_at' => $this->job->finished_at?->toIso8601String(),
            'created_at' => $this->job->created_at->toIso8601String(),
        ];
    }
}
