<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature tests boot the full framework and get a clean database per test via
| RefreshDatabase, so one test can never see another's rows.
|
| Unit tests deliberately do NOT boot Laravel. Everything under tests/Unit
| covers plain PHP classes - parsers, the user-agent pool, the URL guard - and
| keeping the framework out of them makes the suite fast and proves those
| classes have no hidden framework dependencies.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Custom expectations
|--------------------------------------------------------------------------
*/

/**
 * Assert that a value is a well-formed absolute HTTP(S) URL.
 *
 * Used by the parser tests, where a relative or protocol-relative image URL is
 * a real bug: the frontend renders these directly in an <img> tag.
 */
expect()->extend('toBeAbsoluteUrl', function () {
    expect(filter_var($this->value, FILTER_VALIDATE_URL))->not->toBeFalse(
        "Expected '{$this->value}' to be a valid URL."
    );

    expect(parse_url($this->value, PHP_URL_SCHEME))->toBeIn(['http', 'https']);

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Read a saved HTML fixture from tests/Fixtures.
 *
 * Fixtures are real (trimmed) storefront markup captured once and committed.
 * They let the parser tests run with zero network access, which means the
 * suite is deterministic and still passes when a site is down or blocks us.
 *
 * Named fixtureHtml() rather than fixture() because Pest 5 already ships a
 * global fixture() helper, and redeclaring it is a fatal error.
 */
function fixtureHtml(string $name): string
{
    $path = __DIR__.'/Fixtures/'.$name;

    if (! is_file($path)) {
        throw new InvalidArgumentException("Fixture [{$name}] does not exist at {$path}.");
    }

    return (string) file_get_contents($path);
}
