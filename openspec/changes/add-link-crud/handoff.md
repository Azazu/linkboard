# Handoff — add-link-crud

**Updated:** 2026-09-09 · claude
**State:** awaiting-gate-1
**Branch:** change/add-link-crud

## Done this session
- Change started: branch and scaffold created (after the `add-users-and-security` archive; `main` run 34330518338 green).
- Proposal (tier high), delta spec `links` (8 requirements, 15 scenarios), design (11 decisions, applicability table), tasks (5 blocks). Gate 1 running in auto mode.

## Next step
Gate 1 verdict (`scripts/gate-run.sh add-link-crud 1 full`, auto). On approval: `/opsx:apply add-link-crud` (block 1 first). Security-relevant surface: target URL validation (open-redirect/SSRF class), ownership voter, hard delete.

## Blockers
None.
