# How to run Linkboard locally

Prerequisites: Docker with Compose v2, `make`, Node 22 (for the OpenSpec
CLI: `npm install -g @fission-ai/openspec`). No host PHP is needed —
every PHP command runs in the `php` container.

## First run

```bash
make up                       # build the php image, start nginx, php, postgres, redis
```

`make init` (build, up, `composer install`, migrate) becomes the first-run
command once the Symfony application exists (roadmap change
`scaffold-symfony-app`); until then the docroot is empty and nginx
answers 404 at http://localhost:8082 — that is the expected state.

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

App: http://localhost:8082 · API docs: http://localhost:8082/api ·
PostgreSQL: `127.0.0.1:5434` · Redis: `127.0.0.1:6382`.

## Daily

| Task | Command |
|---|---|
| start / stop | `make up` / `make down` |
| logs, status | `make logs`, `make ps` |
| composer / console | `make composer ARGS='…'` / `make console ARGS='…'` |
| migrations | `make migration` (generate, then read it) → `make migrate` |
| async worker (foreground) | `make worker` in a second terminal (clicks are logged asynchronously) |
| async worker (background) | `docker compose --profile worker up -d` — the `worker` service is a compose profile so `make up` does not start it before the application exists |
| the gate floor | `make check` (php-cs-fixer + PHPStan + PHPUnit) |

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
- **`make check` differs from CI** — CI runs `make check EXEC=` natively
  with PHP 8.4 and `MESSENGER_TRANSPORT_DSN=in-memory://`; make sure
  `composer.json` pins the same platform.
