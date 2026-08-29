<?php

declare(strict_types=1);

use App\Enums\ScrapeJobStatus;
use App\Jobs\ScrapeProductJob;
use App\Models\Product;
use App\Models\ScrapeJob;
use App\Models\User;
use App\Scraping\ScraperManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Create a job row directly, bypassing the queue.
 */
function makeJob(array $attributes = []): ScrapeJob
{
    return ScrapeJob::create(array_merge([
        'batch_id' => (string) Str::uuid(),
        'url' => 'https://www.jumia.com.eg/product-'.Str::random(6).'.html',
        'status' => ScrapeJobStatus::Pending,
    ], $attributes));
}

describe('authentication', function () {
    it('rejects unauthenticated requests to every job route', function () {
        $job = makeJob();

        $this->getJson('/api/v1/scrape-jobs')->assertUnauthorized();
        $this->getJson("/api/v1/scrape-jobs/{$job->id}")->assertUnauthorized();
        $this->postJson("/api/v1/scrape-jobs/{$job->id}/retry")->assertUnauthorized();
    });
});

describe('listing jobs', function () {
    beforeEach(fn () => Sanctum::actingAs(User::factory()->create()));

    it('returns jobs newest first', function () {
        $older = makeJob(['url' => 'https://www.jumia.com.eg/older.html']);
        $newer = makeJob(['url' => 'https://www.jumia.com.eg/newer.html']);

        $this->getJson('/api/v1/scrape-jobs')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    });

    it('returns an empty list rather than an error when there are none', function () {
        $this->getJson('/api/v1/scrape-jobs')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    });

    it('exposes the fields the UI needs', function () {
        makeJob();

        $this->getJson('/api/v1/scrape-jobs')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'batch_id', 'url', 'status', 'status_label', 'is_terminal', 'is_retryable', 'error', 'attempts', 'created_at']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'unfinished'],
            ]);
    });

    it('filters by status', function () {
        makeJob(['status' => ScrapeJobStatus::Completed]);
        makeJob(['status' => ScrapeJobStatus::Failed]);
        makeJob(['status' => ScrapeJobStatus::Failed]);

        $this->getJson('/api/v1/scrape-jobs?status=failed')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('filters by batch', function () {
        $batch = (string) Str::uuid();
        makeJob(['batch_id' => $batch]);
        makeJob(['batch_id' => $batch]);
        makeJob();

        $this->getJson("/api/v1/scrape-jobs?batch_id={$batch}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    it('rejects invalid filters', function (string $query) {
        $this->getJson("/api/v1/scrape-jobs?{$query}")->assertStatus(422);
    })->with([
        'unknown status' => 'status=exploded',
        'batch id not a uuid' => 'batch_id=not-a-uuid',
        'per_page too large' => 'per_page=500',
    ]);

    it('paginates', function () {
        for ($i = 0; $i < 25; $i++) {
            makeJob();
        }

        $this->getJson('/api/v1/scrape-jobs?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 3);
    });

    // This count is what tells the frontend whether to keep polling fast.
    it('reports how many jobs are still unfinished', function () {
        makeJob(['status' => ScrapeJobStatus::Pending]);
        makeJob(['status' => ScrapeJobStatus::Running]);
        makeJob(['status' => ScrapeJobStatus::Completed]);
        makeJob(['status' => ScrapeJobStatus::Failed]);

        $this->getJson('/api/v1/scrape-jobs')
            ->assertOk()
            ->assertJsonPath('meta.unfinished', 2);
    });

    it('counts unfinished jobs across every page, not just the current one', function () {
        for ($i = 0; $i < 15; $i++) {
            makeJob(['status' => ScrapeJobStatus::Pending]);
        }

        $this->getJson('/api/v1/scrape-jobs?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.unfinished', 15);
    });

    // Job status changes second to second; a cached list would look like a
    // stalled queue.
    it('is never cached', function () {
        // Laravel appends "private" of its own accord, so assert on the
        // directive that matters rather than the exact header string.
        $header = $this->getJson('/api/v1/scrape-jobs')->assertOk()
            ->headers->get('Cache-Control');

        expect($header)->toContain('no-store');
    });

    it('includes the product once a job has produced one', function () {
        $product = Product::factory()->create(['title' => 'Scraped Thing']);
        makeJob(['status' => ScrapeJobStatus::Completed, 'product_id' => $product->id]);

        $this->getJson('/api/v1/scrape-jobs')
            ->assertOk()
            ->assertJsonPath('data.0.product.title', 'Scraped Thing');
    });
});

describe('showing one job', function () {
    beforeEach(fn () => Sanctum::actingAs(User::factory()->create()));

    it('returns the job', function () {
        $job = makeJob(['url' => 'https://www.jumia.com.eg/specific.html']);

        $this->getJson("/api/v1/scrape-jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.url', 'https://www.jumia.com.eg/specific.html');
    });

    it('returns 404 for an unknown id', function () {
        $this->getJson('/api/v1/scrape-jobs/999999')->assertNotFound();
    });
});

describe('retrying a job', function () {
    beforeEach(function () {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create());
    });

    it('re-queues a failed job', function () {
        $job = makeJob(['status' => ScrapeJobStatus::Failed, 'error' => 'HTTP 403']);

        $this->postJson("/api/v1/scrape-jobs/{$job->id}/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(ScrapeProductJob::class);
    });

    it('clears the previous error when re-queued', function () {
        $job = makeJob(['status' => ScrapeJobStatus::Failed, 'error' => 'HTTP 403']);

        $this->postJson("/api/v1/scrape-jobs/{$job->id}/retry")->assertStatus(202);

        expect($job->fresh()->error)->toBeNull();
    });

    it('reuses the same row rather than creating a duplicate', function () {
        $job = makeJob(['status' => ScrapeJobStatus::Failed]);

        $this->postJson("/api/v1/scrape-jobs/{$job->id}/retry")->assertStatus(202);

        $this->assertDatabaseCount('scrape_jobs', 1);
    });

    // The request is fine; it is the job's state that makes it impossible.
    it('refuses to retry a job that is not failed', function (ScrapeJobStatus $status) {
        $job = makeJob(['status' => $status]);

        $this->postJson("/api/v1/scrape-jobs/{$job->id}/retry")->assertStatus(409);

        Queue::assertNothingPushed();
    })->with([
        'pending' => ScrapeJobStatus::Pending,
        'running' => ScrapeJobStatus::Running,
        'completed' => ScrapeJobStatus::Completed,
    ]);
});

describe('the job lifecycle', function () {
    it('moves from pending to completed and links the product', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-product.html'))]);
        $job = makeJob(['url' => 'https://www.jumia.com.eg/a55.html']);

        (new ScrapeProductJob($job->url, $job->id))->handle(app(ScraperManager::class));

        $job->refresh();
        expect($job->status)->toBe(ScrapeJobStatus::Completed)
            ->and($job->product_id)->not->toBeNull()
            ->and($job->error)->toBeNull()
            ->and($job->started_at)->not->toBeNull()
            ->and($job->finished_at)->not->toBeNull()
            ->and($job->attempts)->toBe(1);
    });

    it('records a readable error when the scrape fails', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-blocked.html'))]);
        $job = makeJob(['url' => 'https://www.jumia.com.eg/blocked.html']);

        $scrapeJob = new ScrapeProductJob($job->url, $job->id);

        try {
            $scrapeJob->handle(app(ScraperManager::class));
        } catch (Throwable $e) {
            // The queue would retry; simulate attempts running out.
            $scrapeJob->failed($e);
        }

        $job->refresh();
        expect($job->status)->toBe(ScrapeJobStatus::Failed)
            ->and($job->error)->toContain('Anti-bot interstitial');
    });

    it('marks an unsafe URL failed without retrying', function () {
        Http::fake();
        $job = makeJob(['url' => 'https://169.254.169.254/']);

        // fail() needs a real queue context, so assert the row instead.
        try {
            (new ScrapeProductJob($job->url, $job->id))->handle(app(ScraperManager::class));
        } catch (Throwable) {
            // fail() throws outside a worker; the row is what matters here.
        }

        expect($job->fresh()->status)->toBe(ScrapeJobStatus::Failed);
        Http::assertNothingSent();
    });

    it('works without a tracking row, as the artisan command dispatches it', function () {
        Http::fake(['*' => Http::response(fixtureHtml('jumia-product.html'))]);

        (new ScrapeProductJob('https://www.jumia.com.eg/a55.html'))->handle(app(ScraperManager::class));

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('scrape_jobs', 0);
    });
});
