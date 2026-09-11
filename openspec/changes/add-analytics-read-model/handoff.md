# Handoff — add-analytics-read-model

**Updated:** 2026-09-11 · claude
**State:** awaiting-gate-2
**Branch:** change/add-analytics-read-model

## Done this session
- Proposal, specs (`analytics`, `demo-data` new; `links` modified), design, tasks (`7855020`).
- Block 1 (`f7d2ef4`): `Period`/`Granularity`/`ReportRequest`, DTOs, four DBAL query services (one statement per report, compile-time bot fragment, UTC bucketing), `ClickRows` fixture, 16 integration + 12 unit tests, architecture test.
- Block 2 (`5662034`): six per-link report resources with declared query parameters, `LinkReportProvider` (404 → existing `LinkVoter` → cache), `ReportRequestFactory`, `cache.reports` tag-aware pool + `ReportCache` gate, invalidation in `UpdateLinkProcessor`/`DeleteLinkProcessor`, three admin reports; 17 API tests + 3 unit.
- Block 3 (`12087a9`): `app:demo:seed` (generated passwords printed once, ten links with documents, clicks in SQL, `--reset`, prod guard) + tests.
- Block 4 (`83c896a`): how-to Analytics section, commands reference, FR-ANL-2/3/4 refined; every documented command run on the dev stack.
- Block 5.1 (`1856d1c`): 1 M-click measurement — per-link reports 26–230 ms p95 (target 300), admin 534/810 ms; no index added (scan is a fifth of the cost; evidence in the design appendix).
- Branch run 34593409687 on `331fc7b`: completed, success (https://github.com/Azazu/linkboard/actions/runs/34593409687).
- `make check` green on the head: 555 tests / 7273 assertions. Tasks 1.1–5.2 checked with evidence; every guard has a demonstrated failing input in its commit body.

**Security-relevant parts for review:** the new read endpoints' authorization (the provider asks `LinkVoter::VIEW` on the link; admin operations rely on `security` + `access_control`), query-parameter handling (declared constraints + domain checks, values bound to SQL, enum literals only in fragments), the demo seed (creates accounts with generated credentials; environment guard).

- Gate 2 round 1 (`1bbc1b7`, Reviewed-Commit `ff0c483`): changes-requested, four majors. Fixed: #2 — an omitted `from` is now defined as 30 days before the effective `to` (spec, brief, how-to, design; HTTP test for both one-bound cases); #3 — the admin summary declares no period, ignores `from`/`to` and the provider no longer parses them (spec exception, test); #4 — the global reports dropped the distinct-visitor count the brief never asked of them and re-measured at 119 / 75 ms p95 on one million clicks (target 300); NFR-PERF-2's index clause reworded to what the plans do, the 300 ms target unchanged. `make check` green after the fixes.

- Finding #1: the user raised the tier to `high` (2026-09-11). `proposal.md` carries the new tier and rationale, `design.md` the applicability table, `tasks.md` a Gate 1 task (0.1).

- Gate 1 round 1 (`6e0ded1`, Reviewed-Commit `f2f86ac`): changes-requested, two majors + one minor. Fixed in `f0df821`: #1 — the timeseries contract is "every UTC bucket intersecting the period, clicks filtered by the exact half-open bounds" (partial first/last buckets, 337/367 maxima, sub-bucket period), spec scenario + design sketch + tests for link, global and HTTP (task 1.4; failing input: series from the raw bounds); #2 — the seed's one-transaction guarantee has two spec scenarios and a failure-injection test through the test-only DBAL middleware `tests/Fixture/FailingStatement.php` (fresh seed → nothing; failed `--reset` → former ids, password, links, clicks intact), the command exits 1 with the reason, decision 10 reconciled with the applicability table (task 3.2; failing input: closure without `transactional()`); #3 — proposal/design/tasks/ROADMAP drift reconciled (no global `uniqueVisitors`, high tier everywhere, tasks 0.1/5.4 claim the recorded round, not the verdict). `make check` green: 558 tests / 7326 assertions. Statuses in review.md → fixed.

**Security-relevant (this session):** the seed's `--reset` deletion path — rollback under failure injection is now asserted; `FailingStatement` is registered under `when@test` only (`config/services.yaml`).

- Gate 1 passed: Confirmation 1 (`627955d`, Reviewed-Commit `2c3c495`) — all three findings confirmed.
- Branch run 34597813548 on `3c0c1d0`: completed, success (https://github.com/Azazu/linkboard/actions/runs/34597813548).
- Gate 2 Confirmation 1 (`f392838`, Reviewed-Commit `3c0c1d0`): findings 1–3 confirmed, finding 4 changes-requested — the reviewer asked for the user's explicit decision on the revised requirement (global reports without unique visitors, NFR-PERF-2 index wording) and for task 5.1 to match its evidence. The user decided (recorded in `proposal.md`, User decisions): top-links keeps its unique visitors (restored in `eab0416` as a two-level (link, 64-bit visitor) statement — 260 ms p95 on a fresh one-million seed, failing input in the commit body), the global timeseries stays without them (every formulation measured 356–575 ms), NFR-PERF-2's index clause as reworded. Task 5.1 and the "brief never asked" claims reconciled. `make check` green.

## Next step
`scripts/gate-run.sh add-analytics-read-model 2 confirm 1` — the second confirmation on finding 4; if it fails again, stop and ask the user to arbitrate (AGENTS.md: no third loop). Then the user pushes the branch, the executor confirms a green Actions run on the exact head (task 5.3), then `/git:merge`.

## Blockers
None.
