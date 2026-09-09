## 1. Fix

- [ ] 1.1 `.github/workflows/ci.yml`: `POSTGRES_DB: app`, health check `-d app`, `actions/checkout@v7` ×3, `actions/setup-node@v7`. Verify: pinned actionlint (`rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667`) exits 0; `rg -n 'app_test|@v4' .github/` returns nothing.
- [ ] 1.2 `.docker/php/Dockerfile`: `apk add --no-cache make` (alongside git/unzip/postgresql16-client); `docker compose build php`; verify `docker compose run --rm --no-deps php make --version` prints GNU Make.
- [ ] 1.2a Real CI path, failing input: start `docker run -d --rm --name ci-pg --network linkboard_default -e POSTGRES_DB=app_test -e POSTGRES_USER=app -e POSTGRES_PASSWORD=app postgres:16-alpine`, wait for `pg_isready`, then `docker compose exec -T -e APP_ENV=test -e DATABASE_URL='postgresql://app:app@ci-pg:5432/app?serverVersion=16&charset=utf8' -e REDIS_URL=redis://redis:6379 -e MESSENGER_TRANSPORT_DSN=in-memory:// php sh -c 'make test-db EXEC= && make check EXEC='` → exit 2, the two health tests fail (`database` reported `fail`). Stop `ci-pg`. Record the tail in the commit body.
- [ ] 1.2b Real CI path, passing input: same as 1.2a with `-e POSTGRES_DB=app` → `make test-db EXEC=` creates `app_test`, `make check EXEC=` exits 0 with cs 0 / stan 0 / 21 tests. Stop `ci-pg`. Record the tail in the commit body.
- [ ] 1.3 `docs/how-to/local-development.md`: troubleshooting bullet on the probe using the un-suffixed database. Verify: re-read the file whole.

## 2. Wrap-up

- [ ] 2.1 Commit (`ci:` for 1.1–1.2, `docs:` for 1.3) with the agent trailer; `openspec validate fix-ci-php-job --strict`; `scripts/pregate-verify.sh gate2 fix-ci-php-job`; request Gate 2. Verify: floor output has no FAIL line.
- [ ] 2.2 Acceptance after merge and push (user pushes): the run on `main` shows `php` success; record the run URL in `handoff.md` before archiving.
