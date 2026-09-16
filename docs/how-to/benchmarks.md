# Running the benchmarks

Three numbers the specification sets targets for, each with the commands that
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

Its `--latency` flag prints p50, p75, p90 and p99, and the redirect target is
p95 — a number those four only bracket, uselessly so when p90 is 22 ms and p99
is 89 ms. So the redirect runs mount `scripts/wrk-percentiles.lua`, six lines
that ask wrk for the percentile itself, read-only into the same throwaway
container. The report benchmark computes its own percentiles from its samples.

## The stack these runs need

Two of the three runs want the php container prod-like and the per-IP redirect
limiter raised — a load generator is one IP. `docker-compose.bench.yml` does
both; it is temporary and belongs to nobody's commit:

```yaml
services:
  php:
    environment:
      RATE_LIMIT_REDIRECT_PER_IP: "1000000"
      APP_ENV: "prod"
      APP_DEBUG: "0"
```

Seeding and measuring cannot share a stack — `app:demo:seed` refuses to run in
`prod` — so every recipe below says which state it needs, and these are the two
commands that switch:

```bash
docker compose up -d php                                                    # dev, for seeding
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d php  # prod-like, for measuring
```

The prod-like stack authenticates with its own keypair, and every environment's
is gitignored:

```bash
docker compose exec php sh -c 'APP_ENV=prod APP_DEBUG=0 bin/console lexik:jwt:generate-keypair --skip-if-exists'
```

When the runs are done, `rm docker-compose.bench.yml && docker compose up -d php`
puts the stack back into `dev`.

## 1. Redirect latency (NFR-PERF-1, target p95 ≤ 50 ms server time)

Seed and pick a slug, in `dev`:

```bash
docker compose up -d php
docker compose exec php bin/console app:demo:seed --clicks=50000 --reset
docker compose exec php bin/console dbal:run-sql 'SELECT slug FROM links ORDER BY click_count DESC LIMIT 1'
```

Then measure, prod-like. The slug below is the one that query printed on this
data (`bench`); substitute the one it prints on yours:

```bash
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d php
docker compose exec php sh -c 'APP_ENV=prod APP_DEBUG=0 bin/console cache:warmup'
docker run --rm --network linkboard_default -v "$PWD/scripts:/scripts:ro" williamyeh/wrk \
    -t1 -c1 -d20s --latency -s /scripts/wrk-percentiles.lua http://nginx/bench
```

```
  p50     20.35 ms
  p75     21.44 ms
  p90     22.25 ms
  p95     22.96 ms
  p99     26.27 ms
```

**p95 23.0 ms: the target is met.** For scale, the same run against `/health` —
no database, no Redis dispatch:

```bash
docker run --rm --network linkboard_default -v "$PWD/scripts:/scripts:ro" williamyeh/wrk \
    -t1 -c1 -d20s --latency -s /scripts/wrk-percentiles.lua http://nginx/health
```

answers p50 1.95 ms / p95 5.42 ms, so the redirect's own work is roughly 18 ms:
one indexed lookup, the rule matcher, and a dispatch to the Redis stream.

Four connections against a five-child pool:

```bash
docker run --rm --network linkboard_default -v "$PWD/scripts:/scripts:ro" williamyeh/wrk \
    -t4 -c4 -d20s --latency -s /scripts/wrk-percentiles.lua http://nginx/bench
```

move the distribution to p50 17.34 ms / p90 102.52 ms / p95 120.16 ms. That is
queueing, not server time, and it is what the number looks like when the pool is
the bottleneck — published here so nobody reads the single-connection figure as
a throughput claim.

## 2. Report latency (NFR-PERF-2, target p95 ≤ 300 ms uncached on 1 M clicks)

Seed a million clicks in `dev`, then measure prod-like:

```bash
docker compose up -d php
docker compose exec php bin/console app:demo:seed --clicks=1000000 --days=60 --reset
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d php
docker compose exec php sh -c 'APP_ENV=prod APP_DEBUG=0 bin/console cache:warmup'
docker compose exec php sh scripts/report-benchmark.sh \
    demo@example.com '<demo password>' admin@example.com '<admin password>'
```

