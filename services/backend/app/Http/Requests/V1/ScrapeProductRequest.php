<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Scraping\Exceptions\UnsafeUrlException;
use App\Scraping\ScraperManager;
use App\Scraping\Support\UrlGuard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a request to scrape a product URL.
 *
 * Validation happens here rather than in the controller so that an unsafe or
 * unsupported URL is rejected with a 422 *before* a job is queued. Queueing a
 * job that is certain to fail wastes a worker and hides the real error from
 * the caller.
 *
 * The URL rules run in increasing order of cost: format, then length, then
 * the SSRF guard (which may perform a DNS lookup), then parser support.
 */
class ScrapeProductRequest extends FormRequest
{
    /**
     * Authorisation is handled by the auth:sanctum middleware on the route,
     * so any request that reaches this point is already authenticated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'url' => [
                'required',
                'string',
                // Matches the source_url column, so a URL that passes
                // validation is always storable.
                'max:512',
                'url:https',
                $this->safeUrlRule(),
                $this->supportedStorefrontRule(),
            ],
        ];
    }

    /**
     * Reject URLs that fail the SSRF guard.
     *
     * The guard's own message names the rule that failed - wrong scheme,
     * disallowed host, non-public address - without revealing which internal
     * addresses exist.
     */
    private function safeUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $guard = app(UrlGuard::class);

            try {
                $guard->assertSafe((string) $value);
            } catch (UnsafeUrlException $e) {
                $fail($e->getMessage());
            }
        };
    }

    /**
     * Reject URLs from storefronts we have no parser for.
     */
    private function supportedStorefrontRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (app(ScraperManager::class)->parserFor((string) $value) === null) {
                $fail('No parser is available for this storefront. Supported sites: Jumia, Amazon.');
            }
        };
    }

    /**
     * Human-readable messages for the built-in rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'A product URL is required.',
            'url.url' => 'The product URL must be a valid HTTPS URL.',
            'url.max' => 'The product URL is too long (maximum 512 characters).',
        ];
    }
}
