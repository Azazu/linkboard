## 1. CI fix (first)

- [x] 1.1 Rewrite `.github/workflows/ci.yml`: add the `detect` job with a `probe` step writing `php=true|false` to `$GITHUB_OUTPUT`; give the `php` job `needs: detect` and `if: needs.detect.outputs.php == 'true'`; remove the job-level `hashFiles`. Verify: `rg -n hashFiles .github/` returns nothing and `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -color` exits 0.
- [x] 1.2 Demonstrated failing input for the retired check: run the same `actionlint` command against the previous workflow file from main (`git show main:.github/workflows/ci.yml > <scratchpad>/old-ci/.github/workflows/ci.yml`, `git init` in that directory because actionlint requires a repository root, then the same pinned actionlint command against it) and record the result in the commit body; if actionlint does not flag it, record that GitHub's parser is the only oracle and cite the three red runs on `main` (34235957169, 34238240202, 34243979355) as the failing input.
- [ ] 1.3 Acceptance on GitHub (user pushes the branch): the run on `change/bootstrap-dev-environment` shows `workflow` green and `php` skipped; record the run URL in `handoff.md`.

## 2. php image and nginx

- [x] 2.1 Create `.docker/php/Dockerfile` (`FROM php:8.3.33-fpm-alpine@sha256:bf90236449d333cef008b1f01c72a3d4f11a6470a74629665e4c6b6158f03fc8`, installer `COPY --from=ghcr.io/mlocati/php-extension-installer:2.11.12@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138`, extensions `pdo_pgsql intl opcache redis gd zip apcu`, `COPY --from=composer:2.10.3@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332`, non-root user from `HOST_UID`/`HOST_GID` build args, workdir `/app`) and `.docker/php/conf.d/app.ini`. Verify (self-contained, no Compose wiring needed yet): `docker build -t linkboard-php-check .docker/php` succeeds and `docker run --rm linkboard-php-check php -m` lists every extension above.
- [x] 2.2 Create `.docker/nginx/default.conf` (root /app/public inside the container, Symfony front-controller rule, FastCGI to `php:9000`, other `.php` internal). Verify: `docker run --rm --add-host=php:127.0.0.1 -v "$PWD/.docker/nginx/default.conf":/etc/nginx/conf.d/default.conf:ro nginx:1.27-alpine nginx -t` exits 0 (the `--add-host` stands in for the compose network so the `php` upstream resolves).
- [x] 2.3 Non-default ids, self-contained (plain `docker build`, no Compose, no bind mount): `docker build --build-arg HOST_UID=1234 --build-arg HOST_GID=1234 -t linkboard-php-uid .docker/php` then `docker run --rm linkboard-php-uid sh -c 'id -u; id -g; touch /app/.probe && stat -c %u /app/.probe'` prints `1234` three times (the image's `/app` workdir is owned by the build user); record the output in the commit body and remove both test images. Also demonstrate the retired naming fails: `bash -c 'UID=1234 true'` prints `UID: readonly variable` (recorded, not committed).

## 3. Compose and environment

- [x] 3.1 Update `docker-compose.yml`: `HOST_UID`/`HOST_GID` build args on `php` (`${HOST_UID:-1000}`), `worker` service under profile `worker` (same build, `messenger:consume async --time-limit=3600 --memory-limit=256M`, `restart: unless-stopped`), redis `command: ["redis-server", "--appendonly", "yes"]` with `redis_data` volume, corrected header comment. Verify: `docker compose config --quiet` exits 0 and `docker compose --profile worker config --services` lists `worker`.
- [x] 3.2 Update `.env`: rename `SHORT_BASE_URL` → `APP_PUBLIC_URL`, add `HOST_UID=1000`, `HOST_GID=1000` with a comment on why not `UID`/`GID`. Verify: `rg -n 'SHORT_BASE_URL|\bUID=|\bGID=' --glob '!openspec/changes/**'` over the repository returns nothing.
- [x] 3.3 Runtime check: `make up`, then `docker compose ps` shows postgres and redis `healthy`, php and nginx `running`; `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8082/` prints `404` (empty docroot); `docker compose exec redis redis-cli config get appendonly` prints `yes`; `docker compose down` afterwards. Record the outputs in the commit body.

## 3a. Makefile guard (scope added after the first Gate 2 floor run)

- [ ] 3a.1 Add the guard macro to `Makefile` and prepend it to `cs`, `stan`, `test`. Verify: without `composer.json`, `make check` exits 0 and prints `[SKIP] no composer.json — application not scaffolded yet` three times then `check: all green`; with a throwaway `composer.json` (`echo '{}' > composer.json`, removed afterwards) `make cs EXEC=` no longer prints SKIP and fails on the missing `vendor/bin/php-cs-fixer` — the failing input that shows the guard is inert once the application exists. Record both outputs in the commit body.
- [ ] 3a.2 `scripts/pregate-verify.sh gate2 bootstrap-dev-environment` reports `make check` OK. Verify: the FAIL line is gone from its output.

## 4. Documentation and plan

- [x] 4.1 Update `docs/how-to/local-development.md` (worker profile command, AOF volume in Reset, "empty app returns 404 until change 2", `HOST_UID`/`HOST_GID` override note replacing the old UID/GID wording in Troubleshooting, CI-red-after-start note) and `docs/reference/commands.md` (worker profile). Verify: re-read both whole; every command in them was run in its exact form during tasks 2–3.
- [x] 4.2 Update `docs/explanation/requirements.md` §7 row 1 (tier `high`, restated exit criterion) and `openspec/ROADMAP.md` row 1 tier. Verify: `rg -n 'bootstrap-dev-environment' docs/ openspec/ROADMAP.md` shows `high` in both.

## 5. Wrap-up

- [x] 5.1 Commit per block (`ci:` for task 1, `chore(docker):` for 2–3, `docs:` for 4) with the agent trailer, each body naming the verification outputs. Verify: `git log --oneline main..HEAD`.
- [ ] 5.2 `openspec validate bootstrap-dev-environment --strict` passes; `scripts/pregate-verify.sh gate2 bootstrap-dev-environment` passes (with the guard of 3a, `make check` is green-with-SKIP; the handoff tells the Gate 2 reviewer so); request Gate 2.
