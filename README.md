# Palm Task

Scrapes product pages from Jumia and Amazon, stores them in MySQL, serves them over a token-secured
API, and displays them in a Next.js grid that refreshes itself.

Three services: a Laravel API that does the scraping, a Next.js frontend, and a small Go service
that manages proxy rotation.

---

## What you need

| | Version | |
|---|---|---|
| PHP | 8.4+ | with `pdo_mysql`, `mbstring`, `curl`, `intl` |
| Composer | 2.x | |
| Node | 20.9+ | |
| MySQL | 8.x | |
| Go | 1.24+ | optional, only for the proxy service |

## Setup

**1. Create the databases.** The second is for tests, so running them never touches your data.

```sql
CREATE DATABASE plam_task      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE plam_task_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**2. Install everything.**

```bash
npm install
npm run setup
```

That installs both projects and runs the migrations. If your MySQL isn't `root` with an empty
password, edit `services/backend/.env` first.

**3. Create an API token.** The frontend needs one; no endpoint is public.

```bash
npm run token
```

Copy what it prints, then:

```bash
cd services/frontend
cp .env.local.example .env.local
```

and paste the token into `BACKEND_API_TOKEN`.

> That variable has no `NEXT_PUBLIC_` prefix, deliberately. Next.js only bundles `NEXT_PUBLIC_*`
> values into the browser, so without it the token stays on the server. Adding the prefix to "make
> it work" would publish your credential to every visitor.

**4. Add some data**, so the grid isn't empty on first load.

```bash
npm run seed
```

## Run it

```bash
npm run dev
```

One command, the same on Windows, macOS and Linux. It starts three things:

- the Laravel API on **:8000**
- a queue worker, which is what actually runs the scrapes
- the Next.js frontend on **:3000**

Use `npm run dev:all` to include the Go proxy service if you have Go. It's optional; without it,
scraping simply connects directly.

Then open **http://localhost:3000**.

---

## Seeing the data

Go to **http://localhost:3000/products**.

You'll see a grid of product cards with titles, prices and images. Top right, "Updated Ns ago"
counts up and resets every 30 seconds. That's the page polling for changes, which is the refresh
behaviour the brief asks for. One seeded product has no image on purpose, so the placeholder is
visible too.

## Testing it through the UI

Quickest way to watch the scraper work.

1. Open **http://localhost:3000/jobs**
2. Paste a couple of URLs into the box, one per line:

   ```
   https://www.jumia.com.eg/oxi-powder-fine-fragrance-8kg-1kg-45488685.html
   https://www.ebay.com/itm/123
   ```

   > Product listings come and go. If that first one 404s by the time you read this, open
   > [jumia.com.eg](https://www.jumia.com.eg), click any product, and copy its URL instead.

3. Hit **Scrape**.

The first is queued. The second is rejected straight away with the reason underneath, because eBay
isn't a storefront this supports. One bad URL in a list shouldn't throw away the good ones, so each
is judged on its own.

Watch the row go **pending → running → completed**, usually inside fifteen seconds. When it lands
you'll see the product title and price, and it turns up on the products page as well.

Try pasting `https://169.254.169.254/latest/meta-data/` too. It's refused before any request is
made. That address is the cloud metadata endpoint, and an API that fetches arbitrary URLs is a route
into a private network if nothing is guarding it.

### If a job fails

Jumia sits behind Cloudflare, which sometimes decides a request looks automated and serves a
challenge instead of the page. Failed rows show why, and offer a **Retry** button.

Blocked pages are also retried automatically through a real browser, which usually clears the
challenge. That's enabled in the local `.env` and off in `.env.example`, because it needs Chrome
plus a matching driver:

```bash
cd services/backend
vendor/bin/bdi detect drivers   # downloads a chromedriver matching your Chrome
```

Parsing is covered separately by saved HTML fixtures, so the test suite proves it works whether or
not a site is cooperating on the day.

## Testing it through the API

Everything below needs the token from step 3.

```bash
TOKEN="paste-your-token-here"
```

**List products.** Watch the `X-Cache` header: `MISS` first time, `HIT` after.

```bash
curl -i -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8000/api/v1/products
```

**Without a token** you get `401`, not data:

```bash
curl -i http://127.0.0.1:8000/api/v1/products
```

**Queue a batch.** Up to ten URLs; the reply lists what was accepted and what wasn't.

```bash
curl -X POST http://127.0.0.1:8000/api/v1/scrape \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"urls":[
        "https://www.jumia.com.eg/oxi-powder-fine-fragrance-8kg-1kg-45488685.html",
        "https://www.ebay.com/itm/1"
      ]}'
```

That returns `202` with one job queued, one rejected, and a `batch_id`.

**Check on them:**

```bash
curl -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8000/api/v1/scrape-jobs
curl -H "Authorization: Bearer $TOKEN" "http://127.0.0.1:8000/api/v1/scrape-jobs?status=failed"
```

**Retry a failed one:**

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8000/api/v1/scrape-jobs/1/retry
```

**Try an internal address.** Returns `422`, queues nothing:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/scrape \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"url":"https://169.254.169.254/latest/meta-data/"}'
```

Every endpoint and status code is listed in [docs/api.md](docs/api.md).

## Running the tests

```bash
npm run test      # backend and frontend
npm run test:all  # adds the Go service
npm run lint      # Pint, Larastan level 6, TypeScript
```

Expect **369 backend** and **96 frontend** tests passing, plus 53 in Go. Larastan runs at level 6
with no baseline and nothing suppressed.

---

## Two things worth knowing

**The proxy pool is empty by default**, so scraping connects directly. Real rotating proxies cost
money. The rotation, health checking and failover are all built and tested, and you can watch them
work using local stand-ins:

```bash
npm run proxy:demo
```

That starts three fake proxies, one deliberately broken, plus the manager. Requests are handed out
in proportion to weight, and the broken one is benched after three failures. Walkthrough in
[docs/testing.md](docs/testing.md).

**Live scraping depends on the sites.** Cloudflare's decisions change day to day. If a live scrape
fails during a demo that's the internet, not the code, and it's exactly why the parsers are tested
against committed HTML fixtures instead.

## Where to read more

| | |
|---|---|
| [docs/architecture.md](docs/architecture.md) | How it fits together, and why each significant decision was made |
| [docs/api.md](docs/api.md) | Every endpoint, parameter and status code |
| [docs/testing.md](docs/testing.md) | What's covered, how to run each suite, and what isn't |
| [docs/presentation-guide.md](docs/presentation-guide.md) | A walkthrough of the code, in order |
| [docs/voice-note-script.md](docs/voice-note-script.md) | The one-minute summary |

## Layout

```
services/backend/        Laravel 13 — API, scraping, caching, auth
services/frontend/       Next.js 16 — products grid, jobs screen
services/proxy-manager/  Go — proxy rotation and health checks
docs/                    architecture, API reference, testing notes
```
