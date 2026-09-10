# Handoff — add-async-click-logging

**Updated:** 2026-09-10 · claude
**State:** awaiting-gate-1
**Branch:** change/add-async-click-logging

## Done this session
- Change started after the `add-routing-rules` archive (`main` f40a07c; merge commit run 34477153396 green). Branch and scaffold created; roadmap row 7 (Stage 2) already lists the change.
- Proposal (tier high: Messenger retry/failure behaviour, the click limit's concurrency moved to a Redis counter with seeding after key loss, the delete-then-consume race, a migration). No new capability; MODIFIED deltas: `redirect` (ADDED "The redirect performs no database write"; MODIFIED "Public redirect endpoint" HEAD wording, "Click limit is exact under concurrency" — Redis counter as the authority, seeding, guarantee boundary, limit change; "Failures of the stores" — lookup / counter / transport policies with the pre-existing scenario names kept, since a MODIFIED block may not drop scenarios), `click-logging` (ADDED idempotent handling, retries then the failed transport with a migrated table, orphan messages discarded, no raw personal data on the queue; MODIFIED "One click per successful redirect" — eventual, "Clicks follow their link on deletion" — counter key removed), `links` ("Read and list own links" — `clickCount` eventually consistent; "Delete a link" — counter removed). Design: 9 decisions (the seam keeps its shape and the resolver its failure mapping — counter failures propagate, dispatch failures are caught in the recorder; one `EVAL` Lua script seeding from `click_count`, prefixed keys in the test environment; a message carrying the finished facts and the hash, never IP/UA — FR-CLK-1/2 to be reworded; insert-first handler using the PK and FK as the idempotency and orphan checks; `messenger_messages` by migration with `auto_setup` off; the delete processor forgets the key best-effort; tests consume the in-memory transport explicitly and assert statement kinds through a test-only DBAL middleware) plus the high-tier applicability table. Tasks: 6 blocks, 14 tasks, each with a verification and the demonstrated failing input for every guard.
- `openspec validate add-async-click-logging --strict` and `scripts/pregate-verify.sh gate1 add-async-click-logging` pass.
- **Security-sensitive, flagged for the reviewer:** Messenger retry/failure-transport behaviour and the orphan-discard path; the Redis counter as the authority for the click limit under concurrency and after key loss; the migration creating `messenger_messages`; personal data kept off the queue.
- Assumptions recorded in the artifacts: the message carries finished facts (detection/geo/hash happen in the request, as routing requires) — a deviation from the letter of FR-CLK-1/2, reworded in task 4.2; Messenger's PHP serializer is kept; counter keys carry a `test:` prefix only in the test environment; no compensation for a crash between `INCR` and dispatch (bounded, stated); the synchronous recorder and its tests are deleted rather than kept as dead code; the retry-then-park test may fall back to asserting the configured strategy if the in-memory transport cannot exercise delays (task 3.3 records which form was feasible).

## Next step
`scripts/gate-run.sh add-async-click-logging 1 full` (auto mode). On changes-requested: `/workflow:fix-findings`, then `scripts/gate-run.sh add-async-click-logging 1 confirm <round>`; stop after two failed confirmations on one finding. On approval: tick task 0.1, `/opsx:apply` starting with block 1 (verify the Redis/Messenger vendor API named in the tasks before writing).

## Blockers
None.
