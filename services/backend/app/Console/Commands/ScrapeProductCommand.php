<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Scraping\Exceptions\ScrapeFailedException;
use App\Scraping\Exceptions\UnsafeUrlException;
use App\Scraping\ScrapeBatchDispatcher;
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
    public function handle(ScraperManager $scraper, ScrapeBatchDispatcher $dispatcher): int
    {
        /** @var list<string> $urls */
        $urls = $this->argument('url');

        if ($this->option('queue')) {
            return $this->dispatchAll($dispatcher, $urls);
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
     * Goes through ScrapeBatchDispatcher rather than dispatching jobs directly, so a CLI
     * submission creates the same tracking rows the API does and shows up on the jobs page.
     * Previously these were invisible there, which made it look like the command had done
     * nothing.
     *
     * @param  list<string>  $urls
     */
    private function dispatchAll(ScrapeBatchDispatcher $dispatcher, array $urls): int
    {
        $result = $dispatcher->dispatch($urls);

        foreach ($result->accepted as $job) {
            $this->line("Queued <comment>{$job->url}</comment>");
        }

        foreach ($result->rejected as $rejection) {
            $this->components->error("{$rejection['url']} - {$rejection['reason']}");
        }

        if ($result->isCompleteFailure()) {
            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Queued %d URL(s) as batch %s. Run `php artisan queue:work` to process them.',
            $result->acceptedCount(),
            $result->batchId,
        ));

        return self::SUCCESS;
    }
}
