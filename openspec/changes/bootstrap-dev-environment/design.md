# Design — bootstrap-dev-environment

## Context

See `proposal.md` — Why. Current state: `docker-compose.yml`, `Makefile`, `.env` and `ci.yml` came from the ai-kit scaffold; `.docker/` was never created; CI fails at workflow parse time. Constraints: PHP-FPM behind nginx, PostgreSQL 16, Redis 7 (`AGENTS.md` Stack); Redis AOF required by FR-RED-3; no host PHP; `make check EXEC=` must keep working natively in CI once the app exists.

## Goals / Non-Goals

**Goals:** a parseable CI workflow whose `php` job self-skips while there is no `composer.json`; a buildable php image and a nginx config so `make up` yields a running stack; compose services matching §7 row 1 (php, nginx, postgres, redis, worker).

**Non-Goals:** anything listed under Non-goals in the proposal; performance tuning of the image; multi-stage production image.

## Decisions

1. **Conditional `php` job via a `detect` job**, not step-level `if`s and not unconditional execution. Step-level `hashFiles` would need the condition repeated on every step and still spin up service containers for nothing; running unconditionally fails on `composer install` until change 2. A `detect` job costs one short runner and keeps a single condition. Output is written with `$GITHUB_OUTPUT`; the `php` job keeps `permissions: contents: read` inherited from the workflow.
2. **Worker as a compose profile**, not a default service. A default `worker` service would crash-loop on the empty app and mark `make up` unhealthy; a profile (`docker compose --profile worker up -d`) starts it only when asked. `make worker` (foreground, `exec php`) stays for interactive debugging; both run the same `messenger:consume async` command.
3. **Non-root php container with the host's ids** via build args `HOST_UID`/`HOST_GID`, defaulted to `1000` in `.env` and substituted by Compose (`${HOST_UID:-1000}`): bind-mounted `var/` and `vendor/` stay owned by the developer, which `docs/how-to/local-development.md` already promises. The names are deliberately not `UID`/`GID`: Bash defines both as read-only shell variables, so `UID=… make init` fails with `UID: readonly variable`. Override for a developer whose ids are not 1000: `HOST_UID=$(id -u) HOST_GID=$(id -g) make init` (Compose reads shell variables over `.env`). Alternative rejected: running as root and `chmod`-ing `var/` (masks permission bugs, differs from CI).
4. **Extensions via `docker-php-extension-installer`**, copied from `ghcr.io/mlocati/php-extension-installer:2.11.12@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138` (see proposal Impact for the justification). Set: `pdo_pgsql intl opcache redis gd zip apcu`; `mbstring` is bundled in the official image.
9. **Makefile guard mirrors the CI `detect` job.** `cs`, `stan` and `test` start with `@test -f composer.json || { echo '[SKIP] no composer.json — application not scaffolded yet'; exit 0; }` (one shared macro), so `make check` is green-with-SKIP on the empty app and becomes the real floor when change 2 adds `composer.json`. Alternatives rejected: skipping `make check` in `pregate-verify.sh` for `low`/docs changes (weakens the verifier for every future change); a waiver (AGENTS.md forbids waiving checks); holding this change until change 2 (leaves `main` red for another change). The guard cannot mask a real failure: with `composer.json` present it is inert.
8. **Every new OCI input is pinned by tag and digest** so the image build and the actionlint floor are reproducible: base `php:8.3.33-fpm-alpine@sha256:bf90236449d333cef008b1f01c72a3d4f11a6470a74629665e4c6b6158f03fc8`, Composer `composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332`, installer as above, actionlint `rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667`. Digests were resolved from Docker Hub and ghcr.io on 2026-09-08 and are recorded in the proposal; a bump edits these lines in a reviewed change. The scaffold's `postgres:16-alpine`, `redis:7-alpine`, `nginx:1.27-alpine` keep their minor tags (patch updates wanted, pinning them is a separate decision).
5. **Redis AOF on** (`redis-server --appendonly yes`) with a `redis_data` named volume; `appendfsync everysec` (Redis default) is sufficient for a click counter whose guarantee boundary already tolerates seeding after data loss (FR-RED-3).
6. **nginx serves `/app/public` with the standard Symfony rule** (`try_files $uri /index.php$is_args$args`, `index.php` the only executable script, `internal` for other `.php`). Before change 2 the docroot is missing, nginx returns 404; this is the accepted "empty app" state and is stated in the how-to.
7. **`APP_PUBLIC_URL` replaces `SHORT_BASE_URL`** now, while nothing reads either name, so the first consumer (change 4, FR-LNK-11) finds the specified name in place.

## Applicability (high tier)

| Question | Applies? | Note |
|---|---|---|
| Crash before/after an external effect | n/a | no runtime effect; CI steps are idempotent shell probes |
| Concurrent writers | n/a | no shared mutable state |
| Money rounding | n/a | |
| Empty/zero/null inputs | yes | `composer.json` absent → `detect` outputs `php=false` and the `php` job is skipped, and `make check` prints `[SKIP]` and exits 0 (the state of this repository until change 2); present → job runs and the guard is inert. `.env` missing `HOST_UID`/`HOST_GID` → Compose falls back to `1000` via `${HOST_UID:-1000}`; a build with `HOST_UID=1234 HOST_GID=1234` is a required test (task 2.3) |
| Authorization boundary | yes | workflow `permissions: contents: read` retained; no secrets introduced; published ports bind to loopback only (`BIND_ADDRESS`) |
| Deletion/expiry | yes | `redis_data` and `pg_data` survive `make down`; `docker compose down --volumes` deletes both and stays a typed-by-hand command (how-to "Reset") |
| Idempotency of retries | yes | `make up`, `make init`, CI re-runs are idempotent; `detect` has no side effects |

## Risks / Trade-offs

- [GitHub's expression parser is the only oracle for the `hashFiles` class of error; nothing local reproduces it] → lint `ci.yml` with `rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667` (dockerized, pinned) as a static floor, and treat the pushed run itself as the acceptance test: `workflow` green, `php` skipped. The three red runs on `main` are the recorded failing input for the retired expression.
- [OCI inputs drift] → tag + digest pins for every new input (Decision 8); a bump is a reviewed change.
- [Developer ids ≠ 1000] → `.env.local` is not read by Compose, so it cannot override the build args; the how-to documents `HOST_UID=$(id -u) HOST_GID=$(id -g) make init` as the override path, and task 2.3 verifies a non-1000 build.
- [Freshly started changes keep `change/**` CI red until `/opsx:propose`] → known limitation, out of scope; noted in the how-to troubleshooting.

## Migration Plan

Apply on the branch, push, watch the run on `change/bootstrap-dev-environment`, then merge. Rollback: revert the merge commit; nothing persistent is created outside Docker volumes.
