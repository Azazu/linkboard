# Handoff — add-link-crud

**Updated:** 2026-09-09 · claude
**State:** awaiting-gate-1
**Branch:** change/add-link-crud

## Done this session
- Change started: branch and scaffold created (after the `add-users-and-security` archive; `main` run 34330518338 green).
- Proposal (tier high), delta spec `links` (8 requirements, 15 scenarios), design (11 decisions, applicability table), tasks (5 blocks). Gate 1 Round 1: changes-requested, 3 majors, all addressed in the artifacts: (1) merge-patch presence from the decoded request body with a per-field null contract, new spec scenarios; (2) generated-slug candidates pre-checked before the single persist (a failed Doctrine commit closes the EntityManager), custom-slug race → 422, residual generated race → logged 500; (3) admin mutations of another user's link write an audit record after flush — new spec requirement, applicability row, tests. Confirmation 1: #1 confirmed; #2 and #3 changes-requested → design decision 3 now retries generated-slug collisions at INSERT time on a reset EntityManager (owner via getReference), spec/tasks aligned; audit tests add the negative paths (403, 422, forced flush failure → no record). Second confirmation running.

## Next step
Gate 1 verdict (`scripts/gate-run.sh add-link-crud 1 full`, auto). On approval: `/opsx:apply add-link-crud` (block 1 first). Security-relevant surface: target URL validation (open-redirect/SSRF class), ownership voter, hard delete.

## Blockers
None.
