# Running the benchmarks

Three numbers the specification sets targets for, each with the command that
produced it. They are measured on one machine against the local Docker stack —
evidence that the design holds its shape, not a claim about production.

**The machine these numbers came from:** Linux 7.1.13 x86_64, 24 cores, 62 GiB
RAM, Docker 29.8.0; PostgreSQL 16 and Redis 7 in the compose stack; PHP-FPM with
`pm.max_children = 5`.

`wrk` is not installed anywhere — it runs from a throwaway container on the
compose network, so the repository carries no benchmarking tool:

```bash
docker run --rm --network linkboard_default williamyeh/wrk --version
```

## 1. Redirect latency (NFR-PERF-1, target p95 ≤ 50 ms server time)

The redirect limiter is per client IP and a load generator is one IP, so the
run raises it for its duration. Create `docker-compose.bench.yml` — it is
temporary and belongs to nobody's commit:

```yaml
services:
  php:
    environment:
      RATE_LIMIT_REDIRECT_PER_IP: "1000000"
      APP_ENV: "prod"
      APP_DEBUG: "0"
```

```bash
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d php
docker compose exec php sh -c 'APP_ENV=prod APP_DEBUG=0 bin/console cache:warmup'
docker run --rm --network linkboard_default williamyeh/wrk -t1 -c1 -d20s --latency http://nginx/<slug>
```

Measured, prod-like, one connection — this is the server time the target is
about:

```
  Latency Distribution
     50%   20.39ms
     75%   21.34ms
     90%   21.95ms
     99%   25.62ms
```

**p95 ≈ 22 ms: the target is met.** For scale, `/health` — no database, no
Redis dispatch — is 2.64 ms at the same concurrency, so the redirect's own work
is roughly 18 ms: one indexed lookup, the rule matcher, and a dispatch to the
Redis stream.

At four concurrent connections against a five-child pool the distribution moves
to p50 17 ms / p90 98 ms / p99 152 ms. That is queueing, not server time, and it
is what the number looks like when the pool is the bottleneck — published here
so nobody reads the single-connection figure as a throughput claim.

Afterwards: `rm docker-compose.bench.yml && docker compose up -d php`.

## 2. Report latency (NFR-PERF-2, target p95 ≤ 300 ms uncached on 1 M clicks)

```bash
docker compose exec php bin/console app:demo:seed --clicks=1000000 --days=60 --reset
```

That produced 1 000 005 click rows in 13 s. Each report is then requested 15
times with the report cache cleared before every request, so every request is a
miss:

```bash
docker compose exec php bin/console cache:pool:clear cache.reports
curl -sS -o /dev/null -w '%{time_total}' -H 'Accept: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  "http://nginx/api/v1/links/$LINK/stats/summary?from=$FROM&to=$TO"
```

Measured over a 30-day period (n = 15 per report):

| Report | p50 | p95 |
|---|---|---|
| link/summary | 86.5 ms | 96.6 ms |
| link/timeseries | 59.0 ms | 65.7 ms |
| link/countries | 61.7 ms | 74.3 ms |
| link/devices | 88.7 ms | 96.5 ms |
| link/referrers | 59.8 ms | 78.4 ms |
| link/variants | 37.7 ms | 43.4 ms |
| admin/summary | 69.1 ms | 73.7 ms |
| admin/timeseries | 161.8 ms | 169.3 ms |
| **admin/top-links** | **284.5 ms** | **303.0 ms** |

**Eight of the nine meet the target with room. `admin/top-links` does not:** its
p95 is 303 ms against a 300 ms target. Re-measured prod-like it is p50 270 ms,
p95 309 ms — 287 ms if the cold first request is excluded. The miss is small,
consistent and real, and it is the report that ranks every link in the service
by clicks in the period, so it is the one that grows with the service rather
than with a link.

What to do about it is a separate decision with its own evidence: the
specification already names the `click_daily` aggregate as the answer if report
latency fails its target (stretch, section 9). Nothing here was tuned to make a
number look better.

## 3. Worker throughput (NFR-PERF-3, target ≥ 500 clicks/s, 10 000 in ≤ 20 s)

With the worker stopped, redirects queue their messages; the drain is then
timed exactly by consuming a fixed count:

```bash
docker run --rm --network linkboard_default williamyeh/wrk -t4 -c8 -d90s http://nginx/<slug>
docker compose exec redis redis-cli xlen messages     # 10583
docker compose exec php sh -c 'time bin/console messenger:consume async --limit=10000 --no-interaction'
```

```
real	0m 15.55s
```

**10 000 messages in 15.55 s → 643 messages/s: the target is met**, and the
click rows appear in the table as they are consumed. The redirect answered all
10 583 requests while none of them had been persisted yet, which is the
decoupling the number is there to demonstrate.

## Reading these numbers honestly

- One machine, one run each, with the command printed above it. Different
  hardware moves every figure.
- The report benchmark clears the cache before each request on purpose. In
  normal use a report is served from Redis for 300 seconds and answers in
  single-digit milliseconds; the numbers above are the cost of the miss.
- A target that is missed is published as missed. There is exactly one.
