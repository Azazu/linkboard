# Design — bootstrap-dev-environment

## Context

See `proposal.md` — Why. Current state: `docker-compose.yml`, `Makefile`, `.env` and `ci.yml` came from the ai-kit scaffold; `.docker/` was never created; CI fails at workflow parse time. Constraints: PHP-FPM behind nginx, PostgreSQL 16, Redis 7 (`AGENTS.md` Stack); Redis AOF required by FR-RED-3; no host PHP; `make check EXEC=` must keep working natively in CI once the app exists.

## Goals / Non-Goals

**Goals:** a parseable CI workflow whose `php` job self-skips while there is no `composer.json`; a buildable php image and a nginx config so `make up` yields a running stack; compose services matching §7 row 1 (php, nginx, postgres, redis, worker).

**Non-Goals:** anything listed under Non-goals in the proposal; performance tuning of the image; multi-stage production image.

## Decisions

1. **Conditional `php` job via a `detect` job**, not step-level `if`s and not unconditional execution. Step-level `hashFiles` would need the condition repeated on every step and still spin up service containers for nothing; running unconditionally fails on `composer install` until change 2. A `detect` job costs one short runner and keeps a single condition. Output is written with `$GITHUB_OUTPUT`; the `php` job keeps `permissions: contents: read` inherited from the workflow.
2. **Worker as a compose profile**, not a default service. A default `worker` service would crash-loop on the empty app and mark `make up` unhealthy; a profile (`docker compose --profile worker up -d`) starts it only when asked. `make worker` (foreground, `exec php`) stays for interactive debugging; both run the same `messenger:consume async` command.
3. **Non-root php container with host UID/GID** via build args defaulted in `.env` (`UID=1000`, `GID=1000`): bind-mounted `var/` and `vendor/` stay owned by the developer, which `docs/how-to/local-development.md` already promises. Alternative rejected: running as root and `chmod`-ing `var/` (masks permission bugs, differs from CI).
4. **Extensions via `docker-php-extension-installer`** pinned by image tag (see proposal Impact for the justification). Set: `pdo_pgsql intl opcache redis gd zip apcu`; `mbstring` is bundled in the official image.
5. **Redis AOF on** (`redis-server --appendonly yes`) with a `redis_data` named volume; `appendfsync everysec` (Redis default) is sufficient for a click counter whose guarantee boundary already tolerates seeding after data loss (FR-RED-3).
6. **nginx serves `/app/public` with the standard Symfony rule** (`try_files $uri /index.php$is_args$args`, `index.php` the only executable script, `internal` for other `.php`). Before change 2 the docroot is missing, nginx returns 404; this is the accepted "empty app" state and is stated in the how-to.
7. **`APP_PUBLIC_URL` replaces `SHORT_BASE_URL`** now, while nothing reads either name, so the first consumer (change 4, FR-LNK-11) finds the specified name in place.

## Applicability (high tier)

| Question | Applies? | Note |
|---|---|---|
| Crash before/after an external effect | n/a | no runtime effect; CI steps are idempotent shell probes |
| Concurrent writers | n/a | no shared mutable state |
| Money rounding | n/a | |
| Empty/zero/null inputs | yes | `composer.json` absent → `detect` outputs `php=false` and the `php` job is skipped (the state of this repository until change 2); present → job runs. `.env` missing `UID`/`GID` → compose falls back to `1000` via `${UID:-1000}` |
| Authorization boundary | yes | workflow `permissions: contents: read` retained; no secrets introduced; published ports bind to loopback only (`BIND_ADDRESS`) |
| Deletion/expiry | yes | `redis_data` and `pg_data` survive `make down`; `docker compose down --volumes` deletes both and stays a typed-by-hand command (how-to "Reset") |
| Idempotency of retries | yes | `make up`, `make init`, CI re-runs are idempotent; `detect` has no side effects |

## Risks / Trade-offs

- [GitHub's expression parser is the only oracle for the `hashFiles` class of error; nothing local reproduces it] → lint `ci.yml` with `actionlint` (dockerized) as a static floor, and treat the pushed run itself as the acceptance test: `workflow` green, `php` skipped. The three red runs on `main` are the recorded failing input for the retired expression.
- [Extension-installer image tag drifts] → pinned tag; bump is a reviewed change.
- [Developer UID ≠ 1000] → Compose substitutes variables from `.env` only, so `.env.local` cannot override the build args; the how-to documents `UID=$(id -u) GID=$(id -g) make init` (shell export) as the override path.
- [Freshly started changes keep `change/**` CI red until `/opsx:propose`] → known limitation, out of scope; noted in the how-to troubleshooting.

## Migration Plan

Apply on the branch, push, watch the run on `change/bootstrap-dev-environment`, then merge. Rollback: revert the merge commit; nothing persistent is created outside Docker volumes.
