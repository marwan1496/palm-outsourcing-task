<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ScrapeProductJob;
use App\Scraping\Exceptions\ScrapeFailedException;
use App\Scraping\Exceptions\UnsafeUrlException;
use App\Scraping\ScraperManager;
use Illuminate\Console\Command;

/**
 * Scrapes one or more product URLs from the command line.
 *
 * This is the fastest way to demonstrate the scraper: no queue worker, no
 * HTTP client, no token - just a URL in and a product row out. It calls the
 * same ScraperManager the API and the queued job use, so what it proves is
 * true of the whole system.
 */
class ScrapeProductCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'products:scrape
                            {url* : One or more product page URLs}
                            {--queue : Dispatch to the queue instead of scraping now}';

    /**
     * @var string
     */
    protected $description = 'Scrape one or more product pages and store them';

    /**
     * Run the command.
     */
    public function handle(ScraperManager $scraper): int
    {
        /** @var list<string> $urls */
        $urls = $this->argument('url');

        if ($this->option('queue')) {
            return $this->dispatchAll($urls);
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($urls as $url) {
            $this->line("Scraping <comment>{$url}</comment>");

            try {
                $product = $scraper->scrape($url);

                $this->components->info(sprintf(
                    'Stored #%d - %s (%s %s)',
                    $product->id,
                    $product->title,
                    $product->currency,
                    number_format($product->priceInMajorUnits(), 2),
                ));

                $succeeded++;
            } catch (UnsafeUrlException $e) {
                // Refused before any request was made - see UrlGuard.
                $this->components->error('Rejected: '.$e->getMessage());
                $failed++;
            } catch (ScrapeFailedException $e) {
                $this->components->error('Failed: '.$e->getMessage());
                $failed++;
            }
        }

        if (count($urls) > 1) {
            $this->newLine();
            $this->line("Done. <info>{$succeeded} succeeded</info>, <comment>{$failed} failed</comment>.");
        }

        // Non-zero exit when nothing worked, so CI and scripts can detect it.
        return $failed > 0 && $succeeded === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Queue the URLs instead of scraping inline.
     *
     * @param  list<string>  $urls
     */
    private function dispatchAll(array $urls): int
    {
        foreach ($urls as $url) {
            ScrapeProductJob::dispatch($url);
            $this->line("Queued <comment>{$url}</comment>");
        }

        $this->components->info(sprintf('Queued %d URL(s). Run `php artisan queue:work` to process them.', count($urls)));

        return self::SUCCESS;
    }
}
