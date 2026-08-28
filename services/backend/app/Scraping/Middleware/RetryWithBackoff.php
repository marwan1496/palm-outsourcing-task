<?php

declare(strict_types=1);

namespace App\Scraping\Middleware;

use App\Scraping\Contracts\ScraperMiddleware;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

/**
 * Retries a failed request a few times, waiting longer after each attempt.
 *
 * Why exponential backoff rather than a fixed delay: when a site is rate
 * limiting us, retrying at a constant interval keeps the pressure on and
 * extends the block. Doubling the wait backs off quickly enough for the site
 * to recover.
 *
 * Only *transient* failures are retried - connection errors and 5xx/429
 * responses. A 404 will be a 404 next time too, so retrying it just wastes a
 * proxy and another second.
 *
 * Because this middleware sits ahead of RotateProxy in the chain, each retry
 * re-enters the rest of the pipeline and therefore picks a *fresh proxy and
 * user-agent*. That is the single most valuable property of the whole design:
 * a retry is not the same request again, it is a different-looking one.
 */
class RetryWithBackoff implements ScraperMiddleware
{
    /**
     * HTTP status codes worth retrying. 429 is rate limiting; 5xx are the
     * site's own failures.
     *
     * @var list<int>
     */
    private const RETRYABLE_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /**
     * @param  int  $maxAttempts  Total attempts, including the first.
     * @param  int  $baseDelayMs  Delay before the second attempt; doubles thereafter.
     * @param  (Closure(int): void)|null  $sleeper  Injected in tests so the suite
     *                                              does not actually sleep.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 500,
        private readonly ?Closure $sleeper = null,
    ) {}

    /**
     * Attempt the request, retrying transient failures with growing delays.
     */
    public function handle(PendingRequest $request, Closure $next): Response
    {
        $attempt = 1;

        while (true) {
            try {
                $response = $next($request);

                if (! $this->shouldRetryStatus($response->status()) || $attempt >= $this->maxAttempts) {
                    return $response;
                }

                $this->logger->warning('Scrape attempt returned a retryable status.', [
                    'attempt' => $attempt,
                    'status' => $response->status(),
                ]);
            } catch (ConnectionException $e) {
                // Out of attempts: let the caller deal with it.
                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }

                $this->logger->warning('Scrape attempt failed to connect.', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->sleep($this->delayForAttempt($attempt));
            $attempt++;
        }
    }

    /**
     * Whether a status code is worth another attempt.
     */
    private function shouldRetryStatus(int $status): bool
    {
        return in_array($status, self::RETRYABLE_STATUSES, true);
    }

    /**
     * Delay before the next attempt, doubling each time: 500ms, 1s, 2s, …
     */
    public function delayForAttempt(int $attempt): int
    {
        return $this->baseDelayMs * (2 ** ($attempt - 1));
    }

    /**
     * Pause between attempts.
     */
    private function sleep(int $milliseconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }
}
