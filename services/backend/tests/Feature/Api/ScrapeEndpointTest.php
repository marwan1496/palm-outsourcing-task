<?php

declare(strict_types=1);

use App\Jobs\ScrapeProductJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    Sanctum::actingAs(User::factory()->create());
});

describe('authentication', function () {
    it('rejects an unauthenticated scrape request', function () {
        // Re-authenticate as a guest for this one case.
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/scrape', ['url' => 'https://www.jumia.com.eg/x.html'])
            ->assertUnauthorized();
    });
});

describe('queueing a scrape', function () {
    // The work is queued, not done inline: scraping takes seconds and holding
    // an HTTP connection open for it times the client out.
    it('accepts a valid URL and queues the job', function () {
        $url = 'https://www.jumia.com.eg/samsung-galaxy-a55.html';

        $this->postJson('/api/v1/scrape', ['url' => $url])
            ->assertStatus(202)
            ->assertJsonPath('url', $url);

        Queue::assertPushed(ScrapeProductJob::class, fn ($job) => $job->url === $url);
    });

    it('accepts an Amazon URL too', function () {
        $this->postJson('/api/v1/scrape', ['url' => 'https://www.amazon.eg/dp/B01LR8CIRC'])
            ->assertStatus(202);

        Queue::assertPushed(ScrapeProductJob::class);
    });
});

describe('input validation', function () {
    it('requires a URL', function () {
        $this->postJson('/api/v1/scrape', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        Queue::assertNothingPushed();
    });

    it('rejects malformed input', function (mixed $url) {
        $this->postJson('/api/v1/scrape', ['url' => $url])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        Queue::assertNothingPushed();
    })->with([
        'not a url' => 'this is not a url',
        'empty string' => '',
        'plain http' => 'http://www.jumia.com.eg/x.html',
        'missing host' => 'https:///x.html',
    ]);

    it('rejects a URL longer than the column allows', function () {
        $url = 'https://www.jumia.com.eg/'.str_repeat('a', 600).'.html';

        $this->postJson('/api/v1/scrape', ['url' => $url])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    });

    it('rejects a storefront with no parser', function () {
        $this->postJson('/api/v1/scrape', ['url' => 'https://www.ebay.com/itm/123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        Queue::assertNothingPushed();
    });
});

describe('SSRF protection at the API boundary', function () {
    // Without UrlGuard this endpoint would be a proxy into internal
    // infrastructure. These are the attacks it exists to stop.
    it('refuses to fetch internal and reserved addresses', function (string $url) {
        $this->postJson('/api/v1/scrape', ['url' => $url])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        Queue::assertNothingPushed();
    })->with([
        'cloud metadata' => 'https://169.254.169.254/latest/meta-data/',
        'loopback' => 'https://127.0.0.1/admin',
        'localhost' => 'https://localhost/admin',
        'private network' => 'https://192.168.1.1/admin',
        'private 10/8' => 'https://10.0.0.1/',
        'file scheme' => 'file:///etc/passwd',
        'gopher scheme' => 'gopher://127.0.0.1:6379/_INFO',
        'internal port' => 'https://www.jumia.com.eg:3306/',
        'lookalike domain' => 'https://jumia.com.eg.evil.test/x.html',
        'credentials' => 'https://jumia.com.eg@evil.test/x.html',
    ]);

    it('explains why the URL was rejected without leaking internals', function () {
        $response = $this->postJson('/api/v1/scrape', ['url' => 'https://169.254.169.254/'])
            ->assertStatus(422);

        expect($response->json('errors.url.0'))->toBeString()->not->toBeEmpty();
    });
});

describe('rate limiting', function () {
    // Each scrape causes an outbound fetch through a proxy, so it is far more
    // expensive than a read and is limited far more tightly.
    it('limits scrape requests to 10 per minute', function () {
        $url = 'https://www.jumia.com.eg/x.html';

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/scrape', ['url' => $url])->assertStatus(202);
        }

        $this->postJson('/api/v1/scrape', ['url' => $url])->assertStatus(429);
    });

    it('allows far more read requests than scrape requests', function () {
        // 20 reads is already double the scrape limit and must still pass.
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/products')->assertOk();
        }
    });
});
