# Review — authorize-deep-probe-by-api-key

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-12
**Reviewed-Commit:** 8b8fbd55206bd1242f4e6b9846edc91f9f6169db
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | design.md decisions 2–4; tasks.md 1.1, 2.1, 3.1; specs/health-check/spec.md verification budget | The proposed synchronous clients do not enforce the promised total deadline. A connection can complete and then a proxy can stop forwarding responses: server-side `statement_timeout` cannot make the waiting PDO client receive the cancellation, and checking `Deadline` before the blocking call cannot interrupt it. DNS is also outside the proposed connect timeout. Redis receives the same allowance for connect and subsequent reads, including AUTH and the memory command, so their accumulated duration can exceed the remaining budget. Specify a mechanism that bounds the entire operation, including response transport and each Redis operation, and state any resolver exclusion consistently with the request-level guarantee. Add a post-handshake black-hole test and delayed Redis AUTH/command tests. The existing lock test covers a responsive server cancelling a query, not a lost response. Also replace the claimed mutation witness for the connect-floor guard: the single connect starts with approximately 4 seconds available, so removing that guard cannot make the 1.5-second delayed-connect plus 2-second statement test exceed 5 seconds. | fixed |
| 2 | major | design.md decision 4 and Applicability concurrent writers; specs/health-check/spec.md revocation scenario | `SET`/`DEL` do not converge to the last database answer, and a failed `DEL` does not mean the stored entry is absent. Concrete race: request A reads a valid key; revocation commits; request B reads the revoked key, deletes the memory and returns 404; A then performs its delayed SET; a subsequent database outage restores access. Independently, a Redis interruption during B's DEL leaves the old entry available after Redis recovers and the database fails. Both contradict the explicit scenario that an observed revocation cannot be undone by a subsequent outage. Define an ordering/invalidation mechanism and its failure behavior, or explicitly revise the authorization contract and trade-off to admit these cases. Cover both interleavings with deterministic tests and mark concurrent writers applicable. Anchor the 300-second lifetime to verification time if that is the promised bound, rather than to a potentially delayed SET. | fixed |
| 3 | major | design.md decisions 2–4; proposal.md item 3; specs/health-check/spec.md outage memory requirement | The remembered-monitor guarantee is broader than the planned execution path. A delayed connection plus a timed-out lookup can consume the entire authorization budget, at which point Redis consultation is explicitly skipped even for a remembered key, producing 404 instead of the promised outage report. Also, an unavailable authorization query does not imply the unchanged probe will report `database: fail`: an ACCESS EXCLUSIVE lock on `api_keys` times out the lookup while the probe's `SELECT 1` succeeds, and a transient connection failure can recover before the separate probe connection. Specify whether fallback is best effort or reserve an enforceable consultation budget, and distinguish authorization lookup failure from the actual dependency report. Align the normative outcome and docs accordingly; test remembered keys with exhausted lookup budgets and table-lock failures, not only an immediately refused port. | fixed |

