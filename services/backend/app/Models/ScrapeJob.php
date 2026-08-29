<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScrapeJobStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One URL submitted for scraping, and how it turned out.
 *
 * The queue itself can't answer "did that URL work?", because a successful job
 * deletes its own row. This model is what the jobs screen reads.
 *
 * @property int $id
 * @property string $batch_id
 * @property string $url
 * @property ScrapeJobStatus $status
 * @property int|null $product_id
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Product|null $product
 */
#[Fillable(['batch_id', 'url', 'status', 'product_id', 'error', 'attempts', 'started_at', 'finished_at'])]
class ScrapeJob extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScrapeJobStatus::class,
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The product this job produced, if it got that far.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Mark the job as started. Called by the queue worker.
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => ScrapeJobStatus::Running,
            'started_at' => now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * Mark the job finished and link the product it produced.
     */
    public function markCompleted(Product $product): void
    {
        $this->update([
            'status' => ScrapeJobStatus::Completed,
            'product_id' => $product->id,
            'error' => null,
            'finished_at' => now(),
        ]);
    }

    /**
     * Mark the job failed.
     *
     * The message goes straight into the UI, so it's truncated to something
     * readable rather than storing an unbounded exception string.
     */
    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => ScrapeJobStatus::Failed,
            'error' => mb_substr($reason, 0, 500),
            'finished_at' => now(),
        ]);
    }

    /**
     * Put a failed job back in the queue.
     */
    public function markQueuedAgain(): void
    {
        $this->update([
            'status' => ScrapeJobStatus::Pending,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    /**
     * How long the scrape took, in milliseconds, once it has finished.
     */
    public function durationMs(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInMilliseconds($this->finished_at);
    }

    /**
     * Jobs that haven't finished yet.
     *
     * The API exposes this as a count so the frontend knows whether to keep
     * polling quickly.
     *
     * @param  Builder<ScrapeJob>  $query
     */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', [ScrapeJobStatus::Pending, ScrapeJobStatus::Running]);
    }
}
