<?php

declare(strict_types=1);

use App\Scraping\Exceptions\UnsafeUrlException;
use App\Scraping\Support\UrlGuard;

/**
 * UrlGuard is the backend's most important security control, so it gets the
 * most thorough tests: one per rejection rule, plus the attack cases those
 * rules exist to stop.
 */

/**
 * Build a guard whose DNS answers are scripted, so tests never hit a resolver.
 *
 * @param  array<string, list<string>>  $dns  hostname => resolved addresses
 */
function guard(array $dns = [], array $hosts = ['jumia.com.eg', 'amazon.eg'], array $schemes = ['https']): UrlGuard
{
    return new UrlGuard(
        allowedHosts: $hosts,
        allowedSchemes: $schemes,
        verifyDns: true,
        resolver: fn (string $host): array => $dns[$host] ?? ['93.184.216.34'], // a public IP by default
    );
}

describe('accepting legitimate URLs', function () {
    it('accepts an allowlisted storefront URL', function () {
        expect(guard()->isSafe('https://www.jumia.com.eg/product/abc.html'))->toBeTrue();
    });

    it('accepts the bare apex domain as well as subdomains', function () {
        expect(guard()->isSafe('https://jumia.com.eg/product.html'))->toBeTrue();
        expect(guard()->isSafe('https://www.jumia.com.eg/product.html'))->toBeTrue();
        expect(guard()->isSafe('https://deals.jumia.com.eg/product.html'))->toBeTrue();
    });

    it('accepts an explicit port 443', function () {
        expect(guard()->isSafe('https://www.jumia.com.eg:443/product.html'))->toBeTrue();
    });

    it('does not throw for a URL it accepts', function () {
        guard()->assertSafe('https://www.jumia.com.eg/product.html');
    })->throwsNoExceptions();
});

describe('rule 1 - the URL must parse and have a host', function () {
    it('rejects a URL with no host', function () {
        expect(fn () => guard()->assertSafe('not-a-url'))
            ->toThrow(UnsafeUrlException::class, 'could not be parsed');
    });

    it('rejects an empty string', function () {
        expect(fn () => guard()->assertSafe(''))->toThrow(UnsafeUrlException::class);
    });

    it('rejects a path with no host', function () {
        expect(fn () => guard()->assertSafe('/just/a/path'))->toThrow(UnsafeUrlException::class);
    });
});

describe('rule 2 - the scheme must be allowlisted', function () {
    it('rejects plain http when only https is allowed', function () {
        expect(fn () => guard()->assertSafe('http://www.jumia.com.eg/product.html'))
            ->toThrow(UnsafeUrlException::class, 'not allowed');
    });

    // These schemes let an attacker read local files or reach non-HTTP
    // services entirely, so they are the highest-value ones to block.
    it('rejects dangerous non-HTTP schemes', function (string $url) {
        expect(fn () => guard()->assertSafe($url))->toThrow(UnsafeUrlException::class);
    })->with([
        'file' => 'file:///etc/passwd',
        'gopher' => 'gopher://jumia.com.eg:70/x',
        'ftp' => 'ftp://jumia.com.eg/file.txt',
        'data' => 'data:text/plain,hello',
        'dict' => 'dict://jumia.com.eg:2628/x',
    ]);

    it('accepts http when it is explicitly allowed', function () {
        $lenient = guard(schemes: ['http', 'https']);

        expect($lenient->isSafe('http://www.jumia.com.eg/product.html'))->toBeTrue();
    });
});

describe('rule 3 - credentials in the URL are rejected', function () {
    // "https://jumia.com.eg@evil.test/" has host evil.test but reads as Jumia
    // to a human skimming logs. Refusing credentials removes the ambiguity.
    it('rejects a URL containing a username', function () {
        expect(fn () => guard()->assertSafe('https://user@www.jumia.com.eg/product.html'))
            ->toThrow(UnsafeUrlException::class, 'credentials');
    });

    it('rejects a URL containing a username and password', function () {
        expect(fn () => guard()->assertSafe('https://user:secret@www.jumia.com.eg/product.html'))
            ->toThrow(UnsafeUrlException::class, 'credentials');
    });
});

describe('rule 4 - only normal web ports are allowed', function () {
    it('rejects ports used by internal services', function (int $port) {
        expect(fn () => guard()->assertSafe("https://www.jumia.com.eg:{$port}/x"))
            ->toThrow(UnsafeUrlException::class, 'not allowed');
    })->with([
        'mysql' => 3306,
        'redis' => 6379,
        'postgres' => 5432,
        'ssh' => 22,
        'elasticsearch' => 9200,
        'go service' => 8081,
    ]);

    it('allows port 80 and 443', function (int $port) {
        expect(guard(schemes: ['http', 'https'])->isSafe("https://www.jumia.com.eg:{$port}/x"))->toBeTrue();
    })->with([80, 443]);
});

