# Review — add-api-keys-and-rate-limiting

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-12
**Reviewed-Commit:** b7678a00949273e46900c568b0f2644abf3b9ddb
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | design.md:20,31,40; specs/api-keys/spec.md — Create an API key and see it once; tasks.md — 3.1 | The deliberately unlocked count-and-insert permits two concurrent requests at nine active keys to create eleven, contradicting both FR-KEY-1 and the delta requirement that the request creating an eleventh key returns 409 and creates nothing. Calling this accepted housekeeping and using “MAY hold at most” does not specify an exception to that requirement. Serialize creation per owner across the count and insert in one transaction, and add a concurrent-creation verification that leaves exactly ten active keys with one success and one 409. Alternatively, obtain explicit acceptance of a relaxed requirement and reconcile all affected artifacts before implementation. | fixed |
| 2 | major | design.md:21; tasks.md — 4.1; specs/api-keys/spec.md — Per-identity API rate limit | A kernel.request subscriber at priority 7 runs after the entire firewall, including access_control enforcement, not just authentication. The installed Firewall runs at priority 8 and its AccessListener throws on denied roles. Thus a valid ordinary user's key or JWT repeatedly requesting an existing /api/v1/admin/ operation receives 403 before the limiter executes: no tokens consumed, no limit headers, and no eventual 429. This contradicts the promised coverage of every authenticated non-auth API request. Specify a hook after successful authentication but before access-control denial, retaining once-per-main-request consumption and response headers; add HTTP tests for repeated role-denied requests with both credential types, including exhaustion. | fixed |
| 3 | major | design.md:22; tasks.md — 5.1; specs/health-check/spec.md — Deep dependency probe | Production authorization now calls findActiveByHash through the application repository before HealthProbe can run. The application Doctrine connection has no bounded connect/query timeout in config/packages/doctrine.yaml; the existing probe deliberately uses separate timeout-bounded connections. A correctly shaped key against an unresponsive database can therefore hang in authorization before reaching the probe's two-second checks. Saying the resolver “throws nothing” neither bounds a hung call nor defines how database failure differs from an invalid credential; task 5.1 tests neither failure nor hang. Define bounded credential lookup and a fail-closed response when authorization cannot be established, reconcile the production database-failure behavior with the health spec, and add production tests for refused connections and a stalled lookup that assert the response budget and no unauthorized probe execution. | fixed |

Validation: branch and HEAD match the requested identifiers; the working tree was clean before review. Read the proposal, design, tasks, all four delta specs, handoff, AGENTS.md and openspec/config.yaml; checked the relevant installed Symfony firewall/authenticator code and current security, Doctrine and health-probe implementation. `openspec validate add-api-keys-and-rate-limiting --strict` passed. This is an artifact review; implementation checks belong to Gate 2.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-12
**Reviewed-Commit:** f576c8ba9953201f80079aebaeb29d117f530d13
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Design decision 5 now serializes creation on the owner's row inside the count-and-insert transaction, with a fresh READ COMMITTED snapshot for the count after acquiring the lock. The proposal and API-key requirement retain the strict ten-key cap under concurrency; task 3.1 verifies twelve concurrent creations from nine committed active keys yield one success, eleven conflicts and exactly ten active keys, and requires demonstrating the failure without the lock. |
| 2 | confirmed — Design decisions 4 and 6 move identity consumption to LoginSuccessEvent, carrying the key identity in UserBadge attributes and returning an exhaustion response before access control. The installed AuthenticatorManager dispatches this event and returns its response before the firewall's access listener runs. Task 4.1 explicitly covers both key and JWT role-denied requests, decreasing headers on 403 responses, eventual 429, and a failing input that moves consumption back to priority 7. Main-request filtering and the response header writer are retained. |
| 3 | changes-requested — The separate bounded connection and fail-closed 404 policy resolve the unbounded application-repository lookup and define database-failure behavior, but the response budget and its required verification remain incomplete. Design decision 7 permits about 2 seconds connecting plus 2 seconds querying; the new production database-down scenario in specs/health-check/spec.md requires a response within 3 seconds of lookup start. A connection completing near its timeout followed by a statement timeout can exceed that scenario's budget. Reconcile the total deadline across design, spec and tests. Also, task 5.1 only tests an unreachable URL returning false and a standalone pg_sleep query on the resolver's connection; its production HTTP test matrix contains neither refused connections nor a stalled credential lookup. Add planned production HTTP tests for both failures through resolveAdmin, asserting the agreed elapsed-time budget, identical 404 problem details with no-store, the warning, and that dependency checks never execute. A standalone connection timeout test does not verify those controller and resolver guarantees requested in round 1. |

