# Review — fix-ci-php-job

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 7a91de14ebf0fbc91df806e9ed0418151badf0e7
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | openspec/changes/fix-ci-php-job/tasks.md: 1.2; proposal.md: Why and What Changes #1 | Task 1.2 proves only that `doctrine:database:create` can create `app_test` and that an unspecified health-probe invocation returns `database => true`. It neither runs the actual PHPUnit/`make check EXEC=` path with the CI-shaped `DATABASE_URL` nor makes that path demonstrably fail with `POSTGRES_DB=app_test` and pass with `POSTGRES_DB=app`. That is the exact regression whose exit code 2 this change claims to fix, so Gate 2 could otherwise be green only against the local `linkboard` database while the CI job remains broken. Specify an exact runnable CI-shaped test command and require recorded failing and passing results for the affected health tests (or the complete `make check EXEC=`); keep the post-merge workflow run as acceptance, not the sole end-to-end evidence. | fixed |

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** ef4852900285616217e4e8da16f746460bf92507
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — tasks 1.2a and 1.2b now correctly cover the local CI-shaped failing and passing `make` path. However, task 2.2 still makes the first real GitHub Actions verification occur only after merge to `main`. Because this change also updates the actions themselves and the workflow runs on `change/**`, require the user to push the exact branch head and record a green run for all workflow jobs before requesting Gate 2; retain the post-merge run as final confirmation. This is required to prevent an untested workflow-level failure from reaching `main`. |

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** f854f84061133a8dbee218d8db4bcc7683102fda
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — task 2.2 correctly requires a green run on the exact branch head before Gate 2, but its sole endpoint (`/actions/runs?branch=...`) exposes workflow-run status, not the individual `workflow`, `detect`, and `php` job conclusions it claims to check. Select the matching `head_sha` and query that run's `/actions/runs/{run_id}/jobs` endpoint (or use an equivalently exact GitHub CLI command), then require all three named jobs to be `completed` and `success`. This is the second failed confirmation of finding #1; per the review protocol, do not request a third confirmation without user arbitration. |
