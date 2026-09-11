# How to run Linkboard locally

Prerequisites: Docker with Compose v2, `make`, Node 22 (for the OpenSpec
CLI: `npm install -g @fission-ai/openspec`). No host PHP is needed —
every PHP command runs in the `php` container.

## First run

```bash
make init                     # build, up, composer install, migrate, create the test database
```

`make init` also generates the dev and test JWT keypairs (`make jwt-keys`,
`config/jwt/<env>/`, gitignored) and creates the test database
(`make test-db`), so `make test` works right after the first run.

The php image runs as `HOST_UID`/`HOST_GID` from `.env` (default 1000)
so bind-mounted `var/` and `vendor/` stay owned by you. If your ids
differ, pass them from the shell — Compose does not read `.env.local`:

```bash
HOST_UID=$(id -u) HOST_GID=$(id -g) make up
```

(The names are not `UID`/`GID`: Bash reserves those as read-only.)

`.env` is committed (Symfony convention) with local defaults. If ports
8082/5434/6382 are taken or you need other values, put the overrides in
`.env.local` (gitignored) — never edit secrets into `.env`.

Health: http://localhost:8082/health (`?deep=1` also probes PostgreSQL and
Redis) · API docs (Swagger UI): http://localhost:8082/api/docs · OpenAPI
JSON: http://localhost:8082/api/docs.json · API base path:
http://localhost:8082/api/v1 · PostgreSQL: `127.0.0.1:5434` · Redis:
`127.0.0.1:6382`.

## Daily

| Task | Command |
|---|---|
| start / stop | `make up` / `make down` |
| logs, status | `make logs`, `make ps` |
| composer / console | `make composer ARGS='…'` / `make console ARGS='…'` |
| migrations | `make migration` (generate, then read it) → `make migrate` |
| test database | `make test-db` (create + migrate `<db>_test`; also run by `make init`) |
| JWT keys | `make jwt-keys` (dev + test keypairs, skips existing; also run by `make init`) |
| make an admin | `make console ARGS='app:user:promote you@example.com'` (`app:user:demote` reverts) — the only way roles change |
| async worker (foreground) | `make worker` in a second terminal — clicks land in `clicks` only while a worker consumes the `async` transport (see Redirect) |
| async worker (background) | `docker compose --profile worker up -d` — the `worker` service is a compose profile, so `make up` does not start it unless asked |
| demo data | `make console ARGS='app:demo:seed'` — two accounts (passwords printed once), ten links with rules, 50 000 clicks over 60 days; `--reset` to start over (see Analytics) |
| the gate floor | `make check` (php-cs-fixer + PHPStan level 8 + PHPUnit; suites `Unit`, `Integration`, `Api`, `Web`) |
| one suite | `docker compose exec php vendor/bin/phpunit --testsuite Unit` (also `Integration`, `Api`) |

## Accounts and the API

Register at http://localhost:8082/register (or `POST /api/v1/auth/register`),
then get a token and call the API:

```bash
curl -s -X POST http://localhost:8082/api/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"you@example.com","password":"your-password-here"}'
# → {"token":"…","expiresAt":"…"}  (valid for one hour, no refresh)
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8082/api/v1/me
```

In Swagger UI the same flow is `POST /api/v1/auth/token` → copy `token` →
**Authorize** (paste the bare token; the UI adds `Bearer`). The request
preview under each operation shows the `Authorization` header when it is
attached.

Create, list, change and delete links (the owner or an admin):

```bash
curl -s -X POST http://localhost:8082/api/v1/links -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"targetUrl":"https://example.com/landing","slug":"spring-sale","maxClicks":100}'
curl -s -H "Authorization: Bearer $TOKEN" 'http://localhost:8082/api/v1/links?isActive=true'
curl -s -X PATCH http://localhost:8082/api/v1/links/$ID -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/merge-patch+json' -d '{"isActive":false,"maxClicks":null}'
curl -s -X DELETE http://localhost:8082/api/v1/links/$ID -H "Authorization: Bearer $TOKEN"
```

A PATCH changes only the fields present in the body; `expiresAt`,
`maxClicks`, `utm` and `rules` can be cleared with `null`, the slug never changes.
Without `slug` a 7-character one is generated. In a shell, quote URLs with
`[` `]` or pass `-g` to curl (`order[createdAt]=asc`).

