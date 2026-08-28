<?php

declare(strict_types=1);

namespace App\Scraping\Support;

/**
 * Supplies a different browser User-Agent string for each request.
 *
 * Why it matters: a default client sends something like "GuzzleHttp/8.1",
 * which storefronts block on sight. Rotating through real browser
 * user-agents makes the traffic look like ordinary visitors rather than one
 * relentless script.
 *
 * Selection is round-robin rather than random, deliberately: random picks
 * repeat by chance, and repetition is exactly the pattern that gets noticed.
 * Round-robin guarantees the pool is used evenly and makes tests predictable.
 */
class UserAgentPool
{
    /**
     * Position of the next user-agent to hand out.
     */
    private int $cursor = 0;

    /**
     * @param  list<string>  $userAgents  Falls back to a built-in set when empty.
     */
    public function __construct(
        private readonly array $userAgents = [],
    ) {}

    /**
     * The next user-agent in the rotation.
     */
    public function next(): string
    {
        $agents = $this->all();

        $agent = $agents[$this->cursor % count($agents)];
        $this->cursor++;

        return $agent;
    }

    /**
     * Every user-agent in the pool.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->userAgents !== [] ? $this->userAgents : self::defaults();
    }

    /**
     * How many user-agents are in rotation.
     */
    public function count(): int
    {
        return count($this->all());
    }

    /**
     * A spread of current desktop and mobile browsers across platforms.
     *
     * These are kept realistic and current on purpose: an outdated Chrome
     * version is itself a signal that the client is automated.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [
            // Chrome on Windows
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            // Safari on macOS
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.2 Safari/605.1.15',
            // Firefox on Windows
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:134.0) Gecko/20100101 Firefox/134.0',
            // Chrome on macOS
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            // Edge on Windows
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0',
            // Safari on iPhone - a large share of Jumia's real traffic
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.2 Mobile/15E148 Safari/604.1',
            // Chrome on Android
            'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
            // Firefox on Linux
            'Mozilla/5.0 (X11; Linux x86_64; rv:134.0) Gecko/20100101 Firefox/134.0',
        ];
    }
}
