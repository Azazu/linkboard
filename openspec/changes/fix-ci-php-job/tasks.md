## 1. Fix

- [ ] 1.1 `.github/workflows/ci.yml`: `POSTGRES_DB: app`, health check `-d app`, `actions/checkout@v7` ×3, `actions/setup-node@v7`. Verify: pinned actionlint (`rhysd/actionlint:1.7.12@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667`) exits 0; `rg -n 'app_test|@v4' .github/` returns nothing.
- [ ] 1.2 Local reproduction with a throwaway postgres on the compose network (`POSTGRES_DB=app`, user/password `app`): `make test-db EXEC=`-equivalent (`doctrine:database:create --if-not-exists --env=test` with `DATABASE_URL=postgresql://app:app@ci-pg:5432/app?serverVersion=16&charset=utf8`) creates `app_test`, and the health probe against that URL reports `database => true`. Record both outputs and the failing input (`POSTGRES_DB=app_test` → `database => false`) in the commit body.
- [ ] 1.3 `docs/how-to/local-development.md`: troubleshooting bullet on the probe using the un-suffixed database. Verify: re-read the file whole.

## 2. Wrap-up

- [ ] 2.1 Commit (`ci:` for 1.1–1.2, `docs:` for 1.3) with the agent trailer; `openspec validate fix-ci-php-job --strict`; `scripts/pregate-verify.sh gate2 fix-ci-php-job`; request Gate 2. Verify: floor output has no FAIL line.
- [ ] 2.2 Acceptance after merge and push (user pushes): the run on `main` shows `php` success; record the run URL in `handoff.md` before archiving.
