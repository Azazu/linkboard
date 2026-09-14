# Review — add-web-admin-and-stats

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-14
**Reviewed-Commit:** 562ff2452c9c8daa0f940db9493c2d679429a836
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | specs/web-ui/spec.md:23; tasks.md:26 | The modified no-JavaScript requirement now requires every charted number on every page to be available as text. The existing dashboard renders its daily series only through `render_chart(chart)` (`templates/dashboard/index.html.twig`); its totals and recent-link table do not expose the daily buckets. Task 3.3 and its verification cover the link statistics page, and decision 7 covers the two new statistics pages, so completing the plan leaves the strengthened requirement false on the dashboard. Either explicitly scope the new guarantee to the two statistics pages, preserving the dashboard's existing contract, or add dashboard implementation and verification coverage and reconcile the scope artifacts. | fixed |
| 2 | major | tasks.md:31–33; design.md, decision 4 | The authorization verification covers only the three listing/report pages. The new block/unblock confirmation GETs and mutation POSTs have no guest/non-admin access tests; task 4.3 exercises administrator behavior and invalid CSRF only. Consequently the planned tests do not establish the administrator boundary on the security-sensitive writes themselves. Add explicit access coverage for both actions and their confirmation pages, including a signed-in non-admin POST with a valid token so CSRF cannot mask a missing role check, and assert the target state remains unchanged. Plan demonstrated failing inputs for the new role and CSRF guards and the moved self-block guard, as required for high tier; the prefix-slug mutation in 4.1 demonstrates routing behavior, not denial of unauthorized account writes. | fixed |
| 3 | minor | specs/web-ui/spec.md:40 | “A link with no clicks in the period” rendering “a page with zeros” is broader than the unchanged analytics summary contract. A link can have zero clicks in the selected period and still have nonzero all-time clicks, unique visitors, previous-period clicks, or today's clicks, and non-null first/last timestamps. Limit the zero guarantee to period-scoped figures and empty breakdowns, preserve the other summary values, and add a scenario with historical clicks outside the selected period; the existing never-clicked scenario does not resolve this ambiguity. | fixed |
| 4 | minor | design.md, decisions 1–2; tasks.md:13 | Specify how each web report obtains its effective request before claiming a shared API/web cache entry. `ReportRequest::cacheKey()` includes granularity, limit and period for every report, while the current providers enable granularity only for timeseries, limit only for the applicable breakdowns, and ignore the selected period for admin summary. Passing one hourly page request to all service methods would therefore create different summary/breakdown keys from the API; passing the selected period to admin summary has the same problem. Record per-report normalization matching the current provider flags and verify API-to-page cache reuse with an explicit period and hourly granularity, including admin summary. The planned repeated service call with a prebuilt request does not check that presenter boundary. | fixed |

### Validation
- Branch and HEAD match the requested identifiers; the working tree was clean before review.
- `scripts/pregate-verify.sh gate1 add-web-admin-and-stats` passed, including strict OpenSpec validation, with zero warnings.
- Reviewed the proposal, design, tasks and delta specification against the current analytics contracts, providers, request/cache-key construction, security configuration and dashboard template. No implementation or Git write commands were run.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-14
**Reviewed-Commit:** 64764ab792923b4d6bcd6fc0af65ae00f4cb0bfb
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Task 3.3 now covers implementing the dashboard's daily table and verifying every bucket against the chart's figures. Proposal scope and impact, design decision 7, and both no-JavaScript scenarios explicitly include the dashboard. The existing controller already supplies `days` to its template, so the planned correction is feasible without a new report path. |
| 2 | confirmed — Task 4.4 explicitly covers guest and non-admin access to both block/unblock confirmation pages and submissions, unchanged target state, and non-admin POSTs with a token accepted by their own session. It plans demonstrated failing inputs for the role boundary (removing both redundant role enforcement points), CSRF check, and moved self-block guard; task 4.3 supplies the authorized action cases. The administrative requirement and design applicability/risk discussion now distinguish role denial from CSRF denial. |

### Validation
- Reviewed the diff from `562ff2452c9c8daa0f940db9493c2d679429a836` to `64764ab792923b4d6bcd6fc0af65ae00f4cb0bfb` for round 1's major findings 1 and 2 and their reachable collateral effects. No blocker findings existed in that round; minor findings 3 and 4 are outside this confirmation's requested scope.
- Branch and HEAD match the requested identifiers; the working tree was clean before this confirmation.
- `scripts/pregate-verify.sh gate1 add-web-admin-and-stats` passed, including strict OpenSpec validation, with zero warnings.
- This confirms the Gate 1 planning corrections; implementation and the planned test/mutation evidence remain for Gate 2. Only `review.md` was modified; no Git write commands were run.

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-14
**Reviewed-Commit:** dd322c79161b7dc4478fa64f7561d87e117022d3
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | tasks.md:32,35; handoff.md:33; src/Auth/UseCase/BlockUser.php:39 | The required high-tier mutation evidence is incomplete despite tasks 4.1 and 4.4 being checked. The implementation commits and handoff record observed failures for report normalization, ownership, batch loading, the administrator role and CSRF, but do not record an executed failure with the moved self-block guard removed or with the admin path segment boundary removed. The corresponding ordinary tests exist; their existence is not the demonstrated failing run required by AGENTS.md and explicitly promised at Gate 1. Execute these two mutations independently, record the exact test command and observed failing assertion for each, restore the guards and show the restored tests passing. Reconcile the checked-task evidence before requesting confirmation. | fixed |
| 2 | minor | src/Web/Stats/StatsControls.php:65; templates/stats/_controls.html.twig:5 | Both pages parse and apply `limit`, but their form has no limit control, value preservation or error rendering. For example, `/admin/stats?limit=500` returns 422 with only the generic instruction to correct the controls above: the actual limit error is never displayed and none of those controls can correct it. A valid `limit=7` is also silently dropped on the next form submission. Either make limit a supported control with its value and refusal, or exclude it from the page's accepted parameters and use the report default; cover the chosen behavior on both pages. | fixed |
| 3 | minor | src/Web/Stats/StatsControls.php:71–73 | Accepted RFC 3339 bounds lose their time when copied into the date controls. Opening a same-day hourly interval such as `from=2026-09-01T10:00:00Z&to=2026-09-01T12:00:00Z&granularity=hour` produces a valid report, but pressing Show with the displayed controls unchanged submits September 1 for both bounds and returns 422. Other sub-day bounds silently change the report interval. Preserve the accepted precision through the form, or explicitly constrain the page to date-only bounds before computing reports, and test a form round trip. | fixed |

