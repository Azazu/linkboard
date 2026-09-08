# Proposal — bootstrap-dev-environment

**Risk-Tier:** high

Tier rationale: the change edits `.github/workflows/ci.yml`, which is the "verifier/CI infrastructure" trigger of `AGENTS.md`; the size of the edit does not lower the tier. Raised from the roadmap's provisional `low` by the user on 2026-09-08.

## Why

Every push to `main` is red: GitHub cannot parse `.github/workflows/ci.yml` because `hashFiles('composer.json')` is used in a job-level `if`, where the function does not exist (annotation on runs 34235957169, 34238240202, 34243979355: `Unrecognized function: 'hashFiles'`). No job runs at all, so the CI floor the workflow relies on does not exist. Separately, `docker-compose.yml` references `.docker/php` and `.docker/nginx/default.conf`, which do not exist, so `make up` cannot start the stack; the specification (§7, change 1) expects a working local environment before the Symfony scaffold arrives.

## What Changes

1. **CI fix (first task).** Replace the job-level `hashFiles` condition with a `detect` job that probes for `composer.json` in a step and exposes `php=true|false` as an output; the `php` job gets `needs: detect` and `if: needs.detect.outputs.php == 'true'`. The `workflow` job is unchanged. Result on this repository today: `workflow` runs and is green, `php` is skipped until `composer.json` exists.
2. **php image.** `.docker/php/Dockerfile` (base `php:8.3.33-fpm-alpine@sha256:bf90236449d333cef008b1f01c72a3d4f11a6470a74629665e4c6b6158f03fc8`, extensions `pdo_pgsql intl opcache redis gd zip apcu` via the extension installer `ghcr.io/mlocati/php-extension-installer:2.11.12@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138`, Composer copied from `composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332`, non-root user with build args `HOST_UID`/`HOST_GID` so bind-mounted `var/` stays writable) and `.docker/php/conf.d/app.ini` (UTC, opcache, memory limit, error settings for dev).
3. **nginx.** `.docker/nginx/default.conf` serving `/app/public` with the Symfony front-controller rule and FastCGI to `php:9000`; until `public/index.php` exists (change 2) the server answers 404, which is the expected state.
4. **Compose.** Add a `worker` service (same image, `messenger:consume async`, under compose profile `worker` so `make up` does not start it before the application exists); Redis with `--appendonly yes` and a persistent volume (FR-RED-3 requires AOF); header comment corrected (there is no `.env.example`; `.env` is committed).
5. **Environment names.** `.env`: rename `SHORT_BASE_URL` to `APP_PUBLIC_URL`, the name the specification fixes (FR-LNK-11); add `HOST_UID`/`HOST_GID` defaults (`1000`) consumed as Compose build args. The names avoid `UID`/`GID`, which Bash reserves as read-only variables.
6. **Docs.** `docs/how-to/local-development.md` and `docs/reference/commands.md` describe the worker profile, AOF volume, the reset command covering both volumes, and state that `make init` becomes usable with change 2; `docs/explanation/requirements.md` §7 row 1: tier `high`, exit criterion restated as "`make up` brings postgres and redis healthy and nginx/php running; CI `workflow` job green, `php` job skipped by `detect`"; `openspec/ROADMAP.md` row 1 tier `high`.

## Capabilities

### New Capabilities

None — tooling and infrastructure only; no application behavior. `.openspec.yaml` sets `skip_specs: true`.

### Modified Capabilities

None.

## Non-goals

- No `composer.json`, Symfony skeleton, `public/index.php` or application code: that is `scaffold-symfony-app` (change 2). `make init` therefore still fails at `composer install` after this change and is documented as such.
- No fix to the same defect in `ai-kit` or in the sibling repositories (cookbook, semanticshelf, thumbforge): separate work in their own repositories, explicitly excluded by the user.
- No change to the `workflow` CI job, to `openspec validate --all --strict` behavior on freshly started changes (red between `/workflow:start` and `/opsx:propose`), or to the verifier scripts.
- No production deployment configuration (public hosting is stretch, D20); no TLS; no CD.
- No `.env.test`: it arrives with the Symfony scaffold.

## Impact

- `.github/workflows/ci.yml` (structure of the `php` job; new `detect` job).
- New: `.docker/php/Dockerfile`, `.docker/php/conf.d/app.ini`, `.docker/nginx/default.conf`.
- `docker-compose.yml` (worker service, redis command and volume, build args), `.env` (rename, build-arg defaults), `Makefile` (no target changes; `worker` target unchanged).
- Docs: `docs/how-to/local-development.md`, `docs/reference/commands.md`, `docs/explanation/requirements.md` §7, `openspec/ROADMAP.md`.
- New OCI inputs, all pinned by immutable tag **and** digest (resolved on 2026-09-08 from the registries): base image `php:8.3.33-fpm-alpine@sha256:bf90236449d333cef008b1f01c72a3d4f11a6470a74629665e4c6b6158f03fc8`; Composer `composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332`; extension installer `ghcr.io/mlocati/php-extension-installer:2.11.12@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138` (build-time only); actionlint `rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667` (developer-side lint, not part of the image). Bumps are reviewed edits of these lines. Existing compose services keep their minor-version tags from the scaffold (`postgres:16-alpine`, `redis:7-alpine`, `nginx:1.27-alpine`) — pinning them is out of scope and noted in the design. Justification for the installer: compiling `intl`, `redis`, `gd`, `apcu` on Alpine by hand needs a dozen `apk` lines that break on each PHP/Alpine bump; the installer is the community standard for exactly this and has no runtime footprint. Alternative rejected: `dunglas/frankenphp` image (changes the runtime model the specification fixes as PHP-FPM behind nginx).
