# How to run Linkboard locally

Prerequisites: Docker with Compose v2, `make`, Node 22 (for the OpenSpec
CLI: `npm install -g @fission-ai/openspec`). No host PHP is needed —
every PHP command runs in the `php` container.

## First run

```bash
make init                     # build, up, composer install, migrate
```

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
| async worker | `make worker` in a second terminal (clicks are logged asynchronously) |
| the gate floor | `make check` (php-cs-fixer + PHPStan + PHPUnit) |

## Reset (DESTRUCTIVE)

`docker compose down --volumes` deletes the PostgreSQL volume. There is
no make target for it on purpose — type it yourself.

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
- **Permission denied on `var/`** — the php image must run as your
  UID/GID (see `.docker/php/Dockerfile`); rebuild with `make init`.
- **`make check` differs from CI** — CI runs `make check EXEC=` natively
  with PHP 8.3 and `MESSENGER_TRANSPORT_DSN=in-memory://`; make sure
  `composer.json` pins the same platform.
