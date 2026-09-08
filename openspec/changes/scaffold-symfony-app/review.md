# Review — scaffold-symfony-app

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-08
**Reviewed-Commit:** eea18639880e04ad911988a1d814d7835e7f65d2
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | src/Shared/Health/HealthProbe.php: checkDatabase; specs/health-check/spec.md: Deep dependency probe | `PDO::ATTR_TIMEOUT` bounds the PostgreSQL connection attempt, but `SELECT 1` has no server-side `statement_timeout` or other query timeout. A PostgreSQL server that accepts a connection and then stops answering can therefore hang the deep probe beyond its required 2 seconds. Configure a 2-second PostgreSQL statement timeout as part of the probe connection and cover the database-hang case, not only an unreachable host. | fixed |
| 2 | blocker | src/Shared/Health/HealthController.php: __invoke; specs/health-check/spec.md: Deep probe in production without authorization | The production branch throws `NotFoundHttpException` for `?deep=1`. `/health` is outside the API contour, so this does not guarantee the spec's required 404 `application/problem+json` body and has no test for that scenario. Return an explicit RFC 9457 problem-details JSON response (including `Cache-Control: no-store`) or install a route-level renderer, and test it under `prod`. | fixed |

## Confirmation 1 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-08
**Reviewed-Commit:** c2218f9dee58a0bdf1497a807955147fd98a372a
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — the probe connection applies a server-side 2-second PostgreSQL `statement_timeout`; the integration test executes `pg_sleep(10)` on that exact connection and verifies cancellation within the bound. |
| 2 | confirmed — the production deep-probe branch explicitly returns a no-store RFC 9457 `application/problem+json` 404, covered by a request through a clean production kernel. |
