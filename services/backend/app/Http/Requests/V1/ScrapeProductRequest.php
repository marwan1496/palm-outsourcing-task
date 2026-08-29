<?php

declare(strict_types=1);

namespace App\Http\Requests\V1;

use App\Scraping\ScrapeBatchDispatcher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a scrape submission.
 *
 * Two shapes are accepted:
 *
 *   {"url": "https://..."}                  a single page
 *   {"urls": ["https://...", "https://..."]} up to ten
 *
 * The single form is kept because it's what the API originally shipped with and
 * what the docs and the artisan command already use. Both normalise to a list,
 * so nothing downstream has to care which was sent.
 *
 * Note what is deliberately NOT validated here: whether each URL is safe and
 * supported. That check produces a per-URL reason, and a FormRequest can only
 * pass or fail the whole request. Since a batch is allowed to partly succeed,
 * ScrapeBatchDispatcher makes that judgement instead and reports both lists.
 */
class ScrapeProductRequest extends FormRequest
{
    /**
     * The route already sits behind auth:sanctum, so anything reaching this
     * point is authenticated.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'required_without:urls', 'string', 'max:512'],

            'urls' => ['sometimes', 'required_without:url', 'array', 'min:1', 'max:'.ScrapeBatchDispatcher::MAX_URLS],
            'urls.*' => ['required', 'string', 'max:512'],
        ];
    }

    /**
     * Reject a request that sends neither field, which would otherwise slip
     * through as an empty-but-valid payload.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('url') && ! $this->has('urls')) {
                    $validator->errors()->add('urls', 'Provide either a "url" string or a "urls" array.');
                }
            },
        ];
    }

    /**
     * Both shapes, flattened to the list the dispatcher works with.
     *
     * @return list<string>
     */
    public function urls(): array
    {
        $urls = $this->validated('urls', []);

        if ($this->filled('url')) {
            $urls[] = $this->validated('url');
        }

        return array_values(array_filter(array_map('trim', $urls)));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'urls.max' => 'You can submit at most '.ScrapeBatchDispatcher::MAX_URLS.' URLs at once.',
            'urls.array' => 'The "urls" field must be an array of URL strings.',
            'url.max' => 'The URL is too long (maximum 512 characters).',
            'urls.*.max' => 'One of the URLs is too long (maximum 512 characters).',
        ];
    }
}
