# Review — stretch-partition-clicks

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** fe05c781cb1db086dabe35da4a41ed625a5fb835
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | design.md decisions 3 and 5, Risks / Trade-offs; tasks.md sections 2, 6 and 8 | The fresh-database path cannot satisfy the promised unchanged demo and analytics suites. The migration creates only the current month when empty, and maintenance creates only current/future months. `DemoSeedCommand` subsequently inserts the preceding 60 days; existing analytics tests insert historical dates including March 2026. Those months are absent on a fresh migration, so these inserts fail. Converting an already seeded local database masks the defect. Specify how historical partitions are provisioned for seeding, test fixtures and valid delayed messages, reconcile the spec's always-present future horizon with migration initialization, and add fresh-database seed/test verification independent of the populated conversion benchmark. | fixed |
| 2 | major | specs/click-logging/spec.md, Click messages are handled idempotently; design.md decisions 2 and 6 and Applicability | The unconditional acknowledgement guarantee for redeliveries/manual retries does not survive retention. After a successfully recorded message's month is dropped, replay hits the missing-partition error and is parked rather than acknowledged. Recreating that month to recover messages instead removes the only deduplication evidence and permits a second lifetime-counter increment. The unchanged deleted-link policy also promises first-attempt acknowledgement, but an absent partition prevents reaching the handler's FK guard. Define the post-retention policy for duplicates, delayed first deliveries and deleted-link messages, including recovery after a missing partition; reconcile the affected requirements and handler scope, and add explicit verification for these cases. | fixed |
| 3 | major | design.md decisions 5–6 and Applicability; tasks.md section 4 | The destructive command has no defined valid range or fail-closed validation for its retention/horizon configuration. For example, a negative retention window moves the cutoff into the future and can make the current populated month eligible for deletion; keeping a straddling month does not protect against this. The applicability table addresses an empty table but omits empty, zero, negative and malformed configuration. Define accepted values and validate all configuration before any DDL, with tests proving invalid input leaves partitions and rows unchanged and demonstrated failing inputs for each new guard. | fixed |
| 4 | minor | specs/analytics/spec.md, Summary report | “Retained means: within the configured retention window” contradicts whole-month retention and on-demand execution. A straddling partition deliberately retains rows older than the cutoff, and an unrun command retains all history. Define retained as rows still present after completed retention runs, with the window determining partition eligibility, so the unchanged SQL and published contract agree. | fixed |

Validation: `scripts/pregate-verify.sh gate1 stretch-partition-clicks` passed, including strict OpenSpec validation. Branch and HEAD match the requested review. This is an artifact review; implementation checks belong to Gate 2.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** 4cef6e59563312071cfeaac9e193448a3558d702
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — Migration initialization now covers the retention window and future horizon, and task 2.2 adds independent fresh-database verification. However, task 4.1 still implements only current/future months, contradicting design decision 5 and the new maintenance scenario; its verification never asserts creation of missing historical partitions. Reconcile that implementation task and test coverage. Also, design decision 5 explicitly accepts failure of fixed-date fixtures once they age outside the window, while task 6.2 and the proposal still promise unchanged passing analytics suites. Define a stable fixture provisioning or clock strategy so the fresh-database fix does not expire when March 2026 falls outside the window. |
| 2 | changes-requested — The pre-insert age guard resolves old first deliveries and post-retention retries only while the cutoff never moves backwards. The window remains configurable: after dropping July 2026 with a one-month window in September, increasing it to thirteen months causes the new backward provisioning to recreate July; replay then passes the age guard and increments the lifetime counter again because its original row is gone. Define a durable expiry boundary or another mechanism that prevents resurrection after configuration changes, and add a verification case. The explicitly named deleted-link case also remains unresolved: an in-window message for a deleted link with no partition still fails before the unchanged FK guard, contradicting the existing first-handling acknowledgement requirement. Reconcile that requirement, handler scope and missing-partition recovery tests; task 3.4 contains no deleted-link case, and task 3.2 still calls the handler unchanged. |
| 3 | confirmed — Design decision 6 and the retention requirement define both settings as positive whole months and require validation before any schema-changing statement. Task 4.5 explicitly covers zero, negative, empty and nonnumeric values for each setting, with nonzero exit, a named setting, and unchanged partitions and row counts. These are the required Gate 1 implementation and failing-input verification commitments. |
| 4 | confirmed — The analytics delta now defines retained clicks as rows still present, explicitly includes older rows in a straddling month and all history before maintenance runs, and task 5.0 verifies both cases. |

