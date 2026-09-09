# Design — fix-ci-php-job

## Context

See `proposal.md` — Why. CI is the only place where the application database and the test database differ in name from the local defaults; the probe reads `DATABASE_URL` verbatim while Doctrine appends `_test` in the test environment.

## Goals / Non-Goals

**Goals:** a green `php` job on `main` with the existing tests unchanged; no Node deprecation warnings.
**Non-Goals:** see proposal.

## Decisions

1. **Rename the service database, not the probe.** Alternatives: (a) make the probe append `_test` in the test environment — hides the fact that the probe checks the configured database and would diverge from prod behavior; (b) point CI `DATABASE_URL` at `app_test` and skip `make test-db` — then Doctrine's suffix would look for `app_test_test`. Creating `app` as the service database and letting `make test-db` derive `app_test` keeps CI identical in shape to the local stack.
3. **Reproduce the real CI path, not a paraphrase.** The php container gets `make`; the reproduction runs `make test-db EXEC=` then `make check EXEC=` with `APP_ENV=test`, `DATABASE_URL=postgresql://app:app@ci-pg:5432/app?...`, `REDIS_URL`, `MESSENGER_TRANSPORT_DSN=in-memory://` — the same variables the workflow sets — against a throwaway `postgres:16-alpine` on the compose network. Failing input: `POSTGRES_DB=app_test` (make exits 2 with the two health tests failing); passing: `POSTGRES_DB=app`.
2. **Bump action majors to v7 by tag**, not SHA: the repository has no SHA-pinning policy yet; introducing one for two actions would be inconsistent. Recorded as a possible later decision.

## Applicability (high tier)

| Question | Applies? | Note |
|---|---|---|
| Crash before/after an external effect | n/a | CI steps only |
| Concurrent writers | n/a | |
| Money rounding | n/a | |
| Empty/zero/null inputs | yes | database `app` absent → the exact failure being fixed; the `make check EXEC=` reproduction (decision 3) with `POSTGRES_DB=app_test` is the failing input, the same run with `POSTGRES_DB=app` is the passing one |
| Authorization boundary | yes | workflow `permissions: contents: read` unchanged; no secrets touched |
| Deletion/expiry | n/a | |
| Idempotency of retries | yes | `doctrine:database:create --if-not-exists` and CI re-runs are idempotent |

## Risks / Trade-offs

- [GitHub's runner is the only place the workflow file and the actions are exercised] → the local reproduction covers the database logic; a green run on the exact branch head is mandatory before Gate 2 (task 2.2), and the `main` run is checked before the archive (post-merge acceptance 2.4 in tasks.md; not a checkbox because it cannot happen before Gate 2). This is the standing rule from here on: no merge is offered while the head's run is red or unverified.
- [v7 of the actions changes defaults] → checkout v7 / setup-node v7 keep the inputs this workflow uses (`node-version`); verified against their release notes titles only — the run itself is the check.

## Migration Plan

Merge, push, watch the run. Rollback: revert.
