## 1. CI fix (first)

- [ ] 1.1 Rewrite `.github/workflows/ci.yml`: add the `detect` job with a `probe` step writing `php=true|false` to `$GITHUB_OUTPUT`; give the `php` job `needs: detect` and `if: needs.detect.outputs.php == 'true'`; remove the job-level `hashFiles`. Verify: `rg -n hashFiles .github/` returns nothing and `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest -color` exits 0.
- [ ] 1.2 Demonstrated failing input for the retired check: run the same `actionlint` command against the previous `ci.yml` (`git show main:.github/workflows/ci.yml > <scratchpad>/old-ci/.github/workflows/ci.yml`, then actionlint in that directory) and record the result in the commit body; if actionlint does not flag it, record that GitHub's parser is the only oracle and cite the three red runs on `main` (34235957169, 34238240202, 34243979355) as the failing input.
- [ ] 1.3 Acceptance on GitHub (user pushes the branch): the run on `change/bootstrap-dev-environment` shows `workflow` green and `php` skipped; record the run URL in `handoff.md`.

## 2. php image and nginx

- [ ] 2.1 Create `.docker/php/Dockerfile` (php:8.3-fpm-alpine, pinned extension installer, extensions `pdo_pgsql intl opcache redis gd zip apcu`, Composer 2, non-root user from `UID`/`GID` build args, workdir `/app`) and `.docker/php/conf.d/app.ini`. Verify: `docker compose build php` succeeds and `docker compose run --rm php php -m` lists every extension above.
- [ ] 2.2 Create `.docker/nginx/default.conf` (root `/app/public`, Symfony front-controller rule, FastCGI to `php:9000`, other `.php` internal). Verify: `docker run --rm -v "$PWD/.docker/nginx/default.conf":/etc/nginx/conf.d/default.conf:ro nginx:1.27-alpine nginx -t` exits 0.

## 3. Compose and environment

- [ ] 3.1 Update `docker-compose.yml`: `UID`/`GID` build args on `php`, `worker` service under profile `worker` (same build, `messenger:consume async --time-limit=3600 --memory-limit=256M`, `restart: unless-stopped`), redis `command: ["redis-server", "--appendonly", "yes"]` with `redis_data` volume, corrected header comment. Verify: `docker compose config --quiet` exits 0 and `docker compose --profile worker config --services` lists `worker`.
- [ ] 3.2 Update `.env`: rename `SHORT_BASE_URL` → `APP_PUBLIC_URL`, add `UID=1000`, `GID=1000` with a comment. Verify: `rg -n SHORT_BASE_URL` over the repository returns nothing.
- [ ] 3.3 Runtime check: `make up`, then `docker compose ps` shows postgres and redis `healthy`, php and nginx `running`; `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8082/` prints `404` (empty docroot); `docker compose exec redis redis-cli config get appendonly` prints `yes`; `docker compose down` afterwards. Record the outputs in the commit body.

## 4. Documentation and plan

- [ ] 4.1 Update `docs/how-to/local-development.md` (worker profile command, AOF volume in Reset, "empty app returns 404 until change 2", `UID`/`GID` note, CI-red-after-start note) and `docs/reference/commands.md` (worker profile). Verify: re-read both whole; every command in them was run in its exact form during tasks 2–3.
- [ ] 4.2 Update `docs/explanation/requirements.md` §7 row 1 (tier `high`, restated exit criterion) and `openspec/ROADMAP.md` row 1 tier. Verify: `rg -n 'bootstrap-dev-environment' docs/ openspec/ROADMAP.md` shows `high` in both.

## 5. Wrap-up

- [ ] 5.1 Commit per block (`ci:` for task 1, `chore(docker):` for 2–3, `docs:` for 4) with the agent trailer, each body naming the verification outputs. Verify: `git log --oneline main..HEAD`.
- [ ] 5.2 `openspec validate bootstrap-dev-environment --strict` passes; `scripts/pregate-verify.sh gate2 bootstrap-dev-environment` passes (note: `make check` is not runnable before change 2 — record this in `handoff.md` for the Gate 2 reviewer); request Gate 2.
