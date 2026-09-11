# Review — add-analytics-read-model

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-11
**Reviewed-Commit:** ff0c4836b7ef0e4f7008a718a12ced4d0ca1f21d
**Verdict:** changes-requested

### Findings

| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | openspec/changes/add-analytics-read-model/proposal.md:3; src/Shared/Demo/DemoSeedCommand.php:81 | The medium tier omits applicable high-tier triggers. The new command deletes existing accounts and their cascading links/clicks on reset, creates credentials and promotes an administrator; the new report provider also establishes an authorization boundary. AGENTS.md explicitly assigns deletion and changes touching authorization/input handling to high, even when existing hashers/voters are reused. No Gate 1 record exists and design.md lacks the required applicability table. Reclassify the actual scope as high, supply the required applicability and guard evidence, and obtain the required Gate 1 decision (or an explicit user waiver) before treating this change as merge-ready. | fixed |
| 2 | major | src/Analytics/Report/Period.php:38-40; tests/Unit/Analytics/PeriodTest.php:49 | Supplying only `to` changes the omitted `from`, contrary to the analytics requirement that the other bound keeps its default. With the test clock at 2026-09-11 and `to=2026-09-08T00:00:00Z`, the code and test select August 9 instead of the specified August 13, silently counting four extra days. Derive the omitted start from the default next-day boundary, and cover this over HTTP; reconcile FromParameter's description and sibling artifacts with the authoritative contract. | fixed |
| 3 | major | src/Analytics/Api/AdminSummaryReport.php:21,28-35 | The shared parameter requirement explicitly covers every global report and requires declared/validated `from` and `to` plus their echo. Admin summary declares only `includeBots` and has no `from`/`to` output fields, while AdminStatsProvider still passes its raw query through ReportRequestFactory's period parser. Consequently the dates affect validation and cache keys without appearing in OpenAPI or the response, and they bypass the declared DateTime constraints used by the other reports. Implement the shared contract with parameter declarations, echoed bounds and API tests, or explicitly revise the specification/design to define a coherent summary exception and stop parsing ignored dates. | fixed |
| 4 | major | openspec/changes/add-analytics-read-model/design.md:54-76; docs/explanation/requirements.md:315 | The recorded acceptance measurement fails the still-normative NFR-PERF-2: admin timeseries is 534 ms and top-links 810 ms (477 ms on another run), against p95 <= 300 ms uncached on one million clicks. The appendix's decision to accept these because they are admin-only/cached does not satisfy an uncached requirement; it also records different index use from the normative composite-index claim. Avoiding an ineffective index is reasonable, but does not close acceptance. Either meet the performance requirement with repeatable measurement or obtain explicit user acceptance of a revised requirement and reconcile the brief, proposal, design and task evidence. | fixed |

### Validation

Reviewed the branch diff against main, change artifacts, query services, API resources/providers, cache and processor integration, demo seeding, and associated tests; inspected installed API Platform/Symfony validation code. HEAD and branch match the requested review identity. `git diff --check main...HEAD` and `openspec validate add-analytics-read-model --strict` passed. Runtime checks were not rerun: Docker socket access is denied in this sandbox and no local PHP executable is available. The executor records 555 tests / 7273 assertions; this review does not independently certify that run. Only this review file was written; no git write commands were run.


## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-11
**Reviewed-Commit:** f2f86ac88a1125e2429e5477643fa8ea76594e87
**Verdict:** changes-requested

### Findings

| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | design.md:17; specs/analytics/spec.md, Timeseries report; tasks.md, task 1.2 | The planned bucket generator does not implement the accepted arbitrary RFC 3339 bounds: for day granularity, from=2026-09-01T12:00:00Z and to=2026-09-02T12:00:00Z generate a noon bucket, while every hit is grouped at midnight, so the documented join returns zero even with clicks in the interval. A window shorter than a bucket produces no buckets at all. The existing TimeseriesQuery already uses truncated boundaries, but neither the specification scenarios nor task 1.2 verifies partial first/last buckets, and the design still prescribes the faulty formula and incorrect maximum row counts (unaligned 14-day/hour and 366-day/day windows can touch 337 and 367 buckets). Specify intersecting UTC buckets with filtering against the original half-open bounds, reconcile the sketch and bounds with that contract, and add explicit implementation/verification coverage for partial buckets, including an interval shorter than one bucket, for link and global reports. | fixed |
| 2 | major | specs/demo-data/spec.md, Guards and re-runs; design.md, applicability Crash before/after an external effect; tasks.md, task 3.1 | The destructive reset promises that a seeding failure leaves no partial data, but task 3.1 verifies only successful creation/reset and refusals before writes. There is no verification task for failure after the old accounts have been deleted/flushed or after replacement rows have been written; the existing seed tests likewise exercise neither rollback case. For this high-tier deletion path, add a failure-injection scenario and verification task asserting that a failed reset preserves the original accounts, credentials, links and clicks and leaves no replacement data, plus the corresponding fresh-seed rollback case. Require demonstrated failure when the outer transaction is removed so the protection is actually exercised. Reconcile decision 10, which places Redis invalidation inside the transaction, with the applicability table and implementation, which place it after commit. | fixed |
| 3 | minor | proposal.md, What Changes 4; design.md, Context and decision 2's global SQL sketch; tasks.md, tasks 2.3 and 5.4; openspec/ROADMAP.md, row 8 | Sibling planning artifacts still describe superseded scope/state: the proposal and task 2.3 require uniqueVisitors in top-links, although the revised analytics specification explicitly removes global distinct counts; the global SQL sketch still inherits the link timeseries's uniques; design Context says medium tier/no Gate 1 and the roadmap still says medium. Task 5.4 is checked even though its own verification requires an approved/confirmed Gate 2 verdict and the only Gate 2 decision is changes-requested. Reconcile the current claims and checkbox state with the revised contract and actual review stream; keep historical measurements clearly historical. | fixed |

### Validation

Reviewed AGENTS.md, openspec/config.yaml, all change artifacts and delta specifications, the relevant brief/roadmap requirements, and the existing timeseries, cache and demo-seed code/tests to distinguish planning gaps from implementation choices. The branch and HEAD match the requested identity. `openspec validate add-analytics-read-model --strict` and `scripts/pregate-verify.sh gate1 add-analytics-read-model` passed (zero warnings). This is a Gate 1 artifact review, not a confirmation of Gate 2 round 1; runtime tests and performance measurements were not rerun. Only review.md was modified; no git write commands were run.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-11
**Reviewed-Commit:** 2c3c4951fc5ffd55fb457c3310f85119452794fc
**Verdict:** confirmed

### Findings

| # | Resolution |
|---|------------|
| 1 | confirmed — The timeseries requirement and scenario now specify every intersecting UTC bucket with clicks filtered by the original half-open bounds, including partial and sub-bucket windows for link and global reports. Decision 2 uses truncated series boundaries and correct 337/367 maxima. Task 1.4 supplies query and HTTP verification, with a recorded failing mutation using raw series bounds; the shared query implementation agrees with the corrected contract. |
| 2 | confirmed — The demo-data contract now includes failed-reset preservation and fresh-seed rollback scenarios. Task 3.2 and the test-only DBAL failure injector exercise failure after deletion and replacement writes, checking original account/link ids, click count and a working original password, or zero rows for a fresh seed. The outer-transaction removal failure is recorded in commit f0df821. Decision 10 now places counter/cache invalidation after commit, consistently with the applicability table and command. This closes the Gate 1 verification-plan gap. |
| 3 | confirmed — The named sibling claims now omit global distinct-visitor counts, declare high tier and Gate 1, and describe tasks 0.1/5.4 as recording review rounds rather than claiming passing verdicts. Historical performance evidence remains distinguished from the revised measurements. |

### Validation

Reviewed only f2f86ac88a1125e2429e5477643fa8ea76594e87..2c3c4951fc5ffd55fb457c3310f85119452794fc and collateral artifacts/code/tests reachable from the named Gate 1 findings, using AGENTS.md and openspec/config.yaml. Branch and HEAD match the requested identity; all source-round findings are dispositioned. The range's `git diff --check`, `openspec validate add-analytics-read-model --strict`, and `scripts/pregate-verify.sh gate1 add-analytics-read-model` passed (zero warnings). Runtime tests and mutation demonstrations were inspected in source and executor evidence, not rerun: Docker socket access is denied and no local PHP executable is available. This confirms Gate 1 round 1 only, not Gate 2. Only review.md was modified; no git write commands were run.


## Confirmation 1 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-11
**Reviewed-Commit:** 3c0c1d01e8340b0317f5f14d4c505efaa012fb45
**Verdict:** changes-requested

### Findings

