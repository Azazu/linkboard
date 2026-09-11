# Proposal — add-analytics-read-model

**Risk-Tier:** medium

Tier rationale (roadmap row 8; AGENTS.md project triggers: "analytics queries" → `medium`): no firewall, voter or API-key change — the report operations reuse the `LINK_VIEW` attribute of the existing `LinkVoter` and the `ROLE_ADMIN` net already on `/api/v1/admin/`; no migration; no new dependency; the only untrusted input is five query parameters from authenticated callers, validated by declarative constraints and bound as SQL parameters, never interpolated. The demo seed command creates accounts with generated passwords through the configured hasher — it is flagged as security-relevant in its commit body, not a change to authentication. Gate 2 only; the user may raise the tier.

## Why

Every redirect now leaves a click row in `clicks`, written by the worker (change 7), and the API exposes nothing of it beyond `clickCount`. The brief's analytics (§2.6, FR-ANL-1…5) is the read side of the CQRS-lite split the stack was chosen for: reports computed by PostgreSQL window functions, never by PHP loops, served through a tag-aware Redis cache and invalidated when the link changes. It is also what the web UI (row 11) will chart and what a reviewer sees first on a dashboard — which is why the demo seed (`app:demo:seed`, NFR-DOC-2) ships in the same change: without data, neither the reports nor the screenshots mean anything.

## What Changes

