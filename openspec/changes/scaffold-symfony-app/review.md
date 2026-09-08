# Review — scaffold-symfony-app

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-08
**Reviewed-Commit:** eea18639880e04ad911988a1d814d7835e7f65d2
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | src/Shared/Health/HealthProbe.php: checkDatabase; specs/health-check/spec.md: Deep dependency probe | `PDO::ATTR_TIMEOUT` bounds the PostgreSQL connection attempt, but `SELECT 1` has no server-side `statement_timeout` or other query timeout. A PostgreSQL server that accepts a connection and then stops answering can therefore hang the deep probe beyond its required 2 seconds. Configure a 2-second PostgreSQL statement timeout as part of the probe connection and cover the database-hang case, not only an unreachable host. | open |
| 2 | blocker | src/Shared/Health/HealthController.php: __invoke; specs/health-check/spec.md: Deep probe in production without authorization | The production branch throws `NotFoundHttpException` for `?deep=1`. `/health` is outside the API contour, so this does not guarantee the spec's required 404 `application/problem+json` body and has no test for that scenario. Return an explicit RFC 9457 problem-details JSON response (including `Cache-Control: no-store`) or install a route-level renderer, and test it under `prod`. | open |
