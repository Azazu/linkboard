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
docker compose exec php bin/console dbal:run-sql -- 'ANALYZE clicks'
docker compose -f docker-compose.yml -f docker-compose.bench.yml up -d php
docker compose exec php sh -c 'APP_ENV=prod APP_DEBUG=0 bin/console cache:warmup'
docker compose exec php sh scripts/report-benchmark.sh \
    demo@example.com '<demo password>' admin@example.com '<admin password>'
```

The `ANALYZE` is not decoration. `clicks` is partitioned by month, so the
planner keeps statistics per partition, and a bulk load leaves them empty until
autovacuum gets round to it — measured: the same benchmark run immediately after
the seed reported the global top-links report at p95 **426.8 ms**, and 251.0 ms
once the statistics existed. Loading a partition and measuring it in the same
breath measures the planner's ignorance.

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
report                  p50      p95      max
link/summary         251.7   269.1   277.5
link/timeseries      134.9   167.2   172.5
link/countries       157.8   171.7   179.2
link/devices         303.9   316.8   331.6
link/referrers       166.0   171.8   172.3
link/variants         48.5    63.5    63.6
admin/summary         74.3    80.1    83.0
admin/timeseries     158.2   171.2   185.9
admin/top-links      277.4   300.0   303.8
```

**Eight of the nine meet the target. `link/devices` does not:** p95 316.8 ms
against 300 ms. It is the report that groups a single link's 220 000 clicks by
device *and* by operating system over the period, the heaviest of the per-link
reports, and it missed before the table was partitioned too (324.3 ms).

`admin/top-links` sits on the line at 300.0 ms and is the noisy one: three runs
of the same command gave 426.8 ms (unanalyzed), 251.0 ms and 300.0 ms. It ranks
every link in the service by clicks in the period, so it is the report that
grows with the service rather than with a link.

### What partitioning did to these numbers, measured rather than assumed

Very little, and the reason is worth stating. The table was partitioned by month
for **storage** — so that retention can drop a month instead of rewriting the
table ([ADR-006](../adr/ADR-006-clicks-partitioning-and-retention.md)) — and
partition pruning only pays when the table holds much more history than the
period being read. Here the whole dataset is 60 days: of seventeen partitions
only three hold rows, and a 30-day period touches two of them. There was almost
nothing to prune.

| report | before partitioning | after |
|---|---|---|
| link/summary | 282.2 | 269.1 |
| link/timeseries | 165.9 | 167.2 |
| link/countries | 171.3 | 171.7 |
| link/devices | **324.3** | **316.8** |
| link/referrers | 194.8 | 171.8 |
| link/variants | 56.4 | 63.5 |
| admin/summary | 81.6 | 80.1 |
| admin/timeseries | 192.2 | 171.2 |
| admin/top-links | 299.2 | 300.0 |

No report improved beyond its own run-to-run spread, and the one that missed
still misses. The answer to report latency is the one the specification already
named and this plan deliberately left out: the `click_daily` aggregate
(stretch, section 9). Nothing here was tuned to make a number look better, and
nothing here is claimed to have made one better.

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
