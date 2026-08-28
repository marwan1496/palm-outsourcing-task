<?php

declare(strict_types=1);

use App\Scraping\Support\UserAgentPool;

describe('rotation', function () {
    it('returns a different user agent on each call', function () {
        $pool = new UserAgentPool(['agent-a', 'agent-b', 'agent-c']);

        expect($pool->next())->toBe('agent-a')
            ->and($pool->next())->toBe('agent-b')
            ->and($pool->next())->toBe('agent-c');
    });

    it('wraps around to the start once the pool is exhausted', function () {
        $pool = new UserAgentPool(['agent-a', 'agent-b']);

        $pool->next();
        $pool->next();

        expect($pool->next())->toBe('agent-a');
    });

    // Round-robin is chosen over random selection precisely so that the same
    // agent is never handed out twice in a row - repetition is the pattern
    // that gets a scraper noticed.
    it('never repeats an agent back-to-back', function () {
        $pool = new UserAgentPool(['a', 'b', 'c', 'd']);

        $previous = null;
        for ($i = 0; $i < 20; $i++) {
            $current = $pool->next();
            expect($current)->not->toBe($previous);
            $previous = $current;
        }
    });

    it('uses every agent equally over a full number of cycles', function () {
        $pool = new UserAgentPool(['a', 'b', 'c', 'd']);

        $counts = [];
        for ($i = 0; $i < 40; $i++) {
            $agent = $pool->next();
            $counts[$agent] = ($counts[$agent] ?? 0) + 1;
        }

        expect($counts)->toBe(['a' => 10, 'b' => 10, 'c' => 10, 'd' => 10]);
    });

    it('keeps working with a single agent configured', function () {
        $pool = new UserAgentPool(['only-one']);

        expect($pool->next())->toBe('only-one')
            ->and($pool->next())->toBe('only-one');
    });
});

describe('defaults', function () {
    it('falls back to the built-in pool when none is configured', function () {
        $pool = new UserAgentPool;

        expect($pool->all())->toBe(UserAgentPool::defaults())
            ->and($pool->count())->toBeGreaterThan(1);
    });

    it('ships several distinct built-in agents', function () {
        $defaults = UserAgentPool::defaults();

        expect($defaults)->toHaveCount(count(array_unique($defaults)));
    });

    // A user-agent that does not look like a browser defeats the point.
    it('has built-in agents that all look like real browsers', function () {
        foreach (UserAgentPool::defaults() as $agent) {
            expect($agent)->toStartWith('Mozilla/5.0')
                ->and(strlen($agent))->toBeGreaterThan(40);
        }
    });

    it('covers both desktop and mobile clients', function () {
        $joined = implode(' ', UserAgentPool::defaults());

        expect($joined)->toContain('Windows NT')
            ->and($joined)->toContain('Macintosh')
            ->and($joined)->toContain('iPhone')
            ->and($joined)->toContain('Android');
    });

    it('prefers the configured agents over the defaults', function () {
        $pool = new UserAgentPool(['custom-agent']);

        expect($pool->all())->toBe(['custom-agent'])
            ->and($pool->count())->toBe(1);
    });
});
