# Proposal — authorize-deep-probe-by-api-key

**Risk-Tier:** high

Tier rationale: an authorization boundary on a production endpoint that today is unconditionally refused, a credential check that runs outside the firewall, and a monitoring surface whose failure modes (database down, stalled, Redis down) decide what an unauthenticated caller can learn — AGENTS.md and NFR-SEC-7 put this at `high`. Gate 1 on the artifacts and Gate 2 on the code, a demonstrated failing input for every guard, the applicability table in `design.md`, a green branch run before Gate 2. No Composer dependency; one runtime extension joins the image and CI — `pgsql`, built from the libpq already present for `pdo_pgsql` — because it is the only PostgreSQL client PHP offers whose every wait the caller can bound (the asynchronous API driven by `stream_select`); `pdo_pgsql` has no read deadline and would hang on a lost response.

## Why

`GET /health?deep=1` is refused with 404 in `prod` because no credential fit for a monitor existed. `add-api-keys-and-rate-limiting` (archived 2026-09-12) shipped the keys and left this authorization to a change of its own after two Gate 1 confirmations showed the hard part: a key lookup that must be *bounded* (a monitor cannot wait on a hung database), *fail-closed* (an unverifiable key opens nothing), and still useful — a deep probe that answers 404 whenever the database is down cannot tell the monitor the one thing it exists to tell. This change designs exactly that.

## What Changes

1. **The `prod` deep probe runs for a valid admin API key** (health-check spec): `GET /health?deep=1` with `Authorization: Bearer lb_…` runs the probe when the key resolves — hash lookup, not revoked, not expired — to an unblocked `ROLE_ADMIN` user. Everything else — no header, a JWT, a session, an unknown, revoked or expired key, a non-admin's or a blocked admin's key — keeps today's 404 problem details (`Cache-Control: no-store`), identical for every reason. Sessions and JWTs never authorize the probe. The check never records a use of the key (`last_used_at` untouched) and never logs the key.
2. **A deadline the client enforces on every wait**: the database phase (connect within 1 s, the lookup within 2.5 s total) runs on ext-pgsql's asynchronous API, where every wait is a `stream_select` with a timeout the authorizer computes — so a connection that never completes, a lookup that stalls, or a response the network loses all end at the deadline, and a server-side `statement_timeout` is only a belt; the Redis phase has a reserved 1-second allowance with a per-command read timeout. A refusal for a verification that could not complete leaves within 5 seconds of the request in every such mode, is the same 404 as for a bad key, and logs a `warning` naming the failure class. Host-name resolution stays outside the budget, as for the probe's own checks.
3. **Authorization survives a database outage — the case the probe exists for**: every database answer, verified or denied, is remembered in Redis as a small record carrying the database's own time of the answer, written by an atomic compare-and-set that keeps the *later* answer — so a verification that raced a revocation can never overwrite the revocation's marker. When the database phase is unavailable (unreachable, refused, timed out, response lost — not "no such active admin key"), a remembered verification less than 300 seconds old (counted from the database's answer time) authorizes the probe, which then runs and reports its own checks — `database: fail` for a refused database, `ok` for one that still answers `SELECT 1`. A denied answer observed while Redis was reachable takes effect on the next request and cannot be undone by a later outage. The admitted case: when Redis was unreachable at the moment of the denial, the marker is not written and the earlier verification may authorize the probe during a database outage for the remainder of its 300 seconds — stated as the bound, logged as a `warning`. Redis down as well → nothing remembered, fail-closed 404.
4. **Tests that reproduce every failure mode end to end** through the `prod` kernel, with the admin and keys committed in the `app` database: the refusal matrix; the admin key → probe body; a closed port → 404 fast; a connection that never completes (black-hole socket) → 404 at the connect allowance; a stalled lookup (`LOCK TABLE api_keys IN ACCESS EXCLUSIVE MODE` held by the test) → 404 at the lookup allowance; a **completed handshake followed by a lost response** (a TCP proxy that swallows the server's reply) → 404 within 5 s — the case a PDO client cannot pass; **delayed connection plus stalled statement** through the same proxy → within 5 s; Redis that accepts and never answers → bounded; a remembered monitor during a refused database → 503 `database: fail`, and during a locked table → the probe runs and reports `ok`; a revoked key is refused at once and stays refused through a later outage; the verification-vs-revocation interleaving reproduced deterministically at the memory level. CI migrates the `app` database (`make migrate EXEC=`) and installs `pgsql`.
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

- New: `src/Shared/Health/Deadline.php` (monotonic allowances), `src/Shared/Health/AsyncPostgresQuery.php` (ext-pgsql asynchronous client: connect and query bounded by `stream_select`), `src/Shared/Health/BoundedRedisCommands.php` (the probe's ext-redis connect/AUTH with a per-command read timeout, factored out), `src/Shared/Health/ProbeMemory.php` (the CAS-ordered Redis memory), `src/Shared/Health/ProbeAuthorizer.php`, tests under `tests/Unit/Shared/Health/`, `tests/Integration/Shared/Health/` and `tests/Api/HealthDeepProdTest.php`, `tests/Fixture/tcp-proxy.php` (delay and lost-response modes) and `tests/Fixture/black-hole-socket.php`.
- Modified: `src/Shared/Health/HealthController.php` (the `prod` branch asks the authorizer), `src/Shared/Health/HealthProbe.php` (its Redis check uses the shared ext-redis factory; its PDO check is unchanged), `.docker/php/Dockerfile` and `.github/workflows` (`pgsql` extension; `make migrate EXEC=`), `docs/how-to/local-development.md`, `docs/explanation/requirements.md` (health rows, §7 row 10a), `openspec/ROADMAP.md` (row 10a removed at archive time).
- Unchanged: the firewall, `ApiKeyTokenHandler` and the API-key resource (the authorizer reuses `ApiKeyHeaderExtractor`'s regex and `ApiKeyGenerator::hash()` only), the liveness probe, the probe's own dependency checks (their lost-response gap is recorded in the design's Risks as a follow-up).
