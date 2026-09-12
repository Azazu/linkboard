# Proposal — authorize-deep-probe-by-api-key

**Risk-Tier:** high

Tier rationale: an authorization boundary on a production endpoint that today is unconditionally refused, a credential check that runs outside the firewall, and a monitoring surface whose failure modes (database down, stalled, Redis down) decide what an unauthenticated caller can learn — AGENTS.md and NFR-SEC-7 put this at `high`. Gate 1 on the artifacts and Gate 2 on the code, a demonstrated failing input for every guard, the applicability table in `design.md`, a green branch run before Gate 2. No new dependency: the bounded PDO and ext-redis clients the probe already uses, the API-key hash lookup the previous change introduced.

## Why

`GET /health?deep=1` is refused with 404 in `prod` because no credential fit for a monitor existed. `add-api-keys-and-rate-limiting` (archived 2026-09-12) shipped the keys and left this authorization to a change of its own after two Gate 1 confirmations showed the hard part: a key lookup that must be *bounded* (a monitor cannot wait on a hung database), *fail-closed* (an unverifiable key opens nothing), and still useful — a deep probe that answers 404 whenever the database is down cannot tell the monitor the one thing it exists to tell. This change designs exactly that.

## What Changes

1. **The `prod` deep probe runs for a valid admin API key** (health-check spec): `GET /health?deep=1` with `Authorization: Bearer lb_…` runs the probe when the key resolves — hash lookup, not revoked, not expired — to an unblocked `ROLE_ADMIN` user. Everything else — no header, a JWT, a session, an unknown, revoked or expired key, a non-admin's or a blocked admin's key — keeps today's 404 problem details (`Cache-Control: no-store`), identical for every reason. Sessions and JWTs never authorize the probe. The check never records a use of the key (`last_used_at` untouched) and never logs the key.
2. **A total deadline for the authorization, enforced in code**: the authorization has a budget of 4 seconds measured on a monotonic clock; the database step gets a 2-second connect timeout (libpq's floor) and a statement timeout of whatever remains; when the remaining budget is exhausted the authorization stops with a refusal. The response therefore leaves within 5 seconds of the request in every failure mode — refused connection, delayed connection, stalled statement, or both combined — and a refusal for a failed lookup is the same 404 as for a bad key, plus a `warning` naming the failure class for operators.
3. **Authorization survives a database outage — the case the probe exists for**: after every *successful* verification against the database the key's hash is remembered in Redis (`probe-auth:<sha256(hash)>`, TTL 300 s, bounded ext-redis client with the remaining budget). When the database step *fails* (unreachable, refused, timed out — not "no such key"), a remembered hash authorizes the probe, which then reports `database: fail` (and Redis, evidently, `ok`). A verified refusal (unknown, revoked, expired, non-admin, blocked) deletes the remembered hash, so revocation takes effect immediately while the database is up; the only staleness is ≤ 300 s *during* a database outage — documented as the trade-off that keeps the probe meaningful. Redis down as well → nothing to remember, fail-closed 404.
4. **Tests that reproduce every failure mode end to end** through the `prod` kernel, with the admin and keys committed in the `app` database: the matrix of refusals; the admin key → probe body; database refusing connections (closed port) → 404 fast; a connection that never answers (a black-hole socket) → 404 after the connect timeout; a stalled lookup (`LOCK TABLE api_keys IN ACCESS EXCLUSIVE MODE` held by the test) → 404 after the statement timeout; **delayed connection plus stalled statement** through a TCP delay proxy fixture → still within 5 seconds; database down with a remembered key → the probe answers 503 with `database: fail`; a revoked key is forgotten immediately. CI migrates the `app` database (`make migrate EXEC=`) so the prod-kernel tests find `api_keys` there, as a deployed instance would.
5. **Docs**: the how-to's health paragraph (the monitor's admin key, alert on any non-200, what a 404 means while the database is down, the 300-second memory), the brief's health rows (§4 table, the deep-probe sentence) and §7 row 10a refined; `.env` unchanged — the budget and the TTL are constants of the design, not deployment knobs.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `health-check`: "Deep dependency probe" — the `prod` refusal gets its exception (a valid admin API key), the bounded total deadline, the fail-closed rule with the identical 404, the Redis memory that lets the probe report a database outage to a recently verified monitor, and the no-`last_used_at`/no-logging rules.

## Non-goals

- Any change to `/health` liveness (still no authentication, no dependency touched), to `/health` outside `prod` (still open), or to the OpenAPI document (`/health` stays outside the API contour).
- Authorizing the probe by a JWT, a session, a non-admin key, or a dedicated "monitor secret" in the environment (a secret in env is what API keys replaced).
- Per-key or per-IP rate limiting of `/health` (a monitor polls; the probe is cheap and read-only; the existing `redirect_ip`/`auth_ip` limits do not apply and none is added).
- Caching the probe's *result*, distinguishing "key refused" from "database down" in the HTTP response (the 404 stays identical; the distinction lives in the `warning`), or a status code other than 404 for refusals.
- Changing the probe's own dependency checks or timeouts, the API firewall, the API-key resource, or the `api_identity` limiter.

## Impact

- New: `src/Shared/Health/Deadline.php` (monotonic budget), `src/Shared/Health/BoundedDatabaseConnection.php` (the probe's PDO factory with a caller-supplied connect timeout and statement timeout), `src/Shared/Health/BoundedRedisConnection.php` (the probe's ext-redis factory with a caller-supplied timeout), `src/Shared/Health/ProbeAuthorizer.php` (the header regex, the hash, the database step, the Redis memory, the deadline), tests under `tests/Integration/Shared/Health/` and `tests/Api/HealthDeepProdTest.php`, `tests/Fixture/tcp-delay-proxy.php` and `tests/Fixture/black-hole-socket.php`.
- Modified: `src/Shared/Health/HealthController.php` (the `prod` branch asks the authorizer), `src/Shared/Health/HealthProbe.php` (its two clients come from the shared factories), `.github/workflows` (`make migrate EXEC=`), `docs/how-to/local-development.md`, `docs/explanation/requirements.md` (health rows, §7 row 10a), `openspec/ROADMAP.md` (row 10a removed at archive time).
- Unchanged: the firewall, `ApiKeyTokenHandler` and the API-key resource (the authorizer reuses `ApiKeyHeaderExtractor`'s regex and `ApiKeyGenerator::hash()` only), the liveness probe, the probe's checks.