### Validation
- Confirmed the requested branch and HEAD; the worktree was clean before this review.
- `scripts/pregate-verify.sh gate1 authorize-deep-probe-by-api-key` passed, including strict OpenSpec validation.
- Reviewed proposal, design, tasks, delta specification, repository review rules/configuration, and the existing raw-client health probe and API-key extractor/hash implementation. These are planning findings; implementation has not been reviewed.
- Timeout semantics checked against PostgreSQL 16 documentation: [connection control](https://www.postgresql.org/docs/16/libpq-connect.html) and [statement timeout](https://www.postgresql.org/docs/16/runtime-config-client.html#GUC-STATEMENT-TIMEOUT). The transport-stall counterexample in finding 1 follows from the distinction between server execution cancellation and receiving a response at the client.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-12
**Reviewed-Commit:** c1b35dd8d97a4beb4d883c3a5d43d7eecaa370f3
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — The async receive loop, resolver exclusion and revised connect-deadline witness address parts of the finding, but design decision 3 and task 1.2 still do not bound the complete operation. The prescribed timeout cleanup calls `pg_cancel_query()` (incorrectly described as non-blocking), then `pg_close()`. PHP 8.4's cancellation wrapper calls `PQcancel` and drains `PQgetResult`; closing also drains `PQgetResult` before `PQfinish`. A proxy that swallows the original connection's responses can therefore hold cleanup after the deadline, even if the cancellation reaches the server. Specify and verify a bounded abort/cleanup path, including destruction, before claiming the 5-second refusal. The planned Redis black hole also stops at AUTH for a password-protected connection: add the requested successful-but-delayed AUTH followed by delayed GET/EVAL test to exercise cumulative operation timeouts. |
| 2 | changes-requested — The failed-invalidation case is now explicitly admitted and verification age is checked independently of SET time, but the new CAS does not guarantee the promised revocation ordering. In design decisions 3–4, `clock_timestamp()` timestamps expression evaluation, not the SELECT's snapshot. Counterexample: A starts with a pre-revocation snapshot and pauses before evaluating its timestamp; revocation commits; B reads the revoked row and stores `d@t2`; A resumes, still reads the valid version from its old snapshot, and emits `v@t3` with t3 > t2. The CAS accepts A and an outage restores access despite B's successful denial write. The synthetic memory test supplies t1 < t2 and assumes precisely the property the SQL does not provide. Define ordering tied to authoritative authorization state, or explicitly relax the race guarantee consistently in proposal/spec/design; add a deterministic database-level interleaving test that establishes the ordering rather than supplying it. |
| 3 | confirmed — Design decisions 2 and 5 reserve a separate Redis allowance after an exhausted database phase and distinguish lookup failure from the probe's own dependency observations. The delta spec and tasks 2.1/3.1 cover consultation after budget exhaustion and a remembered key during an api_keys table lock, with the probe reporting its own SELECT 1 result. This resolves the planning issue in finding 3; the full deadline remains subject to finding 1. |

### Evidence and validation
- Reviewed only the requested commit interval and collateral relevant to findings 1–3. All three source findings were marked `fixed`; no unrelated findings were added.
- The cleanup counterexample follows from [PHP 8.4 ext-pgsql source](https://raw.githubusercontent.com/php/php-src/PHP-8.4/ext/pgsql/pgsql.c), specifically `php_pgsql_do_async`, `pg_close`, and `pgsql_link_free`, and the documented blocking behavior of `PQgetResult` in [libpq asynchronous processing](https://www.postgresql.org/docs/16/libpq-async.html). Merely making the connection non-blocking does not make result retrieval safe while a response is pending.
- The ordering counterexample follows from PostgreSQL's [Read Committed snapshot semantics](https://www.postgresql.org/docs/16/transaction-iso.html#XACT-READ-COMMITTED) and [clock_timestamp semantics](https://www.postgresql.org/docs/16/functions-datetime.html#FUNCTIONS-DATETIME-CURRENT): current wall time can advance during one statement while its visible row version stays fixed.
- `scripts/pregate-verify.sh gate1 authorize-deep-probe-by-api-key` passed, including strict OpenSpec validation. These are artifact-level conclusions; no implementation or runtime failure demonstration is claimed.
- Only `review.md` was modified; no git write commands were run.

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-12
**Reviewed-Commit:** fb42073450832338a0dd0db3f41c14beab1d5ac3
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — The child-process deadline addresses the PostgreSQL cleanup objection and replaces the invalid connect-floor mutation witness. However, design decision 2 budgets only connect, AUTH and one command at 1/3 second each, while decision 4 and task 2.1 require a generation GET before the lookup and another command afterwards; denial additionally requires INCR, TTL refresh and DEL. Even a reused connection therefore has more operations than the budget accounts for. For example, connect, AUTH, generation GET and consultation GET each completing in 0.30 seconds already consume 1.20 seconds, exceeding the normative reserved 1-second Redis allowance. Specify a complete operation budget, including pre-lookup access and denial writes, that preserves the post-lookup consultation allowance. Test the whole sequence with successful delayed operations, including connect; the current 0.5-second response-delay fixture times out at AUTH under the prescribed 1/3-second timeout and cannot exercise the claimed later-command timeout. Align design, spec and tasks. |
| 2 | changes-requested — Reading a generation before the statement fixes the snapshot-timestamp race only while generations cannot be reused. Decision 4 expires the counter after 300 seconds, breaking its monotonicity argument within an otherwise short request: an earlier denial left generation 1 near expiry; the key is currently valid (for example, its owner was unblocked); A reads generation 1 and a valid row; the counter expires; the key is revoked; B observes that revocation, INCR recreates generation 1 and DEL succeeds; A's CAS now accepts generation 1 and restores the memory. An outage restores access despite B's successful denial. Use a generation identity that cannot recur across expiry, or otherwise reject writes spanning counter expiry, and test this interleaving deterministically. Also retain the source finding's verification-age bound: the new value contains only gen and key expiry, and starts a fresh 300-second TTL at the delayed write, so it no longer enforces 300 seconds from verification. Define an absolute verification-age limit or explicitly revise that contract consistently. |
| 3 | confirmed — Decision 5 and the delta spec continue to distinguish authorization lookup failure from the probe's own dependency observations. Tasks 2.1 and 3.1 retain consultation after a timed-out lookup and the remembered-monitor table-lock case with the probe reporting its own SELECT 1 result. The separate post-lookup consultation allowance remains the intended contract; its incomplete Redis accounting is tracked under finding 1 rather than duplicated here. |

### Evidence and validation
- Reviewed the requested interval `8b8fbd55206bd1242f4e6b9846edc91f9f6169db..fb42073450832338a0dd0db3f41c14beab1d5ac3` and collateral reachable from findings 1–3. Confirmed the branch and HEAD and an initially clean worktree. All source findings were marked `fixed`; no unrelated findings were introduced.
- Read the revised proposal, design, tasks and health-check delta, and checked related claims in repository documentation. The Redis counterexample follows directly from the specified expiring counter, INCR recreation and equality-only CAS; it does not require a request lasting 300 seconds or a failed denial write.
- Inspected the installed `vendor/symfony/process/Process.php` timeout and stop paths. The process boundary provides a concrete way to avoid running blocked PostgreSQL cleanup in the request process; runtime verification remains an implementation task.
- `scripts/pregate-verify.sh gate1 authorize-deep-probe-by-api-key` passed, including strict OpenSpec validation. This is a planning review; no implementation or runtime failure demonstration is claimed.
- Findings 1 and 2 have now failed two confirmations. Per AGENTS.md, stop the confirmation loop: split or reduce the change, or ask the user to arbitrate before proceeding.
- Only `review.md` was modified; no git write commands were run.

## Confirmation 3 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-12
**Reviewed-Commit:** 88963059488ce49e3ca87bf799efeadca87b99ff
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — Decision 2 now accounts for the pre-lookup access and consolidates denial writes into one EVAL, giving four operations with a reserved post-lookup share. The process boundary retains the PostgreSQL cleanup fix. However, the required Redis failure demonstrations in design decisions 7/9 and tasks 2.1/3.1 still do not exercise their claimed conditions. On a password-protected connection the RESP sequence is AUTH (command 1), generation GET (2), consultation GET or EVAL (3); TCP connect is not a RESP command. Therefore `--slow-from-command=4` never delays the consultation, and an ordinary missing-value 404 can falsely pass the end-to-end timing assertion. Also, delaying accept on a listening TCP socket does not delay the client's handshake: the connection can complete into the kernel's accept queue, so `--accept-delay=0.5` stalls AUTH, not connect. A per-response 0.2-second delay likewise does not exercise a slow connect. Correct the command index, assert that the intended post-lookup operation actually timed out after successful delayed AUTH and generation GET, and specify a feasible connect-delay/timeout fixture with phase assertions. Retain a complete successful delayed sequence and a guard-removal witness for the Redis bound. These are outstanding verification requirements of this finding, not unrelated test refinements. |
| 2 | confirmed — Decision 4 and task 2.1 replace the expiring integer counter with a fresh random denial token and an atomic token-replacement-plus-delete EVAL. The pre-lookup token comparison rejects the named stale-snapshot and counter-recreation interleavings; the deterministic database/Redis tests cover both. The value again includes verified_at, independently checked against the 300-second age bound at consultation, with a delayed-write/old-value test. The failed-invalidation case remains explicitly admitted in proposal, design and delta specification. This resolves the named planning issues; implementation verification remains for Gate 2. |
| 3 | confirmed — The fixed Redis sequence reserves the post-lookup command's allowance independently of the database timeout. Decision 5, the delta specification and tasks 2.1/3.1 retain consultation after an exhausted lookup phase and the remembered-monitor table-lock case, with the probe reporting its own SELECT 1 observation rather than treating every lookup failure as database: fail. The remaining failure-fixture issue is tracked under finding 1. |

### Evidence and validation
- Reviewed only `8b8fbd55206bd1242f4e6b9846edc91f9f6169db..88963059488ce49e3ca87bf799efeadca87b99ff` and collateral reachable from findings 1–3. Confirmed the requested branch and HEAD and an initially clean worktree. All source findings were marked `fixed`. The proposal records user arbitration authorizing this third confirmation; the current request explicitly requests it.
- The command-count counterexample follows directly from the fixture's specified RESP command sequence. Linux documents that the listen backlog contains completely established connections waiting for accept, supporting the accept-delay counterexample: [listen(2)](https://www.man7.org/linux/man-pages/man2/listen.2.html). An attempted local socket demonstration was blocked by the sandbox at socket creation (`PermissionError`); no successful runtime demonstration is claimed.
- `scripts/pregate-verify.sh gate1 authorize-deep-probe-by-api-key` passed, including strict OpenSpec validation. This is a planning confirmation, not an implementation review.
- Finding 1 remains unresolved after the user-authorized third confirmation. Stop and return to user arbitration as specified in the handoff; do not automatically continue the confirmation loop.
- Only `review.md` was modified; no git write commands were run.

## Confirmation 4 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-12
**Reviewed-Commit:** d0528f88be7554b09fd49181ee6652e40d3fe354
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Decisions 2–3 retain the bounded child-process lookup and the complete four-operation Redis budget, with a separate post-lookup share and an explicit resolver exclusion. Decisions 7/9 and tasks 2.1/3.1 now delay RESP command 3, assert successful AUTH and token GET through the command log, and identify the timed-out consultation through MemoryUnavailable.operation. The successful 0.2-second-per-command sequence exercises accumulated read time; the full-backlog listener separately exercises connect timeout with phase and duration assertions. Removing the Redis timeout has an explicit failing witness. These changes resolve the outstanding fixture mechanics as well as the source finding's deadline and verification-plan objections. |
| 2 | confirmed — The pre-lookup random denial token and atomic replacement-plus-delete EVAL retain the ordering fix without expiring-counter reuse. Tasks cover the real lookup/revocation/denial/stale-write interleaving and token expiry/recreation. Consultation checks verified_at independently of the storage TTL and checks key expiry; a failed denial write and its bounded stale-access consequence remain explicitly admitted. The reviewed changes do not regress the previously confirmed resolution. |
| 3 | confirmed — The fixed Redis sequence reserves the consultation command independently of the 2.5-second lookup allowance. The specification and tasks retain fallback after lookup timeout and the remembered-monitor table-lock case; the probe reports its own dependency observations, without equating every authorization lookup failure to database: fail. The reviewed changes preserve this resolution. |

### Evidence and validation
- Reviewed only `8b8fbd55206bd1242f4e6b9846edc91f9f6169db..d0528f88be7554b09fd49181ee6652e40d3fe354` and collateral reachable from findings 1–3. Confirmed the requested branch and HEAD and an initially clean worktree. All source findings were marked `fixed`; no unrelated findings were introduced. The proposal records user arbitration authorizing this fourth confirmation, also explicitly requested in this invocation.
- Checked the revised fixture's feasibility against Linux source: the accept queue is full when its length exceeds the configured backlog ([sk_acceptq_is_full](https://raw.githubusercontent.com/torvalds/linux/v6.12/include/net/sock.h)), and a new connection request is dropped when that queue is full ([tcp_conn_request](https://raw.githubusercontent.com/torvalds/linux/v6.12/net/ipv4/tcp_input.c)). This supports the planned listen(0) plus one parked connection mechanism; runtime behavior still requires the planned phase assertions in the implementation environment.
- Rechecked the installed `vendor/symfony/process/Process.php` timeout/stop path and the related proposal, design, tasks and delta-spec claims.
- `scripts/pregate-verify.sh gate1 authorize-deep-probe-by-api-key` passed, including strict OpenSpec validation. This confirms the planning resolutions at Gate 1; implementation tests and guard-removal demonstrations remain Gate 2 work.
- Only `review.md` was modified; no git write commands were run.
