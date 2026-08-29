<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a scrape job has got to.
 *
 * Laravel's queue deletes a job row once it succeeds, so a finished job leaves
 * no trace behind. That's fine for a fire-and-forget queue, but the jobs screen
 * needs to show what happened after the fact, so we track status ourselves.
 */
enum ScrapeJobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Label for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether this job has finished, one way or the other.
     *
     * The frontend uses this to decide how fast to poll: quickly while
     * anything is still moving, slowly once everything has settled.
     */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    /**
     * Only failed jobs can be retried. Re-running a completed job would just
     * re-scrape a page we already have, and one still in flight will finish
     * on its own.
     */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Every value, for validation rules.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
