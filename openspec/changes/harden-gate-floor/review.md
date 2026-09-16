# Review — harden-gate-floor

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** d30af22939912558645ce18f4525841af033f511
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | tasks.md:62, task 8.4 | Task 8.4 can only be completed and verified after the archive commit, but the final lifecycle section requires every checkbox complete before Gate 2. `scripts/pregate-verify.sh` rejects any unchecked task at Gate 2, and `scripts/workflow-verify.sh` also requires all tasks complete before merge and archive. This creates a lifecycle deadlock. Separate the pre-merge requirements-document reconciliation from the post-merge roadmap removal, and describe the latter as an un-checkboxed archive lifecycle step rather than a Gate 2 prerequisite. | fixed |
| 2 | major | design.md:185-190, 207-209; tasks.md:39, 55 | The deterministic scratch name plus an unconditional initial drop does not establish database ownership. With configured database `app`, an existing unrelated `app_roundtrip` is deleted even though the equality guard passes; two local invocations against `app` also target and drop the same database. Printing the name does not prevent either failure, so concurrent writers cannot be marked n/a. Specify exclusive scratch ownership before any destructive command (for example, a fresh per-run name with creation that refuses an existing database, and cleanup limited to that successfully created database), including how crash leftovers are handled without deleting an unowned database. Add negative tests for a pre-existing target and overlapping invocations. | fixed |
| 3 | major | design.md:152-169; tasks.md:40, 51-55 | The negative fingerprint test substitutes a different listing from a fake console, so it proves the shell comparison but not the SQL fingerprint check. The only real PostgreSQL run uses migrations whose schema already round-trips correctly. If the fingerprint SQL omits indexes or column attributes, that real run and all proposed stub tests still pass; task 7.2 therefore does not demonstrate detection of an actual leftover index. Add a repeatable real-PostgreSQL negative fixture that changes a covered schema object and exercises the real fingerprint SQL/comparison, with a named difference and nonzero result. Cover the promised column, index and constraint categories so removing their extraction causes a test to fail; keep the stub suite for orchestration failures. | fixed |
| 4 | minor | tasks.md:31, task 3.6; design.md:92-99 | The required unchanged assertion count contradicts the chosen JSON accessor, which intentionally adds counted PHPUnit assertions to existing tests. Its successful use can increase assertion counts even with no new test methods. Record and explain the expected accessor-related assertion delta separately from newly added tests, and verify preservation of the original assertions directly; equal counts alone do not establish equal meaning. | fixed |

### Validation

- Verified the current branch is `change/harden-gate-floor`, HEAD matches the reviewed commit, and the worktree was clean before this record.
- Read the proposal, design, tasks, handoff, `.openspec.yaml`, `AGENTS.md` and `openspec/config.yaml`; checked the existing migration, CI, Makefile and verifier mechanisms against the plan.
- `openspec validate harden-gate-floor --strict` passed.
- `scripts/pregate-verify.sh gate1 harden-gate-floor` passed with zero warnings. The mechanical floor does not detect the lifecycle and isolation issues above.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** b9cf01349a9a7294e457cf856802a408e45433ba
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Task 8.4 now covers only pre-merge requirements reconciliation; roadmap removal is an un-checkboxed post-merge archive step. The named task no longer depends on passing Gate 2 or archiving first. |
| 2 | changes-requested — Design decision 4 and task 5.2 now require successful creation before cleanup and preserve crash leftovers, resolving the destructive ownership design. The requested negative verification remains incomplete: task 7.5 covers a non-empty response, but not failed exclusive creation of an already existing empty database; task 7.6 only runs twice and compares names, without requiring overlap or proving that one run's cleanup cannot drop the other's database. Specify a create-collision failure case (including an empty existing target) with no migration or drop, and a synchronized overlapping-run case that holds one owned database live while the other finishes or fails and verifies cleanup affects only its owner. Random names reduce collisions; the exclusive-create failure path is what makes a collision safe and needs its own demonstrated failing input. |
| 3 | confirmed — Decision 4a and tasks 5.3/7.7 share the production SQL with repeatable real-PostgreSQL fixtures for indexes, column nullability/default/type and constraints, require named differences, and require executed extraction-removal mutations to fail. Task 7.2 separately verifies the shell comparison's nonzero exit and printed diff; the former claim that stub output proves SQL coverage is removed. |
| 4 | confirmed — Task 3.6 replaces equal assertion counts with an explained accessor-related delta, names added tests separately, and requires direct inspection of original assertions in each converted file. |

### Validation

- Reviewed only the diff from `d30af22939912558645ce18f4525841af033f511` to the Reviewed-Commit and context reachable from the source findings; no unrelated findings introduced.
- Verified branch `change/harden-gate-floor`, HEAD equal to the Reviewed-Commit, and an initially clean worktree.
- `openspec validate harden-gate-floor --strict` passed.
- `scripts/pregate-verify.sh gate1 harden-gate-floor` passed with zero warnings; it does not establish the missing ownership-test coverage.
- This is confirmation of planning artifacts at Gate 1; implementation and the planned database tests remain for Gate 2.
