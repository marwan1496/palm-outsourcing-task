# Palm Task — Product Scraping Platform

Three services that scrape eCommerce product pages, store them in MySQL, serve them over a secured
JSON API, and render them in a self-refreshing grid.

```
┌─────────────────┐        ┌──────────────────┐        ┌──────────────────┐
│   Next.js 16    │        │   Laravel 13     │        │      Go 1.27     │
│                 │        │                  │        │                  │
│  /products      │        │  Scraping module │        │  proxy-manager   │
│  grid, 30s poll │        │  REST API        │        │  rotation +      │
│                 │        │  cache + auth    │        │  health checks   │
│  ┌───────────┐  │  HTTPS │                  │  HTTP  │                  │
│  │ BFF route │──┼───────▶│  /api/v1/*       │───────▶│  /v1/proxy/next  │
│  │ holds the │  │ Bearer │  auth:sanctum    │X-Proxy │  /v1/proxy/:id/  │
│  │   token   │  │  token │                  │  -Key  │       report     │
│  └───────────┘  │        └────────┬─────────┘        └──────────────────┘
└─────────────────┘                 │                            │
        ▲                           ▼                            ▼
        │                    ┌─────────────┐            ┌────────────────┐
   browser (no               │   MySQL     │            │  proxy pool    │
   credentials)              │  plam_task  │            │  (rotating)    │
                             └─────────────┘            └────────────────┘
```

## Why it is built this way

Three decisions carry most of the design. Each is explained in full in
[docs/architecture.md](docs/architecture.md).

**The browser never holds a credential.** The Laravel API requires a Sanctum token. If the browser
called it directly, that token would ship in the JavaScript bundle where anyone can read it. So the
browser calls a Next.js *Backend-for-Frontend* route, which runs on the server, reads the token from
a non-public env var, and calls Laravel. The token is never sent to the client — and there is a test
that fails if it ever is.

**The scraper cannot be used to attack our own network.** `POST /api/v1/scrape` takes a URL and
makes the server fetch it, which is a textbook SSRF hole. `UrlGuard` requires HTTPS, an allowlisted
storefront, a normal web port, no embedded credentials, and — the check that actually matters —
resolves the hostname and rejects any private, loopback, or link-local address. Without that last
rule, an allowlisted domain pointing at `169.254.169.254` would sail through.

**Losing the proxy service degrades anonymity, not availability.** If the Go service is down,
`GoProxyManagerProvider` returns "no proxy" and scraping continues over a direct connection. A
circuit breaker stops a dead dependency from adding its timeout to every request — measured: the
first three calls cost ~520 ms each, then the breaker opens and subsequent calls cost 0 ms.

## Stack

| Service | Version | Notes |
|---|---|---|
| Laravel | 13.29 | Slim skeleton — no `app/Http/Kernel.php`; middleware in `bootstrap/app.php` |
| PHP | 8.4 | Readonly classes, backed enums, `#[Fillable]` attribute |
| Guzzle | **8.1** | Via Laravel's `Http` facade, which wraps it |
| Symfony components | **8.1** | Preserved by *not* adding Roach PHP — see below |
| Next.js | 16.3 | App Router, React 19.2, Tailwind 4 |
| Go | 1.27 | Stdlib `net/http` routing, `log/slog`; one dependency (`yaml.v3`) |
| MySQL | 8.4 | Database `plam_task` |

### Why not Roach PHP or Panther

Both were evaluated for the scraping layer and rejected on evidence:

- **Roach PHP** — `roach-php/laravel` 3.2.0 supports `laravel/framework ^10||^11||^12`, so it does
  not support Laravel 13 at all. Its core pins `symfony/* ^7.0` and `guzzle ^7.8`, while Laravel 13
  accepts `symfony ^7.4||^8.0` and `guzzle ^7.8.2||^8.0`. Adding it would have pinned the whole
  application back to Symfony 7.4 and Guzzle 7. This project runs Symfony 8.1 and Guzzle 8.1.
- **Symfony Panther** — drives real Chrome over WebDriver and never uses Guzzle, contradicting the
  brief. Proxy and user-agent are launch-time Chrome flags, so per-request rotation would mean
  relaunching the browser each time.

Instead the pipeline borrows Roach's *architecture* — middleware chain, per-site parsers, item
pipeline — in about 200 lines built on Laravel's own `Pipeline` class, with no dependency cost.

## Setup

Requires PHP 8.4+, Composer, Node 20.9+, MySQL 8, and (optionally) Go 1.24+.

```bash
git clone <repo> && cd palm_outsorucing
```

**1. Database**

```sql
CREATE DATABASE plam_task      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE plam_task_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- for tests
```

**2. Backend**

```bash
cd services/backend
composer install
cp .env.example .env          # defaults already point at plam_task, root, empty password
php artisan key:generate
php artisan migrate
php artisan api:token frontend    # prints a token — copy it, it is shown only once
```

**3. Frontend**

```bash
cd services/frontend
npm install
cp .env.local.example .env.local
# paste the token into BACKEND_API_TOKEN
```

**4. Proxy manager** (optional — everything works without it)

```bash
cd services/proxy-manager
cp proxies.example.yaml proxies.yaml   # edit in your own proxies
go run ./cmd/proxyd
```

## Running

```powershell
.\scripts\dev.ps1              # starts all three in separate windows
.\scripts\dev.ps1 -SkipProxy   # without the Go service
.\scripts\seed-demo.ps1        # fill the grid with demo products
```

Or individually:

```bash
cd services/proxy-manager && go run ./cmd/proxyd      # :8081
cd services/backend       && php artisan serve        # :8000
cd services/frontend      && npm run dev              # :3000
```

Then open **http://localhost:3000/products**.

## Scraping a real page

```bash
cd services/backend
php artisan products:scrape https://www.jumia.com.eg/<some-product>.html
php artisan products:scrape <url> --queue     # via the queue instead
```

The product appears in the grid within 30 seconds, because the cache is invalidated on write.

## Tests

```powershell
.\scripts\test-all.ps1        # everything, plus Pint / Larastan / tsc
```

| Suite | Tests | Coverage |
|---|---|---|
| Laravel (Pest 5) | **291** | 604 assertions |
| Next.js (Vitest 4) | **65** | |
| Go | **53** (83 incl. subtests) | config 100%, pool 95.7%, httpapi 92.1% |

Static analysis: **Larastan level 6, zero errors**, no baseline and no suppressions. Pint clean.

Full details in [docs/testing.md](docs/testing.md).

## Documentation

| Document | What it covers |
|---|---|
| [docs/architecture.md](docs/architecture.md) | Request flow and every significant decision, with the reasoning |
| [docs/api.md](docs/api.md) | Endpoint reference with examples |
| [docs/testing.md](docs/testing.md) | How to run each suite, what is covered, what is not |
| [docs/presentation-guide.md](docs/presentation-guide.md) | Ordered code walkthrough for a live demo |
| [docs/voice-note-script.md](docs/voice-note-script.md) | Timed ~60-second summary script |

Each service also has its own README: [backend](services/backend), [frontend](services/frontend),
[proxy-manager](services/proxy-manager/README.md).

## Layout

```
services/
├── backend/           Laravel 13 — API, scraping module, caching, auth
│   └── app/Scraping/  the scraping module: contracts, middleware, parsers, pipeline
├── frontend/          Next.js 16 — /products page, BFF route
└── proxy-manager/     Go — proxy rotation and health checking
docs/                  architecture, API reference, testing, presentation notes
scripts/               dev.ps1, test-all.ps1, seed-demo.ps1
```