Validation: reviewed only `b7678a00949273e46900c568b0f2644abf3b9ddb..f576c8ba9953201f80079aebaeb29d117f530d13` and collateral relevant to findings 1–3. All source findings were dispositioned as fixed; branch and HEAD match the request, and the working tree was initially clean. Checked the affected artifacts, repository guidance, installed Symfony authentication event and firewall ordering, and the existing health connection factory and tests. `openspec validate add-api-keys-and-rate-limiting --strict` passed. This confirmation assesses the Gate 1 plan; implementation and demonstrated failing inputs remain Gate 2 work.

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-12
**Reviewed-Commit:** b2b8d9142caa8284626636e930de3483252e6688
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Decision 5 serializes creation on the owner's row inside the count-and-insert transaction, with a fresh READ COMMITTED snapshot after locking. The proposal, API-key requirement and task 3.1 agree on the strict cap; the planned concurrent test starts at nine committed keys and requires one success, eleven conflicts and ten active keys, with a demonstrated failure without the lock. |
| 2 | confirmed — Decisions 4 and 6 use LoginSuccessEvent and the passport's key identity before access control; the installed AuthenticatorManager returns the event's response before the access listener. Task 4.1 covers both key and JWT role-denied requests, decreasing headers on 403 and eventual 429, and a failing input restoring priority 7. Main-request filtering and response headers remain specified. |
| 3 | changes-requested — Decisions 7–8, task 5.1 and the health delta now include production HTTP refusal and locked-table cases, but the revised deadline still relies on an unsupported assumption: PDO ATTR_TIMEOUT=1 maps to libpq connect_timeout, whose minimum is 2 seconds (1 is interpreted as 2; see the PostgreSQL documentation below). Thus the stated connect <= 1 s and total lookup <= 2 s do not follow from this mechanism; a slow successful connection followed by a 1-second statement timeout also removes the claimed margin under the 3-second HTTP budget. A closed port returns immediately and the locked-table case establishes its connection normally, so neither tests that combined delay. Reconcile the budget with the driver's actual minimum, or specify an enforceable total deadline, and plan a delayed-connection plus stalled-lookup case. Also correct the claimed failing input: raising only statement_timeout to 2 s on a normally connected locked-table test need not exceed its 3-second assertion; removing the timeout or asserting the actual statement bound would demonstrate the guard. |

Evidence for finding 3: the existing `src/Shared/Health/HealthProbe.php::databaseConnection()` documents the PDO-to-libpq mapping; [PostgreSQL 16 connection parameters](https://www.postgresql.org/docs/16/libpq-connect.html#LIBPQ-CONNECT-CONNECT-TIMEOUT) specify the two-second minimum and a separate timeout per host/address.

Validation: reviewed only `b7678a00949273e46900c568b0f2644abf3b9ddb..b2b8d9142caa8284626636e930de3483252e6688` and collateral relevant to findings 1–3. All source findings were dispositioned as fixed; branch and HEAD match the request, and the working tree was initially clean. Checked the affected planning artifacts, repository guidance, installed Symfony authentication/firewall ordering, current health controller and connection factory, and the driver's documented connection-timeout semantics. `openspec validate add-api-keys-and-rate-limiting --strict` passed. This is a Gate 1 artifact confirmation; implementation tests remain Gate 2 work.

Finding 3 has now failed two confirmations. Per AGENTS.md, stop the confirmation loop: split or reduce the change, or ask the user to arbitrate before proceeding.
