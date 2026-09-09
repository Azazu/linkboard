# Handoff — add-link-crud

**Updated:** 2026-09-09 · claude
**State:** awaiting-gate-2
**Branch:** change/add-link-crud

## Done this session
- Change started: branch and scaffold created (after the `add-users-and-security` archive; `main` run 34330518338 green).
- Proposal (tier high), delta spec `links` (8 requirements, 15 scenarios), design (11 decisions, applicability table), tasks (5 blocks). Gate 1 Round 1: changes-requested, 3 majors, all addressed in the artifacts: (1) merge-patch presence from the decoded request body with a per-field null contract, new spec scenarios; (2) generated-slug candidates pre-checked before the single persist (a failed Doctrine commit closes the EntityManager), custom-slug race → 422, residual generated race → logged 500; (3) admin mutations of another user's link write an audit record after flush — new spec requirement, applicability row, tests. Confirmation 1: #1 confirmed; #2 and #3 changes-requested → design decision 3 now retries generated-slug collisions at INSERT time on a reset EntityManager (owner via getReference), spec/tasks aligned; audit tests add the negative paths (403, 422, forced flush failure → no record). Confirmation 2: #1 confirmed; #2, #3 changes-requested (task 2.1 conflated the recovery and the exhaustion tests; update/activate audit records untested). User chose a third confirmation over a waiver: task 2.1 split into (a) one collision → 201 on candidate 2 with the manager open again, (b) five collisions → 500 + error log, (c) custom slug → 422; spec scenario and task 3.3 now cover link.update and link.activate and the one-record-per-request rule. Confirmation 3: #2 — recovery evidence instead of object identity (lazy manager may be reset in place), failing input = the same test without resetManager(); #3 — exact context shape and explicit no-URL/no-email/no-slug assertions on every successful audit record. User chose a fourth confirmation → confirmed (c73aaba). Gate 1 passed.

- Implemented blocks 1–4 (1a75c0d entity/repository/migration, 821f026 slug rules + URL policy, f75151a API resource/voter/processors/audit, docs). make check: cs 0, stan 0, 159 tests / 1194 assertions. Deviations recorded: nullable fields emitted as null (`skip_null_values: false`); order tiebreaker follows the direction; the collision competitor is inserted on the same connection inside the flush (a side connection cannot see the per-test transaction's owner row).

- Task 5.2: branch run https://github.com/Azazu/linkboard/actions/runs/34335286637 on head c4bedcb — detect, workflow, php all success (run list by SHA + jobs endpoint).
- Gate 2 attempt on 73b20f1: floor passed, Codex exited 1 on the workspace spend cap; nothing written, fail-closed. Retried later the same day at the user's request: worked.
- Task 4.3 (acd3a57): Swagger UI never attached the token (OpenAPI had the JWT scheme but no global `security` requirement) → `swagger.http_auth.JWT` in api_platform.yaml; how-to documents Authorize. make check green. Branch run https://github.com/Azazu/linkboard/actions/runs/34337613127 on head 8d38817 — detect, workflow, php all success.
- Gate 2 Round 1 (def0366, recorded f64663f): changes-requested, 3 majors, all fixed in 5a010be — (1) PATCH `{"targetUrl":""}` now 422 (validator judges '' itself); (2) alternative IPv4 spellings (`127.1`, decimal, hex, octal, trailing dot) parsed per the WHATWG host parser and range-checked, percent-encoded hosts rejected; (3) slug pattern anchored with `\z`. Each guard has a demonstrated failing input (commit body). Spec/design/proposal/requirements/how-to reconciled. make check: 198 tests / 1330 assertions. **Security-sensitive:** input validation of untrusted URLs and slugs — needs a named developer's review. Branch run https://github.com/Azazu/linkboard/actions/runs/34345805988 on head 7458e32 — detect, workflow, php all success.

## Next step
`scripts/gate-run.sh add-link-crud 2 confirm 1` (auto mode). On confirmed: `/git:merge add-link-crud`, user pushes main, check the main run, `/opsx:archive add-link-crud` (syncs spec `links`), update ~/Projects/pet/Linkboard_TZ_RU.md, then `/workflow:start add-redirect-with-sync-logging`. After two failed confirmations on the same finding: stop and ask the user.

## Blockers
None (the Codex spend cap that blocked the first attempt turned out not to apply).
