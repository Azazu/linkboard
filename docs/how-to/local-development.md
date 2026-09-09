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
| async worker (foreground) | `make worker` in a second terminal — needed from `add-async-click-logging` on; until then clicks are written synchronously (see Redirect) |
| async worker (background) | `docker compose --profile worker up -d` — the `worker` service is a compose profile, so `make up` does not start it unless asked |
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
`maxClicks` and `utm` can be cleared with `null`, the slug never changes.
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
# HTTP/1.1 302 Found · Location: <target with the link's UTM appended>
# Cache-Control: no-store · Referrer-Policy: no-referrer-when-downgrade
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8082/nothing-here   # 404
curl -s -H 'Accept: application/json' http://localhost:8082/nothing-here        # problem details
```

Unknown or inactive slug → 404; expired or click limit reached → 410 Gone;
otherwise 302 (never 301). Bodies are small HTML pages, or RFC 9457 problem
details for `Accept: application/json`. `HEAD` answers like `GET` but records
nothing. The click limit is exact under concurrency: check and increment are
one SQL statement, so a link with `maxClicks` 3 answers 302 exactly three times.

Every `GET` 302 writes one row into `clicks` and increments the link's
`clickCount` in the same transaction. The row stores a salted hash of the
visitor (`visitor_hash`) and the referer host — never the IP or the user
agent. The salt is `VISITOR_HASH_SALT`: the value in `.env` is a
local-development default; set the real one in `.env.local` (gitignored) or
the deployment's environment, never in the repository. Rotating it breaks
unique-visitor continuity: visitors before and after the rotation count as
different people.

Redirects are rate limited per client IP, `RATE_LIMIT_REDIRECT_PER_IP`
(default 60 per minute, sliding window, counter in Redis with the shared
lock); over the limit → 429 with `Retry-After`. This limiter is the only Redis
use on the redirect path and it fails **open**: while Redis is down redirects
are served unlimited and each request logs a warning.

**Baseline note (stage 1).** Until `add-async-click-logging` (roadmap row 7)
the click is written synchronously inside the redirect request — one SELECT,
one UPDATE and one INSERT — which deliberately contradicts FR-RED-2 ("no SQL
write on the hot path") for now. The failure contract is already the final
one: if the click cannot be written, a link without `maxClicks` still
redirects and logs an error; a link with `maxClicks` answers 503 with
`Retry-After: 5` because its limit cannot be guaranteed; if the link itself
cannot be looked up, every slug answers 503.

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
- **Redirects work but analytics stay empty** — from `add-async-click-logging`
  on: no worker is consuming the `async` transport; run `make worker`, or
  check `make console ARGS='messenger:stats'`. Before that change clicks land
  in `clicks` synchronously and no worker is involved.
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
- **`make check` fails in the token tests with a key error** — the keypairs
  are missing or were generated with another passphrase; run `make jwt-keys`
  (delete `config/jwt/test/` first if the passphrase in `.env.test` changed).
- **Tests boot the `dev` kernel** — the php container carries `APP_ENV=dev`
  in its real environment and `KernelTestCase` reads `$_ENV` first;
  `phpunit.dist.xml` forces both `$_SERVER` and `$_ENV` to `test`. Keep
  both lines if you edit it.
