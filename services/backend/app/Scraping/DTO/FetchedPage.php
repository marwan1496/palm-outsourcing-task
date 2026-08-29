<?php

declare(strict_types=1);

namespace App\Scraping\DTO;

/**
 * A page that came back from somewhere.
 *
 * Guzzle and a real browser return very different objects, so this is the
 * common shape they both reduce to. Everything downstream — block detection,
 * parsing, persistence — works on this and doesn't care which one produced it.
 */
final readonly class FetchedPage
{
    /**
     * @param  int|null  $status  HTTP status. Null from the browser, which reports
     *                            a rendered page rather than a single response.
     * @param  string  $html  The page body.
     * @param  string  $fetchedBy  "guzzle" or "browser", for logging and so the
     *                             jobs screen can say how a page was obtained.
     */
    public function __construct(
        public ?int $status,
        public string $html,
        public string $fetchedBy,
    ) {}

    /**
     * Whether the status looks like a normal successful response.
     *
     * A null status counts as successful: the browser only returns a page at
     * all if it managed to load one.
     */
    public function isSuccessful(): bool
    {
        return $this->status === null || ($this->status >= 200 && $this->status < 300);
    }
}