| # | Resolution |
|---|------------|
| 1 | confirmed — The proposal now declares high tier with the applicable deletion, authorization and input-handling rationale; design.md supplies the applicability table. Guard tests and recorded failing mutations cover the named boundaries, including the added fresh-seed/reset rollback injection. Gate 1 Confirmation 1 is confirmed at 2c3c4951fc5ffd55fb457c3310f85119452794fc; subsequent changes before this reviewed commit are review/handoff records, without another scope change. |
| 2 | confirmed — Resolved by explicitly revising the period contract: an omitted from is 30 days before the effective to, while an omitted to retains the next-day default. The analytics requirement and one-bound scenario, brief, design and how-to now agree with Period::of, FromParameter and the unit test. LinkReportsTest adds HTTP assertions for both one-bound cases. This confirms the revised contract, not an implementation of the originally requested fixed default start. |
| 3 | confirmed — The specification and design explicitly exempt admin summary from period parameters and echo. AdminStatsProvider passes withPeriod: false, so ReportRequestFactory uses defaults without reading or parsing supplied dates; arbitrary dates cannot alter its cache key. The resource documents this exception and declares includeBots only. AdminStatsTest asserts the response shape and successful handling of malformed ignored from/to values. |
| 4 | changes-requested — The SQL changes remove global distinct counts, and the appendix records improved uncached p95 values of 119/75 ms on a fresh million-click seed; the available var/bench.php calls the shipped query services for 20 uncached runs. However, the resolution also changes the normative NFR-PERF-2 index requirement to allow the single-column FK index and removes previously specified global uniqueVisitors. No explicit user acceptance of that requirement revision is recorded in the reviewed artifacts or commit messages, although round 1 expressly required it for a revised requirement; the recorded user decision raises the risk tier only. The claim that the brief never requested global unique visitors is contradicted by FR-ANL-4 at the source commit, which explicitly includes them in top-links. In addition, checked task 5.1 still requires every per-link plan to use idx_clicks_link_occurred_human or idx_clicks_link_occurred, while its evidence names the FK index. Record explicit user acceptance of the revised requirement/scope (or meet the original contract), and reconcile task 5.1 and the historical rationale. Gate 1 confirmation does not supply user acceptance for this Gate 2 finding. |

### Validation

Reviewed only ff0c4836b7ef0e4f7008a718a12ced4d0ca1f21d..3c0c1d01e8340b0317f5f14d4c505efaa012fb45 and collateral code, tests and artifacts reachable from the four named major findings, using AGENTS.md and openspec/config.yaml. Branch and HEAD match the requested identity; all source-round findings are dispositioned. The range's git diff --check and openspec validate add-analytics-read-model --strict passed. Runtime tests, mutation demonstrations and performance measurements were inspected in source and executor evidence, not rerun: Docker socket access is denied and no local PHP executable is available. The benchmark script is a local ignored artifact, not part of the reviewed commit. Only review.md was modified; no git write commands were run.


## Confirmation 2 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-11
**Reviewed-Commit:** d734ef96f8e79008f9632d8edbab2d4924bdbc94
**Verdict:** changes-requested

### Findings

| # | Resolution |
|---|------------|
| 1 | confirmed — The proposal declares high tier and the applicable deletion, authorization and input-handling triggers; design.md supplies the applicability table. The named guards have tests and recorded failing mutations, including fresh-seed/reset rollback after replacement writes. Gate 1 Confirmation 1 records confirmed at 2c3c4951fc5ffd55fb457c3310f85119452794fc. This closes the source finding's omitted tier, applicability and Gate 1 decision. |
| 2 | confirmed — The revised contract explicitly derives an omitted from from the effective to minus 30 days, while an omitted to keeps the next-day default. Period::of, FromParameter, the analytics specification, brief, design and how-to agree, and LinkReportsTest asserts both one-bound cases over HTTP. As in Confirmation 1, this confirms the revised contract rather than the originally requested fixed default start. |
| 3 | confirmed — Admin summary is explicitly exempt from period parameters and echo. AdminStatsProvider passes withPeriod: false; ReportRequestFactory does not read supplied dates for this report, so they cannot affect validation or its cache key. The resource documents the exception and AdminStatsTest covers its shape and malformed ignored dates. |
| 4 | changes-requested — The proposal's User decisions and commit eab0416 now record acceptance of the global-timeseries and index-clause revisions; task 5.1 agrees with the FK-index evidence, and the historical rationale distinguishes main's brief from the source-round contract. However, restoring top-links uses a lossy replacement for the required distinct visitor_hash count: BreakdownQuery.php:100 groups by left(visitor_hash, 16), discarding 192 bits. For two clicks of the same link in the same period with hashes repeat('a', 16) + repeat('0', 48) and repeat('a', 16) + repeat('1', 48), the shipped grouping returns uniqueVisitors = 1 while the specified full-hash count is 2. These are valid 64-character hex values; the existing tests do not cover equal prefixes with different suffixes. The probability under normal SHA-256 generation is very low, as design decision 2 acknowledges, but this is still an approximate count, contrary to the recorded user decision that the original exact contract is met. The 260 ms measurement therefore does not demonstrate the promised exact report within the target. Preserve full-hash equality (with a same-prefix regression case) and remeasure, or obtain explicit acceptance of approximate top-links counts and reconcile the contract and exactness claims. This is collateral to the performance/scope fix, not an unrelated finding. |

