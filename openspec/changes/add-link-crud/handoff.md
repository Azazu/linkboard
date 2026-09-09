# Handoff — add-link-crud

**Updated:** 2026-09-09 · claude
**State:** implementing
**Branch:** change/add-link-crud

## Done this session
- Change started: branch and scaffold created (after the `add-users-and-security` archive; `main` run 34330518338 green).
- Proposal (tier high), delta spec `links` (8 requirements, 15 scenarios), design (11 decisions, applicability table), tasks (5 blocks). Gate 1 Round 1: changes-requested, 3 majors, all addressed in the artifacts: (1) merge-patch presence from the decoded request body with a per-field null contract, new spec scenarios; (2) generated-slug candidates pre-checked before the single persist (a failed Doctrine commit closes the EntityManager), custom-slug race → 422, residual generated race → logged 500; (3) admin mutations of another user's link write an audit record after flush — new spec requirement, applicability row, tests. Confirmation 1: #1 confirmed; #2 and #3 changes-requested → design decision 3 now retries generated-slug collisions at INSERT time on a reset EntityManager (owner via getReference), spec/tasks aligned; audit tests add the negative paths (403, 422, forced flush failure → no record). Confirmation 2: #1 confirmed; #2, #3 changes-requested (task 2.1 conflated the recovery and the exhaustion tests; update/activate audit records untested). User chose a third confirmation over a waiver: task 2.1 split into (a) one collision → 201 on candidate 2 with the manager open again, (b) five collisions → 500 + error log, (c) custom slug → 422; spec scenario and task 3.3 now cover link.update and link.activate and the one-record-per-request rule. Confirmation 3: #2 — recovery evidence instead of object identity (lazy manager may be reset in place), failing input = the same test without resetManager(); #3 — exact context shape and explicit no-URL/no-email/no-slug assertions on every successful audit record. User chose a fourth confirmation → confirmed (c73aaba). Gate 1 passed.

- Implemented blocks 1–4 (1a75c0d entity/repository/migration, 821f026 slug rules + URL policy, f75151a API resource/voter/processors/audit, docs). make check: cs 0, stan 0, 159 tests / 1194 assertions. Deviations recorded: nullable fields emitted as null (`skip_null_values: false`); order tiebreaker follows the direction; the collision competitor is inserted on the same connection inside the flush (a side connection cannot see the per-test transaction's owner row).

## Next step
Task 5.2: the user pushes `change/add-link-crud`; the executor polls the run list for the head SHA and `/actions/runs/{id}/jobs` until workflow, detect and php are all success, records the URL here, then task 5.3 (pregate gate2, `scripts/gate-run.sh add-link-crud 2 full`).

## Blockers
None.
