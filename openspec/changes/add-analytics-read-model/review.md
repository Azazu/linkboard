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
