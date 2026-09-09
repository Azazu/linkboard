# How to run Linkboard locally

Prerequisites: Docker with Compose v2, `make`, Node 22 (for the OpenSpec
CLI: `npm install -g @fission-ai/openspec`). No host PHP is needed —
every PHP command runs in the `php` container.

## First run

```bash
make init                     # build, up, composer install, migrate, create the test database
```

`make init` finishes with `make test-db`, so `make test` works right after
the first run.

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
| async worker (foreground) | `make worker` in a second terminal (clicks are logged asynchronously) |
| async worker (background) | `docker compose --profile worker up -d` — the `worker` service is a compose profile, so `make up` does not start it unless asked |
| the gate floor | `make check` (php-cs-fixer + PHPStan level 8 + PHPUnit; suites `Unit`, `Integration`, `Api`) |
| one suite | `docker compose exec php vendor/bin/phpunit --testsuite Unit` (also `Integration`, `Api`) |

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
- **Redirects work but analytics stay empty** — no worker is consuming
  the `async` transport; run `make worker`, or check
  `make console ARGS='messenger:stats'`.
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
- **Tests boot the `dev` kernel** — the php container carries `APP_ENV=dev`
  in its real environment and `KernelTestCase` reads `$_ENV` first;
  `phpunit.dist.xml` forces both `$_SERVER` and `$_ENV` to `test`. Keep
  both lines if you edit it.
