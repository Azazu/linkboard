# Proposal — fix-ci-php-job

**Risk-Tier:** high

Tier rationale: edits `.github/workflows/ci.yml` — the "verifier/CI infrastructure" trigger of `AGENTS.md`. The change is two lines; the tier follows the surface, not the size (user decision of 2026-09-08).

## Why

The first run of the CI `php` job on `main` (run 34317318434 after the `scaffold-symfony-app` merge) failed: `Process completed with exit code 2`. Reproduced locally with a PostgreSQL configured like the CI service (`POSTGRES_DB=app_test`, user `app`): `make test-db` succeeds (`app_test` already exists), but the health probe — which connects to the raw `DATABASE_URL` database `app`, not the `_test`-suffixed one Doctrine uses — reports `database: false`, so `HealthProbeTest::testBothDependenciesReachable` and `HealthTest::testDeepProbeReportsEveryDependency` fail and `make check` exits 2. Locally the equivalent database (`linkboard`) always exists, which is why the gap never showed. Separately, every job prints "Node.js 20 is deprecated" for `actions/checkout@v4` and `actions/setup-node@v4`.

## What Changes

1. `.github/workflows/ci.yml`, `php` job service `postgres`: `POSTGRES_DB: app` (was `app_test`) and the health check `pg_isready -U app -d app`. `make test-db EXEC=` then creates `app_test` next to it, mirroring the local setup (`linkboard` + `linkboard_test`).
2. `.github/workflows/ci.yml`: `actions/checkout@v7` (three places) and `actions/setup-node@v7` — current majors (v7.0.1 / v7.0.0 on 2026-09-09), Node 24 runtime, warnings gone. `shivammathur/setup-php@v2` stays (no warning, v2 is the maintained line).
3. `docs/how-to/local-development.md`, troubleshooting: one sentence that the probe uses the un-suffixed `DATABASE_URL` database, so it must exist wherever the tests run.

## Capabilities

### New Capabilities

None — CI configuration only (`skip_specs: true`).

### Modified Capabilities

None.

## Non-goals

- No change to the health probe or the tests: the probe deliberately uses the raw `DATABASE_URL` (design of `scaffold-symfony-app`, decision 5), and the tests assert real behavior.
- No change to the `workflow` or `detect` jobs' logic.
- No pinning of actions by commit SHA (would be a separate, repository-wide decision).
- No fix to the sibling repositories or ai-kit.

## Impact

- `.github/workflows/ci.yml` (service env, health check, two action majors).
- `docs/how-to/local-development.md` (one troubleshooting bullet).
- No dependency, code or schema change.
