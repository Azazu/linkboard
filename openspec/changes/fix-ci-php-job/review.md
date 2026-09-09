# Review — fix-ci-php-job

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 7a91de14ebf0fbc91df806e9ed0418151badf0e7
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | openspec/changes/fix-ci-php-job/tasks.md: 1.2; proposal.md: Why and What Changes #1 | Task 1.2 proves only that `doctrine:database:create` can create `app_test` and that an unspecified health-probe invocation returns `database => true`. It neither runs the actual PHPUnit/`make check EXEC=` path with the CI-shaped `DATABASE_URL` nor makes that path demonstrably fail with `POSTGRES_DB=app_test` and pass with `POSTGRES_DB=app`. That is the exact regression whose exit code 2 this change claims to fix, so Gate 2 could otherwise be green only against the local `linkboard` database while the CI job remains broken. Specify an exact runnable CI-shaped test command and require recorded failing and passing results for the affected health tests (or the complete `make check EXEC=`); keep the post-merge workflow run as acceptance, not the sole end-to-end evidence. | open |
