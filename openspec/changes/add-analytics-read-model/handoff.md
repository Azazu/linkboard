# Handoff — add-analytics-read-model

**Updated:** 2026-09-11 · claude
**State:** fixing-g2
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

## Next step
Finding #1 (tier) awaits the user's decision: raise to `high` (applicability table in `design.md`, `scripts/gate-run.sh add-analytics-read-model 1 full` on the existing artifacts, then `scripts/gate-run.sh add-analytics-read-model 2 confirm 1`) or waive. Then the Gate 2 confirmation of round 1.

## Blockers
Finding #1 needs the user (tier is the user's call; a `major` cannot be closed by the executor alone).
