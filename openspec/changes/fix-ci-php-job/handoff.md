# Handoff — fix-ci-php-job

**Updated:** 2026-09-09 · claude
**State:** awaiting-gate-1
**Branch:** change/fix-ci-php-job

## Done this session
- Change started after the first `php` job run on `main` (run 34317318434, commit 33c6f95) failed with exit code 2.
- Proposal (tier high), design with applicability table, tasks written; `skip_specs: true`. Gate 1 Round 1: changes-requested (reproduction must run the real `make check EXEC=` path with failing and passing inputs). Fixed in artifacts: `make` added to the php image (proposal item 3, design decision 3), tasks 1.2/1.2a/1.2b run `make test-db EXEC= && make check EXEC=` with CI-shaped env against a throwaway postgres, `POSTGRES_DB=app_test` (fails, exit 2) and `POSTGRES_DB=app` (passes). Confirmation 1: changes-requested — the plan must require a green Actions run of the exact branch head before Gate 2. Added as task 2.2 (push branch → poll the API → record URL/SHA), `main` run check as task 2.4; design risk updated. Second confirmation requested.
- Root cause reproduced locally with a postgres configured like CI: `make test-db` passes, the health probe fails because database `app` does not exist (CI creates `app_test` only).

## Next step
User runs Codex for the Round 1 confirmation, then `scripts/gate-run.sh fix-ci-php-job 1 record`. On approval: apply tasks 1.1–1.3, request Gate 2, merge, push, record the green run.

## Blockers
None.