describe('rule 4 - the host must be an allowlisted storefront', function () {
    it('rejects a host that is not on the allowlist', function () {
        expect(fn () => guard()->assertSafe('https://evil.test/product.html'))
            ->toThrow(UnsafeUrlException::class, 'not an allowed storefront');
    });

    // The classic allowlist bypass: make the allowed domain a *prefix* of a
    // domain you control. Matching must be on a dot boundary, not a substring.
    it('rejects a lookalike domain that merely starts with an allowed one', function () {
        expect(fn () => guard()->assertSafe('https://jumia.com.eg.evil.test/product.html'))
            ->toThrow(UnsafeUrlException::class, 'not an allowed storefront');
    });

    it('rejects a domain that merely contains an allowed one', function () {
        expect(fn () => guard()->assertSafe('https://notjumia.com.eg/product.html'))
            ->toThrow(UnsafeUrlException::class);
    });

    it('matches the host case-insensitively', function () {
        expect(guard()->isSafe('https://WWW.JUMIA.COM.EG/product.html'))->toBeTrue();
    });

    it('ignores a trailing dot on the hostname', function () {
        // "jumia.com.eg." is the fully-qualified form and resolves identically.
        expect(guard()->isSafe('https://www.jumia.com.eg./product.html'))->toBeTrue();
    });
});

describe('rule 5 - the host must resolve to a public address', function () {
    // This is the check that actually stops SSRF. An allowlisted domain whose
    // DNS record points inward would pass every check above it.
    it('rejects an allowlisted host that resolves to the cloud metadata endpoint', function () {
        $guard = guard(['www.jumia.com.eg' => ['169.254.169.254']]);

        expect(fn () => $guard->assertSafe('https://www.jumia.com.eg/x'))
            ->toThrow(UnsafeUrlException::class, 'non-public address');
    });

    it('rejects hosts resolving into private and reserved ranges', function (string $address) {
        $guard = guard(['www.jumia.com.eg' => [$address]]);

        expect(fn () => $guard->assertSafe('https://www.jumia.com.eg/x'))
            ->toThrow(UnsafeUrlException::class, 'non-public address');
    })->with([
        'loopback' => '127.0.0.1',
        'private 10/8' => '10.0.0.1',
        'private 172.16/12' => '172.16.0.1',
        'private 192.168/16' => '192.168.1.1',
        'link-local' => '169.254.169.254',
        'all zeroes' => '0.0.0.0',
        'IPv6 loopback' => '::1',
        'IPv6 unique-local' => 'fd00::1',
    ]);

    // A host with several A records could otherwise smuggle one internal
    // address past a check that only looked at the first.
    it('rejects when any one of several addresses is internal', function () {
        $guard = guard(['www.jumia.com.eg' => ['93.184.216.34', '10.0.0.5']]);

        expect(fn () => $guard->assertSafe('https://www.jumia.com.eg/x'))
            ->toThrow(UnsafeUrlException::class, 'non-public address');
    });

    it('rejects a host that does not resolve at all', function () {
        $guard = guard(['www.jumia.com.eg' => []]);

        expect(fn () => $guard->assertSafe('https://www.jumia.com.eg/x'))
            ->toThrow(UnsafeUrlException::class, 'could not be resolved');
    });

    it('does not leak the internal address it found', function () {
        $guard = guard(['www.jumia.com.eg' => ['10.1.2.3']]);

        try {
            $guard->assertSafe('https://www.jumia.com.eg/x');
            $this->fail('Expected the guard to reject this host.');
        } catch (UnsafeUrlException $e) {
            // Confirming which internal addresses exist would help whoever is
            // probing, so the message must not echo it back.
            expect($e->getMessage())->not->toContain('10.1.2.3');
        }
    });

    it('skips the DNS check when it is disabled', function () {
        $guard = new UrlGuard(['jumia.com.eg'], ['https'], verifyDns: false);

        expect($guard->isSafe('https://www.jumia.com.eg/x'))->toBeTrue();
    });
});

describe('isSafe', function () {
    it('returns false instead of throwing for a rejected URL', function () {
        expect(guard()->isSafe('https://evil.test/x'))->toBeFalse();
    });

    it('returns true for an accepted URL', function () {
        expect(guard()->isSafe('https://www.jumia.com.eg/x'))->toBeTrue();
    });
});
