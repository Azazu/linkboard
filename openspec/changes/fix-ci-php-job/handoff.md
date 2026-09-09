# Handoff — fix-ci-php-job

**Updated:** 2026-09-09 · claude
**State:** awaiting-gate-1
**Branch:** change/fix-ci-php-job

## Done this session
- Change started after the first `php` job run on `main` (run 34317318434, commit 33c6f95) failed with exit code 2.
- Proposal (tier high), design with applicability table, tasks written; `skip_specs: true`. Gate 1 requested.
- Root cause reproduced locally with a postgres configured like CI: `make test-db` passes, the health probe fails because database `app` does not exist (CI creates `app_test` only).

## Next step
User runs Codex for Gate 1, then `scripts/gate-run.sh fix-ci-php-job 1 record`. On approval: apply tasks 1.1–1.3, request Gate 2, merge, push, record the green run.

## Blockers
None.
