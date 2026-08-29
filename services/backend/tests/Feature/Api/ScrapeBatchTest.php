<?php

declare(strict_types=1);

use App\Enums\ScrapeJobStatus;
use App\Jobs\ScrapeProductJob;
use App\Models\ScrapeJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());
});

const JUMIA_A = 'https://www.jumia.com.eg/product-a.html';
const JUMIA_B = 'https://www.jumia.com.eg/product-b.html';
const AMAZON_A = 'https://www.amazon.eg/dp/B01LR8CIRC';

describe('submitting a single URL', function () {
    // The original single-url shape has to keep working: it is what the docs,
    // the artisan command and anyone who already integrated are using.
    it('still accepts the original single url field', function () {
        $this->postJson('/api/v1/scrape', ['url' => JUMIA_A])
            ->assertStatus(202)
            ->assertJsonCount(1, 'accepted')
            ->assertJsonPath('accepted.0.url', JUMIA_A)
            ->assertJsonPath('accepted.0.status', 'pending');

        Queue::assertPushed(ScrapeProductJob::class);
        $this->assertDatabaseCount('scrape_jobs', 1);
    });

    it('records the job as pending with a batch id', function () {
        $response = $this->postJson('/api/v1/scrape', ['url' => JUMIA_A])->assertStatus(202);

        $job = ScrapeJob::first();
        expect($job->status)->toBe(ScrapeJobStatus::Pending)
            ->and($job->batch_id)->toBe($response->json('batch_id'))
            ->and($job->product_id)->toBeNull();
    });
});

describe('submitting several URLs', function () {
    it('queues every valid URL in one request', function () {
        $this->postJson('/api/v1/scrape', ['urls' => [JUMIA_A, JUMIA_B, AMAZON_A]])
            ->assertStatus(202)
            ->assertJsonCount(3, 'accepted')
            ->assertJsonCount(0, 'rejected');

        Queue::assertPushed(ScrapeProductJob::class, 3);
        $this->assertDatabaseCount('scrape_jobs', 3);
    });

    it('groups them under one batch id', function () {
        $response = $this->postJson('/api/v1/scrape', ['urls' => [JUMIA_A, JUMIA_B]]);

        expect(ScrapeJob::distinct()->pluck('batch_id'))->toHaveCount(1)
            ->and(ScrapeJob::first()->batch_id)->toBe($response->json('batch_id'));
    });

    it('rejects duplicates within the same batch', function () {
        $this->postJson('/api/v1/scrape', ['urls' => [JUMIA_A, JUMIA_A, JUMIA_B]])
            ->assertStatus(202)
            ->assertJsonCount(2, 'accepted')
            ->assertJsonCount(1, 'rejected')
            ->assertJsonPath('rejected.0.reason', 'Duplicate URL in this batch.');

        $this->assertDatabaseCount('scrape_jobs', 2);
    });
});

describe('partial success', function () {
    // The point of the batch design: one bad URL must not sink the rest.
    it('queues the good URLs and reports the bad ones', function () {
        $response = $this->postJson('/api/v1/scrape', [
            'urls' => [JUMIA_A, 'https://www.ebay.com/itm/123', JUMIA_B],
        ])->assertStatus(202);

        expect($response->json('accepted'))->toHaveCount(2)
            ->and($response->json('rejected'))->toHaveCount(1);

        Queue::assertPushed(ScrapeProductJob::class, 2);
        $this->assertDatabaseCount('scrape_jobs', 2);
    });

    it('explains why each rejected URL was turned away', function () {
        $response = $this->postJson('/api/v1/scrape', [
            'urls' => [JUMIA_A, 'https://169.254.169.254/latest/meta-data/'],
        ])->assertStatus(202);

        expect($response->json('rejected.0.reason'))->toBeString()->not->toBeEmpty();
    });

    it('creates no job row for a rejected URL', function () {
        $this->postJson('/api/v1/scrape', ['urls' => [JUMIA_A, 'http://insecure.jumia.com.eg/x.html']]);

        expect(ScrapeJob::pluck('url')->all())->toBe([JUMIA_A]);
    });
});

describe('rejecting the whole batch', function () {
    // Nothing was accepted, so there is no progress to report. 422 is the
    // honest answer rather than a 202 pointing at an empty batch.
    it('returns 422 when no URL is acceptable', function () {
        $this->postJson('/api/v1/scrape', [
            'urls' => ['https://www.ebay.com/itm/1', 'https://169.254.169.254/'],
        ])
            ->assertStatus(422)
            ->assertJsonCount(2, 'rejected');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('scrape_jobs', 0);
    });

    it('rejects every SSRF target', function (string $url) {
        $this->postJson('/api/v1/scrape', ['urls' => [$url]])->assertStatus(422);

        Queue::assertNothingPushed();
    })->with([
        'cloud metadata' => 'https://169.254.169.254/latest/meta-data/',
        'loopback' => 'https://127.0.0.1/admin',
        'private network' => 'https://192.168.1.1/admin',
        'internal port' => 'https://www.jumia.com.eg:3306/',
        'lookalike domain' => 'https://jumia.com.eg.evil.test/x.html',
        'credentials' => 'https://jumia.com.eg@evil.test/x.html',
        'file scheme' => 'file:///etc/passwd',
    ]);
});

describe('input validation', function () {
    it('requires either url or urls', function () {
        $this->postJson('/api/v1/scrape', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('urls');
    });

    it('caps the batch at ten URLs', function () {
        $urls = array_map(fn (int $i) => "https://www.jumia.com.eg/p{$i}.html", range(1, 11));

        $this->postJson('/api/v1/scrape', ['urls' => $urls])
            ->assertStatus(422)
            ->assertJsonValidationErrors('urls');

        Queue::assertNothingPushed();
    });

    it('accepts exactly ten URLs', function () {
        $urls = array_map(fn (int $i) => "https://www.jumia.com.eg/p{$i}.html", range(1, 10));

        $this->postJson('/api/v1/scrape', ['urls' => $urls])
            ->assertStatus(202)
            ->assertJsonCount(10, 'accepted');
    });

    it('rejects an empty urls array', function () {
        $this->postJson('/api/v1/scrape', ['urls' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('urls');
    });

    it('rejects a URL longer than the column allows', function () {
        $long = 'https://www.jumia.com.eg/'.str_repeat('a', 600).'.html';

        $this->postJson('/api/v1/scrape', ['urls' => [$long]])
            ->assertStatus(422);
    });

    // Malformed input never reaches the queue: UrlGuard turns each one away,
    // and a batch with nothing acceptable is a 422.
    it('rejects malformed URLs', function (string $url) {
        $this->postJson('/api/v1/scrape', ['urls' => [$url]])->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('scrape_jobs', 0);
    })->with([
        'not a url' => 'this is not a url',
        'plain http' => 'http://www.jumia.com.eg/x.html',
        'missing host' => 'https:///x.html',
        'unsupported storefront' => 'https://www.ebay.com/itm/123',
    ]);
});

describe('authentication and rate limiting', function () {
    it('rejects an unauthenticated submission', function () {
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/scrape', ['url' => JUMIA_A])->assertUnauthorized();
    });

    it('limits scrape submissions to ten per minute', function () {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/scrape', ['url' => JUMIA_A])->assertStatus(202);
        }

        $this->postJson('/api/v1/scrape', ['url' => JUMIA_A])->assertStatus(429);
    });
});
