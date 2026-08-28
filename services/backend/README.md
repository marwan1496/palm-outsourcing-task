# Backend — Laravel 13

The scraping engine, the REST API, and the MySQL schema.

## Setup

```bash
composer install
cp .env.example .env          # defaults point at plam_task, root, empty password
php artisan key:generate
php artisan migrate
php artisan api:token frontend    # token for the frontend — shown only once
php artisan serve                 # :8000
```

## The scraping module

Everything lives under `app/Scraping/`. It is the core of the project.

```
Contracts/     ProductParser · ProxyProvider · ScraperMiddleware
DTO/           ScrapedProduct              (readonly)
Middleware/    RotateUserAgent · RotateProxy · RetryWithBackoff
Parsers/       JumiaParser · AmazonParser · ParsesProductPages (shared helpers)
Pipeline/      ScraperPipeline (fetch) · ItemPipeline (validate → normalise → store)
Proxy/         GoProxyManagerProvider · DirectProxyProvider (null object)
Support/       UserAgentPool · UrlGuard
ScraperManager.php                         ← the entry point; start reading here
```

**`ScraperManager::scrape()` is five numbered steps** from a URL to a stored product. Both the
artisan command and the queued job call it and nothing else, so there is exactly one code path.

**The middleware chain is configuration** — see `config/scraping.php`, which is heavily commented
and doubles as the module's specification. Order matters: `RetryWithBackoff` is outermost, so a
retry re-enters proxy and user-agent rotation and therefore looks like a different visitor.

**Guzzle** is used through Laravel's `Http` facade, which is a Guzzle wrapper. `RotateProxy` passes
`['proxy' => …]` via `withOptions()` — a native Guzzle request option.

### Adding a storefront

Three changes, nothing else:

1. Add a case to `App\Enums\ProductSource` with its host patterns.
2. Write a class implementing `ProductParser` (copy `JumiaParser` as a template).
3. List it in `config/scraping.php` under `parsers`.

## Commands

```bash
php artisan products:scrape <url> [<url>...]   # scrape now
php artisan products:scrape <url> --queue      # queue instead
php artisan api:token <name>                   # issue a Sanctum token
php artisan queue:work                         # process queued scrapes
```

## Security

| Control | Where | Purpose |
|---|---|---|
| Sanctum tokens | `routes/api.php` | Every endpoint; no public routes |
| Rate limiting | `bootstrap/app.php` | 60/min reads, 10/min scrapes |
| **SSRF guard** | `app/Scraping/Support/UrlGuard.php` | The important one — see below |
| CORS | `config/cors.php` | One origin, never `*` |
| Security headers | `app/Http/Middleware/SecurityHeaders.php` | nosniff, frame-deny, CSP |
| Mass-assignment | `#[Fillable]` on `Product` | Explicit allowlist, never `$guarded = []` |

**`UrlGuard` is the control worth reading.** `POST /api/v1/scrape` makes the server fetch a
user-supplied URL, which without validation is a proxy into any network the server can reach. It
requires HTTPS, an allowlisted storefront, a normal port, no embedded credentials, and resolves the
hostname to reject private, loopback and link-local addresses. That last rule is the one that
matters — an allowlist alone fails when the attacker controls DNS.

## Caching

`GET /products` has three layers: the application cache (`Cache::flexible`, stale-while-revalidate),
an ETag for `304` responses, and `Cache-Control` for the browser. `X-Cache: HIT|MISS` on every
response makes it visible.

Invalidation uses a **version key**, not cache tags — tags are unsupported on the database driver.
See the comment at the top of `app/Support/ProductCache.php`.

## Tests

```bash
vendor/bin/pest                    # 291 tests
vendor/bin/phpstan analyse         # Larastan level 6, zero errors
vendor/bin/pint                    # formatting
```

Tests run against a separate `plam_task_test` MySQL database, so they never wipe demo data. Create
it once:

```sql
CREATE DATABASE plam_task_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Full detail in [../../docs/testing.md](../../docs/testing.md).

## Notes

- Laravel 13's slim skeleton has **no `app/Http/Kernel.php`** — middleware, rate limiters and
  exception rendering are all configured in `bootstrap/app.php`.
- Prices are integers in minor units. The API returns both `price` and `price_formatted`.
- `source_url` is unique, so re-scraping updates rather than duplicating.