1. **Six per-link reports** (FR-ANL-2) under `GET /api/v1/links/{id}/stats/{summary,timeseries,countries,devices,referrers,variants}` for the owner or an admin (403 for a stranger, 401 anonymous, 404 unknown id — the same boundary as the link itself). Every report takes a half-open UTC period `from`/`to` (default: the current UTC day and the 29 days before it; at most 366 days), `includeBots` (default false — bots are stored but excluded, FR-CLK-6), `timeseries` takes `granularity` `hour`|`day` (`hour` only for periods of at most 14 days) and `countries`/`referrers` take `limit` (default 10, at most 50). Invalid or inconsistent parameters answer 422 problem details with one violation per parameter. All numbers come from SQL: `count`, `count(distinct visitor_hash)`, `date_trunc` + `generate_series` with a left join for zero-filled buckets, `sum() over (order by bucket)` for running totals, `sum() over ()` for shares, `rank() over (order by count desc)`, `lag()` for the period-over-period delta. Every report carries `generatedAt` so the UI can show how stale it is.
2. **Read model in `src/Analytics/`** (FR-ANL-1): DBAL query services returning immutable DTOs; no `Click` or `Link` entity, no ORM, is referenced from that directory — an architecture test asserts it (NFR-QA-2).
3. **Tag-aware Redis cache** (FR-ANL-3): one pool on `REDIS_URL` with TTL 300 s; every per-link entry is tagged `link-{id}`, every admin entry `global`; the key names the report and every parameter. `PATCH` and `DELETE` on a link invalidate `link-{id}` after the flush (`DELETE` also `global`, because the top-links report names links); a cache failure never fails the write and never fails a report — the report is computed from PostgreSQL and the failure is logged.
4. **Admin global statistics** (FR-ANL-4): `GET /api/v1/admin/stats/summary` (totals of users, links and clicks, clicks today), `/admin/stats/timeseries` (clicks per bucket over all links, same parameters as the per-link one) and `/admin/stats/top-links` (top N links by clicks in the period with slug, owner id, clicks and unique visitors) — admin only, same mechanisms, tag `global`.
5. **`EXPLAIN`-verified queries** (NFR-PERF-2): every per-link report is run with `EXPLAIN (ANALYZE, BUFFERS)` against a dev database seeded with one million clicks; the plans (index used, timing) are pasted into `design.md`, and the uncached 30-day timings are recorded against the 300 ms target.
6. **`app:demo:seed`** (NFR-DOC-2): two accounts (a user and an admin) with passwords generated per run and printed once — never a password in code — ten links with routing rules and variants, and 50 000 synthetic clicks over 60 days generated in SQL with realistic distributions (countries, devices, OS, browsers, referrers, variants, a bot share, repeat visitors), `links.click_count` set to match; `--clicks`, `--days` and `--reset` options; refuses to run in `prod` and refuses to run twice without `--reset`.
7. **Docs**: an Analytics section in the how-to (endpoints, parameters, curl examples, cache staleness and invalidation, Redis-down behaviour, the demo seed), the command in `docs/reference/commands.md`, FR-ANL-2/3/4 in the brief refined where this proposal fixes what the brief left open (default period alignment, summary semantics, the `global` tag's invalidation, variants of unresolved clicks).

## Capabilities

### New Capabilities

- `analytics`: the per-link reports (parameters, period rules, contents of each of the six reports, bot exclusion, UTC bucketing and gap filling), their authorization boundary, the report cache (key, TTL, tags, invalidation, degradation when Redis is unavailable), the read-model rule (SQL, no entities) and the admin global statistics.
- `demo-data`: the `app:demo:seed` console command — what it creates, its options, its guards (environment, re-run), the printed credentials and the consistency of what it writes (`click_count` equals the seeded rows).

### Modified Capabilities

- `links`: "Update a link" — a successful `PATCH` (including deactivation and reactivation) invalidates the link's cached reports; "Delete a link" — deletion invalidates the link's cached reports and the global statistics, and the reports of a deleted link answer 404.

## Non-goals

- The web UI's charts, period pickers and the "updated up to 5 minutes ago" text (`add-web-ui`, row 11) — this change provides `generatedAt` and the API the UI will call.
- Benchmarks in the README and load numbers for the redirect and the worker (`harden-quality-and-docs`, row 13); the 1 M-click `EXPLAIN` measurement of this change lives in `design.md`.
- A `click_daily` aggregate, materialized views, partitioning or retention of `clicks` (stretch); new indexes on `clicks` unless the `EXPLAIN` measurement shows a report missing the 300 ms target — the admin timeseries over all links has no `link_id` filter and is measured, not pre-optimised.
- CSV or file exports, real-time (WebSocket/Mercure) updates, public statistics pages (`/{slug}+`), per-user statistics for admins, a `resolvedBy` breakdown, statistics per rule.
- Unique-visitor continuity across a `VISITOR_HASH_SALT` rotation (documented as broken by design in the click-logging capability).
- Doctrine result caching, HTTP caching headers on report responses (`Cache-Control` stays API Platform's default), and cache warming.
- Seeding in `prod` (the command refuses; the public demo instance of `stretch-public-hosting` decides its own seeding), realistic user agents or IPs in the seed (only the finished click facts are written, like the worker does).

## Impact

- New: `src/Analytics/` (period and granularity value objects, report request, DTOs per report, DBAL query services, `ReportCache`, API resources and state providers for the six link reports and the three admin reports), `src/Shared/Demo/` (the seed command and its dataset), `config/packages/cache.yaml` (the `cache.reports` tag-aware Redis pool), tests under `tests/Unit/Analytics/`, `tests/Integration/Analytics/`, `tests/Api/Analytics/`, `tests/Integration/Shared/`, a click-rows fixture helper.
- Modified: `src/Link/Api/UpdateLinkProcessor.php` and `src/Link/Api/DeleteLinkProcessor.php` (cache invalidation after the flush, best effort), `docs/how-to/local-development.md`, `docs/reference/commands.md`, `docs/explanation/requirements.md` (FR-ANL-2/3/4 wording), `openspec/ROADMAP.md` (row 8 removed at archive time).
- Unchanged: the `clicks` and `links` schema (no migration), the redirect and click write paths, the firewall and `access_control`, the `LinkVoter`; no new dependency — `symfony/cache` (the framework's `cache.adapter.redis_tag_aware`) and API Platform's `QueryParameter` validation are already installed.
