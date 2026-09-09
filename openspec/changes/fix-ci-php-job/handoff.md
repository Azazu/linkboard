# Handoff — fix-ci-php-job

**Updated:** 2026-09-09 · claude
**State:** proposing
**Branch:** change/fix-ci-php-job

## Done this session
- Change started after the first `php` job run on `main` (run 34317318434, commit 33c6f95) failed with exit code 2.
- Root cause reproduced locally with a postgres configured like CI: `make test-db` passes, the health probe fails because database `app` does not exist (CI creates `app_test` only).

## Next step
`/opsx:propose fix-ci-php-job`, Gate 1 (tier high: CI infrastructure), apply, Gate 2, merge, push, observe the run.

## Blockers
None.