### Validation
- Confirmed the requested branch and HEAD, with a clean working tree before review; inspected the diff against `main`, the change artifacts, OpenSpec configuration, new tests, and the affected report, authorization, account-action and template code.
- `scripts/pregate-verify.sh gate2 add-web-admin-and-stats`: whitespace, strict OpenSpec validation, tier, task-path and Markdown checks passed. Its `make check` step could not start the PHP checks because this review sandbox cannot access `/var/run/docker.sock` (permission denied). This is an execution limitation, not an observed lint or test failure; no independent green test run is claimed.
- The handoff reports 809 passing tests and a successful CI run for `a646230`; these are executor-recorded results, not independently rerun here.
- Findings 2 and 3 follow from the shared request parser and rendered form; runtime reproduction was unavailable in this sandbox.
- Only `review.md` was modified. No Git write commands or implementation edits were run.

## Confirmation 1 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-14
**Reviewed-Commit:** 6f0daa3dc77db1f91c9e0d0a2b7b7a6323320483
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — The new task/handoff text and commit `8cc990f` record both mutations, their observed failures and restoration, and both guards are present and unchanged from the reviewed baseline. However, none records the exact test commands explicitly requested by this finding, either for the failing mutations or the restored passing runs. Record the commands actually executed, including test selection and the test-container cache rebuild for the security configuration mutation, together with the failing and restored passing results. Also identify the web self-block case correctly: it is `UserBlockTest::testAnAdministratorCannotBlockTheirOwnAccount`, not a second `BlockUserTest` case as tasks 4.4 and the handoff currently claim. The observed integration refusal and web alert failure match the existing assertions; the remaining issue is completing the requested reproducible execution record, not adding another guard or test. |

### Validation
- Reviewed only the diff from `dd322c79161b7dc4478fa64f7561d87e117022d3` to `6f0daa3dc77db1f91c9e0d0a2b7b7a6323320483` for round 1's major finding 1 and its reachable collateral effects, including the commit messages, task/handoff evidence, restored guards and corresponding tests. No blocker findings existed; minor findings 2 and 3 are outside this confirmation's requested scope.
- Branch and HEAD match the requested identifiers; the working tree was clean before this confirmation. Repository searches and the intervening commit bodies did not locate the missing mutation commands.
- `scripts/pregate-verify.sh gate2 add-web-admin-and-stats`: whitespace, strict OpenSpec validation, tier, task-path and Markdown checks passed. `make check` could not start the PHP checks because access to `/var/run/docker.sock` was denied. No independent passing PHP test run or mutation rerun is claimed; the handoff's green CI result is executor-recorded evidence.
- Only `review.md` was modified. No Git write commands or implementation edits were run.

## Confirmation 2 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-14
**Reviewed-Commit:** 314fe3182295f596299087de3899ae8a9be209ec
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Tasks 4.1 and 4.4 now record the exact Docker Compose commands for each independent mutation, including `cache:clear --env=test`, the PHPUnit filters and test files, the observed failures, and the passing results after restoring each guard (`OK (1 test, 2 assertions)` and `OK (2 tests, 18 assertions)`). Task 4.4 and the handoff correctly identify the web case as `UserBlockTest::testAnAdministratorCannotBlockTheirOwnAccount`. The named tests and their assertions match the recorded failures; the self-block guard and `^/admin(/|$)` boundary are present and unchanged from the reviewed baseline. This completes the reproducible executor evidence requested by finding 1 and the first confirmation. |

### Validation
- Reviewed only the diff from `dd322c79161b7dc4478fa64f7561d87e117022d3` to `314fe3182295f596299087de3899ae8a9be209ec` for round 1's major finding 1 and its reachable collateral effects, including the task/handoff evidence, commit bodies, restored guards and corresponding tests. No blocker findings existed; minor findings 2 and 3 are outside this confirmation's requested scope.
- Branch and HEAD match the requested identifiers; the working tree was clean before this confirmation. Re-read tasks and handoff in full and searched related mutation and self-block claims for consistency.
- `scripts/pregate-verify.sh gate2 add-web-admin-and-stats`: whitespace, strict OpenSpec validation, tier, task-path and Markdown checks passed. `make check` could not start the PHP checks because access to `/var/run/docker.sock` was denied. No independent passing PHP test run or mutation rerun is claimed; the recorded mutation results and green CI run are executor-provided evidence.
- Only `review.md` was modified. No Git write commands or implementation edits were run.
