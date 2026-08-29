<?php

declare(strict_types=1);

namespace App\Scraping\Fetchers;

use App\Scraping\Contracts\PageFetcher;
use App\Scraping\DTO\FetchedPage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Panther\Client;
use Throwable;

/**
 * Fetches a page by driving a real Chrome, for when Guzzle gets turned away.
 *
 * WHY THIS EXISTS
 *
 * Some sites don't block on headers, they block on things a plain HTTP client
 * can't fake. Cloudflare fingerprints the TLS handshake and serves a challenge
 * that only completes if JavaScript actually runs. We proved this on Jumia:
 * every header combination still got a 403, because the problem was never the
 * headers.
 *
 * A real browser has a genuine TLS fingerprint and runs the challenge script,
 * so it can get through where Guzzle can't.
 *
 * WHY IT ISN'T THE DEFAULT
 *
 * Three reasons, in order of importance:
 *
 *   1. The brief asks for Guzzle. This is a fallback, not a replacement.
 *   2. It's slow. Seconds per page instead of milliseconds, and a browser
 *      process costs far more memory than an HTTP request.
 *   3. It can't use the proxy rotation the Go service provides per request,
 *      because Chrome takes its proxy as a launch flag.
 *
 * NO GUARANTEE
 *
 * Chromedriver-driven Chrome sets navigator.webdriver and leaks other
 * automation signals that Cloudflare specifically looks for. This may still be
 * challenged. It is a better chance, not a certainty.
 */
class BrowserFetcher implements PageFetcher
{
    /**
     * @param  string|null  $driverBinary  Absolute path to chromedriver. Null lets
     *                                     Panther search for it, which works on
     *                                     Linux and macOS but not on Windows -
     *                                     see resolveDriver() for why.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 30,
        private readonly ?string $driverBinary = null,
    ) {}

    /**
     * Load the URL in Chrome and return the rendered HTML.
     *
     * The client is created and destroyed per call. Keeping a browser alive
     * between scrapes would be faster, but this path is already the slow
     * exception, and a leaked Chrome process is a much worse problem than a
     * slow one.
     */
    public function fetch(string $url): FetchedPage
    {
        $client = null;

        try {
            $client = Client::createChromeClient($this->resolveDriver(), [
                '--headless=new',
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                // Chrome announces itself as automated unless this is off. It
                // isn't enough on its own to beat a determined bot check, but
                // there's no reason to volunteer the information.
                '--disable-blink-features=AutomationControlled',
                '--window-size=1920,1080',
            ]);

            $client->request('GET', $url);

            // Give the page a moment to run whatever it wants to run. A
            // challenge that redirects only does so after its script executes,
            // so returning immediately would capture the challenge itself.
            $client->wait($this->timeoutSeconds, 500)->until(
                fn () => true,
            );

            $html = $client->getPageSource();

            $this->logger->info('Fetched a page through the browser.', [
                'url' => $url,
                'bytes' => strlen($html),
            ]);

            // Panther reports a rendered page rather than one HTTP response,
            // so there is no single meaningful status to hand back.
            return new FetchedPage(status: null, html: $html, fetchedBy: $this->name());
        } catch (Throwable $e) {
            $this->logger->warning('Browser fetch failed.', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return new FetchedPage(status: null, html: '', fetchedBy: $this->name());
        } finally {
            // Always close the browser. Without this, a failed scrape leaves a
            // Chrome process behind, and enough of those will exhaust the box.
            try {
                $client?->quit();
            } catch (Throwable) {
                // Nothing useful to do if the browser has already died.
            }
        }
    }

    /**
     * Absolute path to chromedriver, or null to let Panther find it.
     *
     * Panther searches with ExecutableFinder over ['./drivers', './vendor/bin'],
     * which hands back a RELATIVE path like "./drivers/chromedriver.exe". On
     * Linux and macOS that runs fine. On Windows it doesn't: cmd.exe reports
     * "'.' is not recognized as an internal or external command" and the
     * browser never starts.
     *
     * Resolving to an absolute path ourselves sidesteps that entirely and
     * costs nothing on the platforms where Panther's own lookup already works.
     */
    private function resolveDriver(): ?string
    {
        if ($this->driverBinary !== null && is_file($this->driverBinary)) {
            return $this->driverBinary;
        }

        foreach (['drivers/chromedriver.exe', 'drivers/chromedriver'] as $candidate) {
            $path = base_path($candidate);

            if (is_file($path)) {
                return $path;
            }
        }

        // Nothing found locally. Let Panther try the system PATH; if that also
        // fails it throws a clear "chromedriver binary not found" message.
        return null;
    }

    public function name(): string
    {
        return 'browser';
    }
}