Validation: reviewed only `fe05c781cb1db086dabe35da4a41ed625a5fb835..4cef6e59563312071cfeaac9e193448a3558d702` and collateral requirements/source reachable from the named findings. Branch and HEAD match the request. `scripts/pregate-verify.sh gate1 stretch-partition-clicks` passed, including strict OpenSpec validation. This confirmation evaluates planning artifacts; implementation verification remains for Gate 2.

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** be353c1f12733d7c9f1469b9f4e02e7f9780efbb
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Migration initialization and maintenance now cover the retention window and future horizon. Task 4.1 explicitly verifies recreation of a missing historical partition; task 2.2 verifies seeding on an independently created fresh database. Design decision 5 and task 6.3 additionally provision a declared fixed fixture range through `make test-db`, using the shared partition function, so the analytics fixtures remain insertable after their dates leave the production window. These concrete implementation and verification commitments resolve the two gaps identified in Confirmation 1. |
| 2 | confirmed — Design decision 5b and the click-logging delta define the expiry boundary as the later of the configured cutoff and a durable, never-decreasing boundary of removed data. The command records that boundary transactionally with the drops; tasks 3.5 and 4.5 cover replay after window expansion and atomic persistence of the boundary. Task 3.4 covers expired first deliveries, redeliveries, manual retries, and the deleted-link/missing-partition recovery case. The deleted-link requirement explicitly qualifies acknowledgement with the missing-partition precedence, and task 3.2 now permits the handler's expiry guard. |
| 3 | confirmed — Both configuration settings must be positive whole numbers of months, validated before any schema-changing statement. Task 4.6 retains explicit failing inputs for zero, negative, empty and nonnumeric values for each setting, with nonzero exit, a diagnostic naming the setting, and unchanged partitions and row counts. The resolution confirmed previously remains intact. |

Validation: reviewed only `fe05c781cb1db086dabe35da4a41ed625a5fb835..be353c1f12733d7c9f1469b9f4e02e7f9780efbb` and collateral requirements/source reachable from the named findings. Branch and HEAD match the request. `scripts/pregate-verify.sh gate1 stretch-partition-clicks` passed, including strict OpenSpec validation. This confirms the Gate 1 planning resolutions; implementation and execution of the promised tests remain for Gate 2. Finding 4 was already confirmed in Confirmation 1; no unrelated findings are introduced.

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** 608aa53d52b9a1f67dc794032f91d6864c54e6ff
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | src/Click/Retention/ClickPartitionsCommand.php:147–148; tests/Integration/Click/ClickPartitionsCommandTest.php | The durable removal boundary records the configured cutoff, not the upper bound of the partitions actually dropped. For example, a two-month run on September 16 drops June and earlier, keeps July, but records July 16 instead of July 1. After widening the window to thirteen months, a delayed first delivery from July 10 is permanently acknowledged and discarded even though its month was never removed and it is inside the expanded window. This contradicts the explicit spec scenario that the recorded boundary is the end of the last removed month. Record the greatest actual upper bound among the dropped partitions, preserving the transactional, never-decreasing update. Add a test asserting the exact boundary and acceptance of a first delivery in the surviving straddling month after widening; the existing test checks only non-nullness and monotonicity. | open |
| 2 | major | src/Click/Handler/ClickRecordedHandler.php:50–58; src/Click/Retention/ClickPartitionsCommand.php:138–148 | The expiry check and the insert are not synchronized with retention: the boundary is read before the insert transaction and no lock protects that decision. A worker using the thirteen-month window can accept a replay of an already recorded July click, pause after the guard, then resume after a short-window maintenance run drops July and a subsequent expanded-window run recreates it. Its insert now succeeds and increments the lifetime counter a second time, despite the durable boundary committed by retention. This is the configuration-change replay case the change explicitly promises to prevent, with an in-flight handler added; sequential tests miss it. Make the eligibility decision and insert atomic relative to destructive maintenance/reprovisioning through an appropriate shared locking or database mechanism, and add a deterministic two-connection regression test that pauses the handler at this boundary and proves the replay cannot increment again. Merely moving the read inside a transaction without synchronization is insufficient. | open |

Validation: reviewed `git diff main...change/stretch-partition-clicks`, the change artifacts, `openspec/config.yaml`, and the relevant handler, maintenance, migration and test code. Branch and HEAD match the requested commit; the worktree was initially clean. `scripts/pregate-verify.sh gate2 stretch-partition-clicks` passed whitespace, strict OpenSpec validation, task and document checks, but could not run `make check`: access to `/var/run/docker.sock` is denied in this sandbox. No host PHP executable is available. The recorded executor/CI results were read, not independently reproduced; the two findings above are source-level counterexamples, not executed database reproductions. Only `review.md` was modified; no git write command was run.