The seed prints both passwords once; both accounts are needed, because the
three global reports require `ROLE_ADMIN` and answer 403 for the owner account.
`scripts/report-benchmark.sh` is the recipe, not a summary of one: it
authenticates, selects the account's most-clicked link from the collection's
`items` by click count — printing which link it chose and how many clicks it
holds, so a wrong one cannot hide in the table — derives a 30-day period, and
for each of the nine reports issues 20 requests, clearing `cache.reports`
before every single one so that every sample is a cache miss. A response that
is not 200 fails the run rather than being timed. It runs inside the `php`
container, which is why it addresses `http://nginx` — the service name on the
compose network.

Twenty samples rather than fifteen for a reason: with nearest-rank percentiles
over 15 samples, the 95th *is* the maximum, so a p95 column would just repeat
the worst sample.

`--clicks=1000000` writes 1 020 279 rows: the seed spreads a requested total
over its links and rounds each share up, and the figure is identical after
every `--reset`. The link it selected holds 220 000 of them.

```
link=01a0a92e-5be4-756f-8e24-f817ab4f10e6 (220000 clicks, the account's most-clicked)  period=2026-08-18T00:00:00Z..2026-09-17T00:00:00Z  samples=20  clicks in table=1020279
report                  p50      p95      max
link/summary         260.8   282.2   321.7
link/timeseries      138.3   165.9   166.5
link/countries       157.7   171.3   174.3
link/devices         312.8   324.3   324.9
link/referrers       165.8   194.8   199.2
link/variants         50.0    56.4    58.5
admin/summary         75.0    81.6    90.0
admin/timeseries     186.6   192.2   200.6
admin/top-links      281.0   299.2   323.3
```

**Eight of the nine meet the target. `link/devices` does not:** p95 324.3 ms
against 300 ms. Running the same command again gives 325.3 ms, and a run before
it 339.8 ms, so the miss is consistent rather than noise — it is the report that
groups a single link's 220 000 clicks by device *and* by operating system over
the period, the heaviest of the per-link reports.

`admin/top-links` sits on the line: 299.2 ms here, 294.8 ms and 298.1 ms in the
other two runs. It is published as met, with the number, because that is what it
measured — and it is the one to watch, since it ranks every link in the service
by clicks in the period, so it grows with the service rather than with a link.

What to do about either is a separate decision with its own evidence: the
specification already names the `click_daily` aggregate as the answer if report
latency fails its target (stretch, section 9). Nothing here was tuned to make a
number look better.

Two defects of this recipe are worth naming, because the numbers moved when
they were fixed: the commands published before it could not be executed at all,
and the script then selected the *last* link in the collection rather than the
most-clicked one — so the per-link reports had been timed against a link with
almost no clicks.

## 3. Worker throughput (NFR-PERF-3, target ≥ 500 clicks/s, 10 000 in ≤ 20 s)

With no worker running, redirects only queue their messages; the drain is then
timed exactly by consuming a fixed count. The load run sends twelve thousand
requests from one IP, so it needs the prod-like stack section 2 left in place.
Deleting the stream first is what makes `xlen` the count this run produced — it
discards whatever was queued and not yet consumed:

```bash
docker compose exec php bin/console dbal:run-sql 'SELECT slug FROM links ORDER BY click_count DESC LIMIT 1'
docker compose exec redis redis-cli del messages
docker run --rm --network linkboard_default williamyeh/wrk -t4 -c8 -d90s http://nginx/app-download
docker compose exec redis redis-cli xlen messages     # 12176
docker compose exec php sh -c 'time bin/console messenger:consume async --limit=10000 --no-interaction'
```

```
real	0m 11.65s
```

**10 000 messages in 11.65 s → 858 messages/s: the target is met**, and the
click rows appear in the table as they are consumed. The redirect answered all
12 169 requests of the load run while none of them had been persisted yet, which
is the decoupling the number is there to demonstrate.

Then `rm docker-compose.bench.yml && docker compose up -d php`.

## Reading these numbers honestly

- One machine, one run each, with the commands printed above it, and all three
  against the same prod-like stack — the seeding steps are the only ones in
  `dev`, because the seed refuses to run in `prod`.
- The report benchmark clears the cache before each request on purpose. In
  normal use a report is served from Redis for 300 seconds and answers in
  single-digit milliseconds; the numbers above are the cost of the miss.
- A target that is missed is published as missed. There is exactly one, and one
  more sits within five milliseconds of its target.
