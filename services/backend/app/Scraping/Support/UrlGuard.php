<?php

declare(strict_types=1);

namespace App\Scraping\Support;

use App\Scraping\Exceptions\UnsafeUrlException;
use Closure;

/**
 * Decides whether a URL is safe for the scraper to fetch.
 *
 * WHY THIS CLASS EXISTS - this is the most important security control in the
 * backend. `POST /api/v1/scrape` accepts a URL and makes the server fetch it.
 * Without validation that endpoint is a Server-Side Request Forgery hole: an
 * authenticated caller could point it at
 *
 *   http://169.254.169.254/latest/meta-data/   (cloud credentials)
 *   http://127.0.0.1:3306/                     (the database)
 *   http://192.168.1.1/admin                   (anything on the LAN)
 *
 * and use our server as a proxy into infrastructure they cannot otherwise
 * reach. Firewalls do not help, because the request originates inside them.
 *
 * The guard applies five checks, in order of cost - cheap string checks first,
 * the DNS lookup last:
 *
 *   1. The URL must parse and have a host.
 *   2. The scheme must be allowlisted (https only by default).
 *   3. The URL must not embed credentials (user:pass@host).
 *   4. The host must be an allowlisted storefront, and the port a normal
 *      web port.
 *   5. Every IP the host resolves to must be publicly routable.
 *
 * Check 5 is what defends against an allowlisted domain whose DNS record
 * points at an internal address. An allowlist alone is not sufficient.
 */
class UrlGuard
{
    /**
     * Ports a storefront legitimately serves from. Anything else is either a
     * mistake or an attempt to reach an internal service.
     *
     * @var list<int>
     */
    private const ALLOWED_PORTS = [80, 443];

    /**
     * @param  list<string>  $allowedHosts  Domains, matched exactly or as a parent
     *                                      of the host (so "jumia.com.eg" also
     *                                      allows "www.jumia.com.eg").
     * @param  list<string>  $allowedSchemes  Lowercase URL schemes.
     * @param  bool  $verifyDns  Whether to resolve the host and check its IPs.
     *                           Only ever disabled in tests that use fake HTTP.
     * @param  (Closure(string): list<string>)|null  $resolver  Hostname to IPs.
     *                                                          Injectable so tests
     *                                                          need no real DNS.
     */
    public function __construct(
        private readonly array $allowedHosts,
        private readonly array $allowedSchemes = ['https'],
        private readonly bool $verifyDns = true,
        private readonly ?Closure $resolver = null,
    ) {}

    /**
     * Throw unless the URL is safe to fetch.
     *
     * @throws UnsafeUrlException with a message safe to show the caller - it
     *                            names the rule that failed but never leaks
     *                            which internal addresses exist.
     */
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            throw new UnsafeUrlException('The URL could not be parsed.');
        }

        $this->assertSchemeAllowed($parts['scheme'] ?? '');
        $this->assertNoEmbeddedCredentials($parts);
        $this->assertPortAllowed($parts['port'] ?? null);
        $this->assertHostAllowed($parts['host']);

        if ($this->verifyDns) {
            $this->assertResolvesToPublicAddress($parts['host']);
        }
    }

    /**
     * Whether the URL passes every check, without throwing.
     */
    public function isSafe(string $url): bool
    {
        try {
            $this->assertSafe($url);

            return true;
        } catch (UnsafeUrlException) {
            return false;
        }
    }

    /**
     * Reject schemes such as file://, gopher:// and ftp://, which can be used
     * to read local files or reach non-HTTP services.
     */
    private function assertSchemeAllowed(string $scheme): void
    {
        if (! in_array(strtolower($scheme), $this->allowedSchemes, true)) {
            throw new UnsafeUrlException(sprintf(
                'URL scheme [%s] is not allowed. Allowed: %s.',
                $scheme !== '' ? $scheme : '(none)',
                implode(', ', $this->allowedSchemes),
            ));
        }
    }

    /**
     * Reject URLs carrying credentials.
     *
     * "https://jumia.com.eg@evil.test/" parses with host "evil.test" but reads
     * as Jumia to a human reviewing logs. Refusing credentials entirely
     * removes that whole class of confusion.
     *
     * @param  array<string, mixed>  $parts
     */
    private function assertNoEmbeddedCredentials(array $parts): void
    {
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('URLs containing credentials are not allowed.');
        }
    }

    /**
     * Confine requests to normal web ports so the endpoint cannot be used to
     * probe internal services such as MySQL on 3306 or Redis on 6379.
     */
    private function assertPortAllowed(?int $port): void
    {
        if ($port !== null && ! in_array($port, self::ALLOWED_PORTS, true)) {
            throw new UnsafeUrlException(sprintf(
                'Port %d is not allowed. Allowed ports: %s.',
                $port,
                implode(', ', self::ALLOWED_PORTS),
            ));
        }
    }

    /**
     * Require the host to be a storefront we actually support.
     *
     * Matching is exact or on a dot-boundary, so "jumia.com.eg" allows
     * "www.jumia.com.eg" but rejects "jumia.com.eg.evil.test".
     */
    private function assertHostAllowed(string $host): void
    {
        $host = strtolower(rtrim($host, '.'));

        foreach ($this->allowedHosts as $allowed) {
            $allowed = strtolower($allowed);

            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return;
            }
        }

        throw new UnsafeUrlException(sprintf('Host [%s] is not an allowed storefront.', $host));
    }

    /**
     * Resolve the host and require every returned address to be public.
     *
     * Every address is checked, not just the first: a host with several A
     * records could otherwise smuggle an internal address past a check that
     * only looked at one of them.
     */
    private function assertResolvesToPublicAddress(string $host): void
    {
        $addresses = $this->resolve($host);

        if ($addresses === []) {
            throw new UnsafeUrlException(sprintf('Host [%s] could not be resolved.', $host));
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                // The address itself is deliberately not echoed back: that
                // would confirm internal addresses to whoever is probing.
                throw new UnsafeUrlException(sprintf(
                    'Host [%s] resolves to a non-public address.',
                    $host,
                ));
            }
        }
    }

    /**
     * Resolve a hostname to its IP addresses.
     *
     * A literal IP in the URL is returned as-is, so "https://127.0.0.1/" is
     * still checked rather than being treated as an unresolvable name.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ipv4 = gethostbynamel($host);

        return $ipv4 === false ? [] : $ipv4;
    }

    /**
     * Whether an IP address is publicly routable.
     *
     * NO_PRIV_RANGE rejects RFC 1918 space (10/8, 172.16/12, 192.168/16) and
     * IPv6 unique-local addresses. NO_RES_RANGE rejects reserved ranges,
     * including loopback (127/8) and link-local (169.254/16) - the latter
     * being the cloud metadata endpoint.
     */
    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