**Security notes on targets.** A target must be an absolute `http`/`https`
URL to a public host: `localhost`, loopback, link-local and private
addresses (v4 and v6, including IPv4-mapped v6) are rejected on write, so a
short link cannot be pointed at the cloud metadata service or an internal
host. The host is first mapped to ASCII the way browsers do it (UTS #46, so
fullwidth `１２７.０.０.１` becomes `127.0.0.1`, `。` becomes `.`, and `пример.рф` its punycode),
literal addresses are recognised in every spelling a browser accepts
(`127.1`, `2130706433`, `0x7f000001`, `0177.0.0.1`, trailing dot), and
percent-encoded or unmappable hosts, userinfo (`user@host`) and any
backslash or control character are rejected, so the check applies to the
address the visitor's browser will actually contact rather than to a
spelling PHP and the browser read differently. Hostnames are deliberately not
resolved at validation time: a public name that resolves to a private
address is the documented residual risk, which is why the server itself
never fetches a target — it only redirects.

Auth endpoints (`/login`, `/register`, `/api/v1/auth/*`) accept 10 requests
per minute per client IP (`RATE_LIMIT_AUTH_PER_IP` in `.env`); the counter
lives in Redis. The client IP comes from the trusted-proxy configuration
(`TRUSTED_PROXIES`, default `127.0.0.1` = the nginx container): a forwarded
header from any other peer is ignored, so do not widen it to a range you do
not control.

## Redirect

The short link itself is `GET /{slug}` — public, no session, no cookie:

```bash
curl -sI http://localhost:8082/spring-sale
# HTTP/1.1 302 Found · Location: <resolved destination with the link's UTM appended>
# Cache-Control: no-store · Referrer-Policy: no-referrer-when-downgrade
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8082/nothing-here   # 404
curl -s -H 'Accept: application/json' http://localhost:8082/nothing-here        # problem details
```

Unknown or inactive slug → 404; expired or click limit reached → 410 Gone;
otherwise 302 (never 301). Bodies are small HTML pages, or RFC 9457 problem
details for `Accept: application/json`. `HEAD` answers like `GET` but records
nothing. The click limit is exact under concurrency: check and increment are
one atomic Redis script (see **Click limit** below), so a link with `maxClicks`
3 answers 302 exactly three times.

Every `GET` 302 dispatches one `ClickRecorded` message to the `async`
transport (Redis Streams); the request itself runs one `SELECT` (the link),
for a link with `maxClicks` one Redis command (the click counter), and no SQL
write. A **worker** turns the message into one row in `clicks` and one
increment of the link's `clickCount`, in one transaction — so `clickCount` in
the API is eventually consistent and lags by the queue backlog:

```bash
make worker                                   # foreground consumer, Ctrl-C to stop
docker compose --profile worker up -d         # or as a background compose service
make console ARGS='messenger:stats'           # messages waiting on async / failed
```

The message carries the finished click facts and the salted visitor hash —
never the IP, the user agent or header values. Handling is idempotent: the
message's `click_id` is the row's primary key, so a redelivery is acknowledged
without a second row or increment. A message whose link was deleted in the
meantime is acknowledged and discarded with an `info` log line — never
retried, never parked. Any other failure is retried three times (1 s, 2 s,
4 s, no jitter) and then parked on the `failed` transport (table
`messenger_messages`, created — or adopted, rows kept — by a migration):

```bash
make console ARGS='messenger:failed:show'     # what is parked, and why
make console ARGS='messenger:failed:retry --force'   # replay everything parked (add an id to replay one)
```

**Click limit.** For a link with `maxClicks` the authority is the Redis
counter `link:{id}:clicks`, driven by one `EVAL` of a fixed Lua script per
redirect: it lifts an absent or lower key to the link's persisted `clickCount`
(never lowers it), compares with `maxClicks`, increments only when the redirect
is allowed. Exhausted → 410, nothing dispatched. The key has no TTL and is
removed with the link. Guarantee boundary: exact while the key exists;
whenever the key is seeded — first redirect of a limited link, a limit set
after an unlimited period, a Redis data loss — the seed is the count as the
seeding request read it, so it excludes the accepted redirects not persisted at
that read (queued or persisted since, dispatch-failed, parked); the limit may
be exceeded by at most that many, and requests in flight at a limit change
complete under the limit they loaded. `clicks` stays the exact record. Redis
runs with AOF in Compose to make key loss exceptional.

**When something is down.** Database unreachable → 503 for every slug (the
link cannot be looked up). Redis unreachable → 503 with `Retry-After: 5` for
links **with** `maxClicks` only (the limit cannot be guaranteed); unlimited
links never touch Redis and redirect normally. Transport unreachable → 302 for
every link and an `error` log line: the redirect never waits for or fails on
logging, only that click's record is lost. `HEAD` issues no counter command
and no message.

