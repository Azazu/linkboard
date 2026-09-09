# Handoff — fix-ci-php-job

**Updated:** 2026-09-09 · claude
**State:** awaiting-gate-2
**Branch:** change/fix-ci-php-job

## Done this session
- Change started after the first `php` job run on `main` (run 34317318434, commit 33c6f95) failed with exit code 2.
- Proposal (tier high), design with applicability table, tasks written; `skip_specs: true`. Gate 1 Round 1: changes-requested (reproduction must run the real `make check EXEC=` path with failing and passing inputs). Fixed in artifacts: `make` added to the php image (proposal item 3, design decision 3), tasks 1.2/1.2a/1.2b run `make test-db EXEC= && make check EXEC=` with CI-shaped env against a throwaway postgres, `POSTGRES_DB=app_test` (fails, exit 2) and `POSTGRES_DB=app` (passes). Confirmation 1: changes-requested — the plan must require a green Actions run of the exact branch head before Gate 2. Added as task 2.2 (push branch → poll the API → record URL/SHA), `main` run check as task 2.4; design risk updated. Confirmation 2: changes-requested — task 2.2 must name the jobs endpoint (`/actions/runs/{run_id}/jobs`) as well as the run list. Added; user chose a third confirmation over a waiver. Confirmation 3 confirmed (65d6147).
- Applied: ci.yml (POSTGRES_DB app, health check, actions v7), make in the php image, how-to bullet; failing and passing CI-path reproductions recorded in the ci: commit body. Tasks 1.x and 2.1 done.
- Task 2.2: branch run https://github.com/Azazu/linkboard/actions/runs/34319257555 on head 5a218d8 — workflow, detect and php all success (verified through /actions/runs and /actions/runs/{id}/jobs); the first green php job of the repository.
- Gate 2 Round 1: changes-requested — the green run must be on the reviewed commit itself. Branch re-pushed at 9be010f (the round's record commit): run https://github.com/Azazu/linkboard/actions/runs/34319750884 — workflow, detect, php all success. Finding #1 → fixed (the reviewer's row had no Status cell; only the cell was appended); confirmation requested. Between 9be010f and the confirmation head only review.md and handoff.md change (protocol files; the workflow file is byte-identical to the one that ran).
- Root cause reproduced locally with a postgres configured like CI: `make test-db` passes, the health probe fails because database `app` does not exist (CI creates `app_test` only).

## Next step
User runs Codex for Gate 2, then `scripts/gate-run.sh fix-ci-php-job 2 record`. On approval: `/git:merge fix-ci-php-job`; user pushes `main`; the executor checks the `main` run (task 2.4) before offering `/opsx:archive`.

## Blockers
None.
