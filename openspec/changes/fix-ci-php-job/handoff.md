# Handoff — fix-ci-php-job

**Updated:** 2026-09-09 · claude
**State:** implementing
**Branch:** change/fix-ci-php-job

## Done this session
- Change started after the first `php` job run on `main` (run 34317318434, commit 33c6f95) failed with exit code 2.
- Proposal (tier high), design with applicability table, tasks written; `skip_specs: true`. Gate 1 Round 1: changes-requested (reproduction must run the real `make check EXEC=` path with failing and passing inputs). Fixed in artifacts: `make` added to the php image (proposal item 3, design decision 3), tasks 1.2/1.2a/1.2b run `make test-db EXEC= && make check EXEC=` with CI-shaped env against a throwaway postgres, `POSTGRES_DB=app_test` (fails, exit 2) and `POSTGRES_DB=app` (passes). Confirmation 1: changes-requested — the plan must require a green Actions run of the exact branch head before Gate 2. Added as task 2.2 (push branch → poll the API → record URL/SHA), `main` run check as task 2.4; design risk updated. Confirmation 2: changes-requested — task 2.2 must name the jobs endpoint (`/actions/runs/{run_id}/jobs`) as well as the run list. Added; user chose a third confirmation over a waiver. Confirmation 3 confirmed (65d6147).
- Applied: ci.yml (POSTGRES_DB app, health check, actions v7), make in the php image, how-to bullet; failing and passing CI-path reproductions recorded in the ci: commit body. Tasks 1.x and 2.1 done.
- Root cause reproduced locally with a postgres configured like CI: `make test-db` passes, the health probe fails because database `app` does not exist (CI creates `app_test` only).

## Next step
Task 2.2: the user pushes `change/fix-ci-php-job`; the executor polls the run list for the head SHA and then `/actions/runs/{run_id}/jobs` until workflow, detect and php are all success, records the URL here, then task 2.3 (pregate gate2, request Gate 2).

## Blockers
None.
