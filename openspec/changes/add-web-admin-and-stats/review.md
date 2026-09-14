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