The visitor hash's salt is `VISITOR_HASH_SALT`: the value in `.env` is a
local-development default; set the real one in `.env.local` (gitignored) or
the deployment's environment, never in the repository. Rotating it breaks
unique-visitor continuity: visitors before and after the rotation count as
different people.

Redirects are rate limited per client IP, `RATE_LIMIT_REDIRECT_PER_IP`
(default 60 per minute, sliding window, counter in Redis with the shared
lock); over the limit → 429 with `Retry-After`. The limiter fails **open**:
while Redis is down redirects are served unlimited and each request logs a
warning.

## Routing rules

A link may carry a `rules` document (FR-RUL-2; JSON Schema in
`docs/reference/rules-schema.json`, the PHP validator is the authority and a
test keeps the two equal). Up to 20 rules with exactly one dimension each —
device (`device` and/or `os`), `country`, or `language` — and 2–4 A/B
variants whose integer weights sum to 100; every target passes the same URL
policy as `targetUrl`. Send it on `POST /api/v1/links` or replace it whole on
`PATCH` (`"rules": null` clears it):

```bash
curl -s -X POST http://localhost:8082/api/v1/links -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d @- <<'JSON'
{"targetUrl":"https://example.com/","slug":"routed","rules":
{
  "version": 1,
  "rules": [
    { "match": { "device": ["smartphone", "tablet"], "os": ["iOS"] }, "target": "https://apps.apple.com/app/id123" },
    { "match": { "os": ["Android"] }, "target": "https://play.google.com/store/apps/details?id=com.example" },
    { "match": { "country": ["DE", "AT", "CH"] }, "target": "https://example.de/" },
    { "match": { "language": ["uk", "ru"] }, "target": "https://example.com/ua/" }
  ],
  "variants": [
    { "name": "A", "weight": 50, "target": "https://example.com/landing-a" },
    { "name": "B", "weight": 50, "target": "https://example.com/landing-b" }
  ]
}
}
JSON
```

Invalid documents answer 422 with one violation per problem and its path, for
example `rules[rules][0][match]` or `rules[variants][1][weight]`. JSON types
are enforced as written: an object where an array belongs (even with keys
`"0"`, `"1"`) is a violation.

On redirect the destination is resolved in a fixed order: device rules →
country rules → language rules → A/B variants → `targetUrl`; the first match
wins and the click records `resolved_by` and `variant`. Try a device rule with
a user agent and a language rule with `Accept-Language`:

```bash
curl -sI -A 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' http://localhost:8082/routed | grep -i location
curl -sI -H 'Accept-Language: uk' http://localhost:8082/routed | grep -i location
curl -sI http://localhost:8082/routed | grep -i location   # variant A or B, sticky per IP + user agent
```

**Input policy** (FR-RUL-4/7). A header that is absent or well-formed but not
understood (an unknown user agent, `Accept-Language: *`, an IP no resolver
knows) leaves that dimension unresolved: its rules are skipped, nothing is
logged. A hostile header — `User-Agent` over 1024 bytes or with control
characters or invalid UTF-8, `Accept-Language` over 256 bytes or outside the
header grammar, an oversized client hint — is never parsed: the visitor gets
`targetUrl` with `resolved_by = default` and one `notice` names the link and
the issue classes (never the values). Any exception inside detection,
geolocation or evaluation degrades the same way. The click is recorded in
every case.

**Country resolution.** `COUNTRY_RESOLVERS` (default `header,geolite2`) names
the resolvers in order; an unknown name fails every boot of the application —
try it and watch the console refuse to start:

```bash
docker compose exec -T -e COUNTRY_RESOLVERS=bogus php bin/console about
```

- `header` reads the header named by `GEOIP_COUNTRY_HEADER` (default
  `CF-IPCountry`) **only when the request came through a trusted proxy**
  (`TRUSTED_PROXIES`); a client talking to the app directly cannot set its own
  country.