### Validation

Reviewed only ff0c4836b7ef0e4f7008a718a12ced4d0ca1f21d..d734ef96f8e79008f9632d8edbab2d4924bdbc94 and collateral code, tests and artifacts reachable from the four named major findings, using AGENTS.md and openspec/config.yaml. Branch and HEAD match the requested identity; all source-round findings are dispositioned. The range's git diff --check and openspec validate add-analytics-read-model --strict passed. A read-only Python reproduction of the prefix equivalence returned two distinct full hashes versus one prefix; PostgreSQL execution of the corresponding VALUES-only CTE was attempted but Docker exec was denied access to the Docker socket. Runtime suites, mutation demonstrations and million-click performance measurements were inspected in source and executor evidence, not rerun. The benchmark script is a local ignored artifact, not part of the reviewed commit. Only review.md was modified; no git write commands were run.

Finding 4 has now failed two confirmations. Per AGENTS.md, stop the confirmation loop: split or reduce the change, or obtain user arbitration before proceeding.


## Confirmation 3 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-11
**Reviewed-Commit:** e1f872eb3203db095bac56e51c41b0066142da15
**Verdict:** confirmed

### Findings

| # | Resolution |
|---|------------|
| 1 | confirmed — The proposal declares high tier and identifies deletion, authorization and input-handling triggers; design.md supplies the applicability table. Tests and recorded failing mutations cover the named guards, including rollback after replacement writes during fresh seeding and reset. Gate 1 Confirmation 1 records confirmed at 2c3c4951fc5ffd55fb457c3310f85119452794fc. The source finding's missing tier, applicability and Gate 1 decision are resolved. |
| 2 | confirmed — The explicitly revised period contract derives an omitted from from the effective to minus 30 days, while an omitted to keeps the next-day default. Period::of, FromParameter, the analytics specification, brief, design and how-to agree; LinkReportsTest covers both one-bound cases over HTTP. This confirms the revised contract, rather than the originally requested fixed default start. |
| 3 | confirmed — Admin summary is explicitly exempt from period parameters and echo. AdminStatsProvider passes withPeriod: false, so ReportRequestFactory neither reads nor parses supplied dates for this report; those values cannot affect validation or its cache key. The resource documents the exception, and AdminStatsTest covers the response shape and malformed ignored dates. |
| 4 | confirmed — The proposal records user acceptance of the global-timeseries and index-clause revisions, and user arbitration choosing exact top-links counts and authorising this third confirmation. BreakdownQuery now groups by the complete non-null visitor_hash under COLLATE "C", then counts distinct link/hash pairs per link; it no longer truncates the hash. The same-prefix regression test asserts three clicks and two unique visitors, including a repeated full hash; commit 88d8b8f records that restoring the prefix makes this test fail. Period/bot filtering, click totals, ranking and limiting remain consistent with the report contract. The design appendix records a fresh million-click measurement through the shipped query service: top-links p95 239 ms uncached, with global timeseries 119 ms; the 300 ms target is retained. Task 5.1 matches the accepted index contract and FK-index evidence, and the historical rationale distinguishes the source-round contract from the earlier brief. The exactness and acceptance objections from the previous confirmations are resolved. |

### Validation

Reviewed only ff0c4836b7ef0e4f7008a718a12ced4d0ca1f21d..e1f872eb3203db095bac56e51c41b0066142da15 and collateral code, tests and artifacts reachable from the four named major findings, using AGENTS.md and openspec/config.yaml. Branch and HEAD match the requested identity; all source-round findings are dispositioned. The range's git diff --check and openspec validate add-analytics-read-model --strict passed. Runtime tests, failing mutations and performance measurements were inspected in source and executor evidence, not rerun: Docker socket access is denied and no local PHP executable is available. The executor records make check green with 559 tests / 7331 assertions. The inspected var/bench.php invokes the shipped query services directly for 20 uncached runs; it is an ignored local artifact, not part of the reviewed commit. Only review.md was modified; no git write commands were run.
