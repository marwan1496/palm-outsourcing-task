# proxy-manager

A small Go microservice that decides **which proxy the Laravel scraper should use next**, and
remembers how each proxy has been performing.

## Why this is a separate service

Proxy health is *shared, mutable state that outlives a single request*. A PHP process starts and
dies with each request, so it has nowhere to keep "proxy #3 has failed four times in a row" — every
Laravel worker would have to rediscover that independently, and every worker would keep paying for
the same dead proxy.

A long-lived Go process solves this directly: one pool, shared by every worker, updated
continuously by a background health checker. Go is a natural fit because the work is concurrent
(probing every proxy at once) and the service must stay cheap enough to sit on the hot path of
every scrape.

## How it decides

Selection is **smooth weighted round-robin** — the algorithm nginx uses for upstream balancing.
Each candidate accumulates its effective weight, the highest scorer is picked, and the winner then
pays back the total. That spreads picks evenly (`A B A C A B`) instead of draining one proxy's
weight before moving on (`A A A B C`).

Two things adjust a proxy's standing:

| Signal | Effect |
|---|---|
| Recent success rate (last 20 outcomes) | Scales its effective weight, floored at 1 so it always gets a chance to recover |
| N consecutive failures (default 3) | Benched entirely for a cooldown window (default 60s) |

Any single success clears the failure streak *and* ends an active cooldown, so a proxy that comes
back to life returns to rotation immediately rather than serving out its sentence.

## API

Health endpoints are unauthenticated so an orchestrator can probe without holding the secret.
Everything that exposes a proxy URL sits behind the `X-Proxy-Key` header.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/healthz` | no | Liveness. Always 200 while the process runs. |
| `GET` | `/readyz` | no | Readiness. 503 when no proxy is available. |
| `GET` | `/v1/proxy/next` | yes | The proxy to use for the next scrape. **503 is normal** — see below. |
| `POST` | `/v1/proxy/{id}/report` | yes | Report how a scrape went: `{"success":true,"latency_ms":320}` |
| `GET` | `/v1/proxies` | yes | Pool statistics, for dashboards and demos. |

**`503` from `/v1/proxy/next` is a contract, not an outage.** It means "no healthy proxy right
now", and Laravel reads it as *scrape directly instead*. This is why stopping this service entirely
does not break scraping — it only removes proxy rotation.

## Configuration

The proxy list lives in `proxies.yaml` (gitignored, since proxy URLs usually embed credentials).
Copy the example to get started:

```bash
cp proxies.example.yaml proxies.yaml
```

Everything else comes from the environment:

| Variable | Default | Purpose |
|---|---|---|
| `PROXY_LISTEN_ADDR` | `:8081` | Listen address |
| `PROXY_API_KEY` | *(empty)* | Shared secret. **Empty disables auth** — dev only; the service logs a warning at startup |
| `PROXY_CONFIG` | `proxies.yaml` | Path to the proxy list |
| `PROXY_PROBE_URL` | `https://www.jumia.com.eg` | URL the health checker requests through each proxy |
| `PROXY_HEALTHCHECK_INTERVAL` | `30s` | How often to probe |
| `PROXY_HEALTHCHECK_TIMEOUT` | `5s` | Per-probe timeout |
| `PROXY_FAILURE_THRESHOLD` | `3` | Consecutive failures before benching |
| `PROXY_COOLDOWN` | `60s` | How long a benched proxy stays out |
| `PROXY_LOG_FORMAT` | `text` | Set to `json` for machine-readable logs |
| `PROXY_DEBUG` | *(empty)* | Set to anything for debug-level logging |

**The service starts fine with no proxies at all.** Every `/v1/proxy/next` returns 503 and Laravel
connects directly, so the whole stack runs locally without owning a single proxy.

## Running

```bash
go run ./cmd/proxyd          # or: make run
make build                   # binary into bin/
```

## Tests

```bash
make test                    # go test -cover ./...
make cover                   # HTML coverage report
```

Current coverage: **config 100%**, **pool 95.7%**, **httpapi 92.1%**. `cmd/proxyd` is wiring only
and is covered by running the service, not by unit tests.

> `make test-race` needs `CGO_ENABLED=1` and a C compiler. On a stock Windows install without
> mingw/gcc, `go test -race` fails with *"-race requires cgo"* — use plain `make test` there. The
> pool's concurrency test still exercises the mutex; the race detector just makes it stricter.

## Layout

```
cmd/proxyd/main.go        Entrypoint: config, wiring, graceful shutdown
internal/config/          YAML + environment loading, validation
internal/pool/pool.go     Selection algorithm and health bookkeeping
internal/pool/health.go   Background prober
internal/httpapi/         Router, handlers, shared-secret auth
```

Only one third-party dependency (`gopkg.in/yaml.v3`). Routing uses the standard library's
method-aware patterns from Go 1.22 (`mux.HandleFunc("GET /v1/proxy/next", …)`), so no chi, gorilla,
or gin is needed.
