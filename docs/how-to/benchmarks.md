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

Seed first, in `dev` — `app:demo:seed` refuses to run in `prod` — and take a
slug to hammer:

```bash
docker compose exec php bin/console app:demo:seed --clicks=50000 --reset
docker compose exec php bin/console dbal:run-sql \
    'SELECT slug FROM links ORDER BY click_count DESC LIMIT 1'
```

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

Every command below substitutes the slug that query printed — `app-download`
in the seed's own data:

```bash
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d php
docker compose exec php sh -c 'APP_ENV=prod APP_DEBUG=0 bin/console cache:warmup'
docker run --rm --network linkboard_default williamyeh/wrk -t1 -c1 -d20s --latency http://nginx/app-download
```

Keep `docker-compose.bench.yml` until section 3 is done — its load run needs the
same raised limiter. Then `rm docker-compose.bench.yml && docker compose up -d
php`, which puts the stack back into `dev`.

Measured, prod-like, one connection — this is the server time the target is
about:

```
  Latency Distribution
     50%   20.46ms
     75%   21.43ms
     90%   22.11ms
     99%   25.26ms
```

**p95 ≈ 22 ms: the target is met.** For scale, `/health` — no database, no
Redis dispatch — at the same concurrency:

```bash
docker run --rm --network linkboard_default williamyeh/wrk -t1 -c1 -d20s --latency http://nginx/health
```

is p50 1.82 ms, so the redirect's own work is roughly 19 ms: one indexed lookup,
the rule matcher, and a dispatch to the Redis stream.

Against a five-child pool, four connections:

```bash
docker run --rm --network linkboard_default williamyeh/wrk -t4 -c4 -d20s --latency http://nginx/app-download
```

move the distribution to p50 17.4 ms / p90 97.3 ms / p99 134.7 ms. That is
queueing, not server time, and it is what the number looks like when the pool is
the bottleneck — published here so nobody reads the single-connection figure as
a throughput claim.

## 2. Report latency (NFR-PERF-2, target p95 ≤ 300 ms uncached on 1 M clicks)

```bash
docker compose exec php bin/console app:demo:seed --clicks=1000000 --days=60 --reset
docker compose exec php sh scripts/report-benchmark.sh \
    demo@example.com '<demo password>' admin@example.com '<admin password>'
```

The seed prints both passwords once; both accounts are needed, because the
three global reports require `ROLE_ADMIN`. `scripts/report-benchmark.sh` is the
recipe, not a summary of one: it authenticates, picks the account's most-clicked
link, derives a 30-day period, and for each of the nine reports issues 20
requests, clearing `cache.reports` before every single one so that every sample
is a cache miss. A response that is not 200 fails the run rather than being
timed. It runs inside the `php` container, which is why it addresses
`http://nginx` — the service name on the compose network.

Twenty samples rather than fifteen for a reason: with nearest-rank percentiles
over 15 samples, the 95th *is* the maximum, so a p95 column would just repeat
the worst sample.

Measured on 1 020 279 click rows (the seed's million plus the rows the worker
benchmark below persisted), 20 samples per report:

```
report                  p50      p95      max
link/summary           89.7    119.5    123.0
link/timeseries        60.3     69.2     73.2
link/countries         63.8     72.9     82.1
link/devices           93.7    117.9    126.9
link/referrers         62.9     74.6     82.6
link/variants          40.7     51.6     54.5
admin/summary          69.2     83.6     86.0
admin/timeseries      170.3    192.1    216.7
admin/top-links       292.6    321.9    327.6
```

**Eight of the nine meet the target. `admin/top-links` does not:** p95 321.9 ms
against 300 ms. The miss is consistent, not noise — earlier runs of the same
report measured 303 ms and, prod-like, 309 ms — and it is the one report that
ranks every link in the service by clicks in the period, so it grows with the
service rather than with a link.

What to do about it is a separate decision with its own evidence: the
specification already names the `click_daily` aggregate as the answer if report
latency fails its target (stretch, section 9). Nothing here was tuned to make a
number look better.

## 3. Worker throughput (NFR-PERF-3, target ≥ 500 clicks/s, 10 000 in ≤ 20 s)

With no worker running, redirects only queue their messages; the drain is then
timed exactly by consuming a fixed count. The load run sends more than ten
thousand requests from one IP, so it needs `docker-compose.bench.yml` from
section 1 in place. Deleting the stream first is what makes `xlen` the count
this run produced — it discards whatever was queued and not yet consumed:

```bash
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d php
docker compose exec redis redis-cli del messages
docker run --rm --network linkboard_default williamyeh/wrk -t4 -c8 -d90s http://nginx/app-download
docker compose exec redis redis-cli xlen messages     # 11925
docker compose exec php sh -c 'time bin/console messenger:consume async --limit=10000 --no-interaction'
```

```
real	0m 11.09s
```

**10 000 messages in 11.09 s → 902 messages/s: the target is met**, and the
click rows appear in the table as they are consumed. The redirect answered all
11 917 requests of the load run while none of them had been persisted yet, which
is the decoupling the number is there to demonstrate.

## Reading these numbers honestly

- One machine, one run each, with the command printed above it. Different
  hardware moves every figure.
- The report benchmark clears the cache before each request on purpose. In
  normal use a report is served from Redis for 300 seconds and answers in
  single-digit milliseconds; the numbers above are the cost of the miss.
- A target that is missed is published as missed. There is exactly one.