- `geolite2` reads the MaxMind GeoLite2 country database at
  `GEOIP_DATABASE_PATH` (default `var/geoip/GeoLite2-Country.mmdb`, gitignored,
  never committed). Download it with a free MaxMind account
  (<https://www.maxmind.com/en/geolite2/signup>), place it there and start a
  new process — the file is opened lazily once per process. While it is
  missing the resolver disables itself with one `warning` and the country is
  unknown; an address that is not in the database is unknown too.
- In the test environment `COUNTRY_RESOLVERS=fixed` uses a constant map
  (`tests/Fixture/FixedMapCountryResolver.php`).

Device, OS, browser and bot detection use `matomo/device-detector`; its regex
database is parsed once per deploy into the filesystem pool
`cache.device_detector`, so the first requests after `cache:clear` are slower.

## Analytics

Reports are the read side of the click pipeline (FR-ANL-1…5): every number
is computed by PostgreSQL over `clicks` — aggregates and window functions,
never PHP loops — and returned as an immutable report. Six reports per link,
for the owner or an admin (403 for anyone else, 401 anonymous, 404 for an
unknown id, exactly like the link itself):

| Report | Returns |
|---|---|
| `GET /api/v1/links/{id}/stats/summary` | all-time `totalClicks`, `uniqueVisitors`, `firstClickAt`, `lastClickAt`; `clicksToday` (UTC); `clicksInPeriod` vs `clicksInPreviousPeriod` (the same length before `from`) and `deltaPercent` (null when the previous period is empty) |
| `…/stats/timeseries` | one UTC bucket per `granularity` (`hour` for periods of at most 14 days, or `day`) with `clicks`, `uniqueVisitors`, `cumulativeClicks`; every bucket the period touches present, zeros where nothing happened; the first and last bucket are partial when `from`/`to` are not aligned to the granularity (only clicks inside the period count) |
| `…/stats/countries` | the `limit` countries with the most clicks, `share` (% of the period total, one decimal) and `rank` (ties share a rank); `country: null` groups unknown origins |
| `…/stats/devices` | `byDeviceType` and `byOs` breakdowns with shares; `null` groups what detection did not recognise |
| `…/stats/referrers` | the `limit` referrer hosts with share and rank; clicks without a referrer are the `direct` group |
| `…/stats/variants` | clicks, `uniqueVisitors` and share per A/B variant among the clicks a variant resolved (`total`); rule- and default-resolved clicks are not part of it |

Parameters, all optional: `from` and `to` (RFC 3339; the period is half-open,
`from` inclusive and `to` exclusive, evaluated in UTC, at most 366 days;
default: the current UTC day and the 29 before it — `to` is the start of the
next UTC day, so identical default requests share one cache entry all day;
`to` alone gives the 30 days before it, `from` alone runs up to the default `to`),
`includeBots` (`true`/`false`, default `false` — bots are stored but excluded),
`granularity` (timeseries), `limit` (1–50, default 10; countries and
referrers). A malformed, out-of-range or inconsistent parameter answers 422
problem details with one violation per parameter (`propertyPath` is the
parameter name). Every report echoes its effective parameters and carries
`generatedAt`, the UTC time its numbers were computed. Seed the instance and
try them:

```bash
make console ARGS='app:demo:seed'    # prints demo@example.com / admin@example.com with generated passwords
curl -s -X POST http://localhost:8082/api/v1/auth/token -H 'Content-Type: application/json' \
  -d '{"email":"demo@example.com","password":"<the printed password>"}'
# → {"token":"…"}; then, with ID = the id of one of the demo links (GET /api/v1/links):
curl -s -H "Authorization: Bearer $TOKEN" "http://localhost:8082/api/v1/links/$ID/stats/summary"
curl -s -H "Authorization: Bearer $TOKEN" "http://localhost:8082/api/v1/links/$ID/stats/timeseries?from=2026-09-01T00:00:00Z&to=2026-09-08T00:00:00Z&granularity=day"
curl -s -H "Authorization: Bearer $TOKEN" "http://localhost:8082/api/v1/links/$ID/stats/countries?limit=3&includeBots=true"
```

`app:demo:seed` creates two accounts, ten links with routing documents
(device, country and language rules, A/B variants, click limits, an expired
one, UTM sets) and 50 000 synthetic clicks over the last 60 days with
realistic distributions — several countries and an unknown share, consistent
device/OS/browser triples, referrer hosts and direct traffic, a 5 % bot share,
repeat visitors — in one transaction and one SQL statement per link (`--clicks`
and `--days` change the volume). The passwords are generated per run from a
CSPRNG, hashed like any account's and shown only once on the console; there is
no password in the repository, so if you lose them run
`make console ARGS='app:demo:seed --reset'`, which deletes the two accounts
(their links and clicks follow) and seeds anew with new passwords. The command
refuses to run twice without `--reset` and never runs in `prod`. The click
rows have the handler's shape — no raw IP or user agent anywhere.

**Cache.** Reports are served through the pool `cache.reports` — Redis, tag
aware (`RedisTagAwareAdapter`), TTL 300 s — keyed by the report and every
effective parameter and tagged `link-{id}` (link reports) or `global` (admin
reports). Two identical requests within the TTL return the same body including
`generatedAt`; clicks recorded in between become visible when the entry
expires — a staleness of at most five minutes, which the web UI will state. A
successful `PATCH` on a link (including deactivation) drops the link's
entries; a `DELETE` drops them and the global ones. The cache is never a
dependency: while Redis is down every report is computed from PostgreSQL and
answered 200, the adapter logs `Failed to fetch key … Connection refused` at
`warning`, and a `PATCH`/`DELETE` whose invalidation was refused still
succeeds with a `Report cache not invalidated` warning naming the link id.
`RedisTagAwareAdapter` requires Redis to run the `noeviction` or a `volatile-*`
`maxmemory-policy`; the compose Redis sets no `maxmemory`, so it is
`noeviction` — keep that when you point `REDIS_URL` elsewhere. Cache keys are
namespaced per environment (`framework.cache.prefix_seed`), so the test suite's
pool never touches the dev stack's entries.

**Admin statistics** (`ROLE_ADMIN` only): `GET /api/v1/admin/stats/summary`
(`totalUsers`, `totalLinks`, `activeLinks`, `totalClicks`, `clicksToday`; no
period — only `includeBots`), `/admin/stats/timeseries` (clicks and running
total per bucket over every link, same parameters as a link's timeseries, no
unique visitors) and `/admin/stats/top-links?limit=10` (the links with the
most clicks in the period with `slug`, `ownerId`, `clicks`, `uniqueVisitors`,
`rank`). The global timeseries carries no distinct-visitor count: over every
link's rows and every bucket that count is what puts it over the 300 ms
target (an accepted revision, see the change's proposal). Same cache, tag
`global`, invalidated when a link is deleted.

```bash
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" 'http://localhost:8082/api/v1/admin/stats/top-links?limit=3'
```

Per-link report queries are bounded by the link's indexes (the planner picks
the `(link_id)` index or the composite `(link_id, occurred_at)` one depending
on the window) and spend most of their time on `count(DISTINCT visitor_hash)`;
on one million seeded clicks every report — per-link and the admin ones over
every link — answers in under 300 ms uncached. Plans and
timings are recorded in the appendix of the change's design
(`openspec/changes/archive/*-add-analytics-read-model/design.md` after the
archive).

## Reset (DESTRUCTIVE)

`docker compose down --volumes` deletes both named volumes: `pg_data`
(PostgreSQL) and `redis_data` (Redis append-only file, which holds the
click-limit counters). There is no make target for it on purpose — type
it yourself.

## Troubleshooting

- **Port already in use** — change `APP_PORT` / `FORWARD_*_PORT` in `.env`.
- **A flex recipe appended variables to `.env`** — review them and commit
  them together with the recipe; a value that is a real secret belongs in
  `.env.local`, with a placeholder left in `.env`. `.env.test` is committed
  too and is what CI and PHPUnit read.
- **Compose does not see my `.env.local`** — Docker Compose reads only
  `.env`; for compose-level overrides (ports, project name) use
  `docker compose --env-file .env.local` or export them in the shell.
- **Redirects work but `clicks` stays empty and `clickCount` does not move** —
  no worker is consuming the `async` transport; run `make worker` (or the
  `worker` compose profile) and check `make console ARGS='messenger:stats'`.
  Messages that failed three times sit on `failed`: `messenger:failed:show`.
- **Permission denied on `var/`** — the php image must run as your ids;
  rebuild with `HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose build php`
  (see `.docker/php/Dockerfile`).
- **CI is red right after `/workflow:start`** — `openspec validate --all
  --strict` fails for a change that has no artifacts yet; it turns green
  with `/opsx:propose`. Known limitation, not a defect of your change.
- **`make check` differs from CI** — CI runs `make test-db EXEC=` and
  `make check EXEC=` natively with PHP 8.4; `composer.json` pins
  `config.platform.php` to the same version as the image.
- **Health tests fail with `database: fail` while everything else works** —
  the deep probe connects to the database named in `DATABASE_URL` as is
  (no `_test` suffix; it checks the configured dependency, like in prod),
  so that database must exist wherever the tests run. CI creates `app`
  as the service database and `make test-db` derives `app_test` from it.
- **`make check` fails in the token tests with a key error**, or the dev
  stack answers `POST /api/v1/auth/token` with a 500 "private key/passphrase" —
  the keypairs are missing or were generated with another passphrase; run
  `make jwt-keys` (delete `config/jwt/test/` or `config/jwt/dev/` first if the
  passphrase in `.env.test` / `.env` changed).
- **Tests boot the `dev` kernel** — the php container carries `APP_ENV=dev`
  in its real environment and `KernelTestCase` reads `$_ENV` first;
  `phpunit.dist.xml` forces both `$_SERVER` and `$_ENV` to `test`. Keep
  both lines if you edit it.
