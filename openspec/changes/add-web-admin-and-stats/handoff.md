# Handoff — add-web-admin-and-stats

**Updated:** 2026-09-14 · claude
**State:** implementing
**Branch:** change/add-web-admin-and-stats

## Done this session
- Branch `change/add-web-admin-and-stats` created from `main` (`2d3fdeb`, after the archive of `add-web-ui`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 11a and §7 of `docs/explanation/requirements.md`: the rest of FR-WEB-1 — `/links/{id}/stats` with every report of section 2.6 as charts and tables (period, granularity, bots toggle) and the admin pages `/admin/users`, `/admin/links`, `/admin/stats`. Tier `high`, set by the user when row 11 was split (2026-09-13): the admin pages are an authorization boundary, and the stats page reads another capability's reports on behalf of an owner.
- What it builds on, already merged: the `web-ui` capability (shell, hardening headers and the per-request CSP nonce, forms, `LinkPages::findGranted()` answering 404 on a voter denial), the `analytics` read model with its cached reports and `generatedAt`, and `user-administration` for the admin API.

- Artifacts written and `openspec validate --strict` passes: proposal (tier `high` with the three triggers that apply, non-goals), the `web-ui` delta (two MODIFIED requirements — the page enumeration gains `/links/{id}/stats`, and the no-JavaScript rule now says every charted series is also a table — and six ADDED ones: the statistics page's content, its ownership boundary, its refusal of a parameter on the control that carried it, the administrator-only boundary of `/admin/*`, the accounts page with block and unblock, the administrative links page and the global statistics page), design (one authority per report with the API providers as adapters, parsing split from API-shaped wrapping, the two authorization boundaries asked of the mechanisms that own them, block/unblock as a use case, shared filter parsing and a batch owner lookup, charts as an enhancement over tables, plus the applicability table), tasks (Gate 1, the two refactors, the statistics page, the four administrative tasks, docs, wrap-up).

**Design choices worth the reviewer's attention:** (1) the report services return the very objects the API answers, through the same cache key and tag, so one entry serves both presenters — the alternatives (a second code path, a reshaped cache payload, or moving nine classes) are recorded in decision 1 with why each was rejected, including the honest cost that the web layer names classes in the `Analytics\Api` namespace. (2) A refused report parameter re-renders the page with 422 and no figures rather than redirecting to the defaults, so the address keeps describing what is shown. (3) `^/admin(/|$)` carries the segment boundary row 11's Gate 1 found to be load-bearing — `admin` is a reserved slug but `admin-sale` is a valid one.

**Security-relevant parts:** the two authorization boundaries (the link voter's `LINK_VIEW` for a link's statistics with 404 on denial; `ROLE_ADMIN` for `/admin/*`, guarded both in `access_control` and on each controller), the move of the self-block guard and the audit line out of an API Platform processor into a use case, and the CSRF-protected confirmation flow for blocking an account.

- Gate 1 round 1 (`ca4be4c`, Reviewed-Commit `562ff24`): changes-requested — two majors and two minors, all four real, all fixed in the following commit.
  1. The strengthened no-JavaScript rule ("every number a chart draws is also on the page as text") would have been false on the dashboard, whose daily series is drawn only as a chart — the plan covered only the two new pages. Rather than scoping the guarantee down, the dashboard gains the same table: the controller already holds the figures, and the alternative was a rule that holds on the new pages and not on the old one.
  2. The authorization coverage stopped at the three listing pages: the block and unblock confirmations and their submissions had no guest or non-admin test, so a passing forgery check could have stood in for a missing role check. A task now asserts 403 and no change for a signed-in ordinary user submitting **with a token their own session would accept**, a login redirect and no change for a guest, and three demonstrated failing inputs that remove one guard at a time — the role, the token check, and the moved self-block guard.
  3. "A link with no clicks in the period renders as zeros" was broader than the `analytics` summary contract: all-time clicks, unique visitors and the first/last click are not period-scoped. The requirement now says which figures read zero and which keep their values, with a scenario for a link whose clicks all fall before the period.
  4. The sharpest one, and a real defect the plan would have shipped: `ReportRequest::cacheKey()` digests the period, granularity and limit for *every* report, while the providers normalize them per report with flags. One page's controls handed unchanged to nine service methods would have produced keys the API never writes — the same numbers computed and cached twice, defeating the decision's whole purpose. The normalization moves out of the providers into the services, and a task now proves the shared entry across presenters for all nine reports with an explicit period, hourly granularity and a non-default limit, the admin summary included.

- Gate 1 Confirmation 1 (`91fa943`, Reviewed-Commit `64764ab`): both majors confirmed — **Gate 1 passed**. Task 0.1 ticked. The reviewer notes that the minors it raised were dispositioned outside the confirmation's scope (they were fixed in the same commit) and that the implementation and its mutation evidence remain for Gate 2.

## Next step
`/opsx:apply add-web-admin-and-stats` — implement in the task order: the two refactors first (one authority for a report, one for an account action), then the statistics page, then the administrative pages, then docs. Then `make check`, a green branch run, and Gate 2.

## Blockers
None.
