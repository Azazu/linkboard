# Proposal — add-api-keys-and-rate-limiting

**Risk-Tier:** high

Tier rationale: AGENTS.md and NFR-SEC-7 put every change touching authentication, API keys, firewalls or rate limits at `high`; this change adds a second authenticator to the API firewall, a credential type stored hashed at rest, a per-identity rate limit on every API request, a new table with a reviewed migration, and lets a credential open the production deep health probe. Gate 1 on the artifacts and Gate 2 on the code; a demonstrated failing input for every new guard; `design.md` carries the applicability table; a green branch run before Gate 2 (auto review mode). No new dependency: Symfony's `access_token` authenticator, RateLimiter and Lock are already installed and in use.

## Why

Integrators and monitoring systems hold keys, not browser sessions or one-hour JWTs (FR-AUTH-3, §2.8 of the brief). Until now the API is reachable only with a JWT minted from a password, nothing limits an authenticated client's request rate, and the production deep health probe is unconditionally refused because no credential fit for a monitor exists. The `api_keys` table is the last piece of §3 the schema lacks.

## What Changes

1. **API keys as a resource** (FR-KEY-1, FR-KEY-3): `POST /api/v1/api-keys` creates a named key (`name` ≤ 64 characters, optional `expiresAt`) for the caller — the response (201) carries the plaintext `key` exactly once, of the form `lb_` + 40 base62 characters; the database stores only `SHA-256(key)` and the first 8 characters as a display `prefix`. `GET /api/v1/api-keys` lists the caller's own keys (prefix, name, created, last used, expires, revoked) newest first; `DELETE /api/v1/api-keys/{id}` revokes (sets `revokedAt`, keeps the row for audit, 204; idempotent). At most 10 active keys per user — the 11th `POST` answers 409 problem details (assumption: a state conflict, not a field validation). Owner-only through `ApiKeyVoter`: an admin manages their own keys like any user, never another user's (permission matrix "own / own"); a stranger's `DELETE` is 404 (keys of others are invisible, so an unknown id and a foreign id look alike).
2. **API keys authenticate API requests** (FR-AUTH-3, FR-KEY-2): `Authorization: Bearer lb_…` on any `/api/v1` operation resolves to the key's user through an exact-match lookup by the hash (no plaintext is ever compared or stored); a revoked, expired or unknown key answers 401 problem details; a blocked user's key answers 403 `blocked` like a blocked user's JWT; `lastUsedAt` is updated at most once per minute per key. JWTs keep working unchanged; both credentials reach the same user entity, the same voters and the same `/api/v1/auth/*`-free public surface.
3. **Per-identity rate limit on the API** (FR-KEY-4): every authenticated request under `/api/v1` (outside `/api/v1/auth/*`, which keeps its per-IP limit) consumes one token of a sliding window of `RATE_LIMIT_API_PER_KEY` (default 600) per minute, keyed by the API key for key-authenticated requests and by the user for JWT-authenticated ones; over the limit → 429 problem details with `Retry-After`; every limited response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`. Redis storage with the existing lock, fail-open like the redirect limiter (Redis down → the request proceeds, one `warning`, no headers).
4. **Production deep health probe for monitors** (health-check spec, FR-KEY-2 ∩ §2.10): `GET /health?deep=1` in `prod` runs the probe when the request carries a valid, unexpired, unrevoked API key of an unblocked **admin** — resolved by the same lookup the firewall uses; anything else keeps today's 404. Sessions and JWTs never authorize it.
5. **Schema**: table `api_keys` per §3.2 of the brief (uuid PK, `user_id` FK → users `ON DELETE CASCADE`, `name`, `key_hash` char(64) unique, `prefix` char(8), `expires_at`, `revoked_at`, `last_used_at`, `created_at`) in a reviewed, reversible migration.
6. **Docs**: how-to (create a key, call the API with it, revoke, the limit headers and 429, `RATE_LIMIT_API_PER_KEY`, the monitor's deep probe), `.env` default for the new limit, FR-KEY-1…4 refined where this proposal fixes what the brief left open (409 on the 11th key, `X-RateLimit-*` on which responses, fail-open, the probe's key rules).

## Capabilities

### New Capabilities

- `api-keys`: creating, listing and revoking API keys; the key format, hashing at rest and plaintext-once; authenticating API requests with a key (lookup, 401/403 rules, `lastUsedAt`); the per-identity API rate limit and its headers.

### Modified Capabilities

- `authentication`: "JWT for the API" becomes "JWT or API key for the API" — the same bearer header carries either credential, distinguished by the `lb_` prefix; the failure rules are stated for both.
- `user-accounts`: "Blocked accounts are refused everywhere" — the credentials list gains API keys (403 `blocked` on the next request, no waiting for expiry).
- `health-check`: "Deep dependency probe" — the `prod` refusal gets its documented exception: a valid admin API key authorizes the probe; everything else stays 404.

## Non-goals

- The web UI's API-keys page (`add-web-ui`, row 11) — this change provides the API it will call; `ApiKeyVoter` is written so the web controllers reuse it.
- Key rotation, scopes or per-key permissions, key-bound IP allow-lists, OAuth/OIDC, refresh tokens.
- Rate limits keyed by IP for authenticated API traffic (identity is the better key once the caller is known); changing the auth (`10/min/IP`) or redirect (`60/min/IP`) limits — both stay as they are, with their existing trusted-proxy tests (FR-KEY-5 is already asserted for the two IP-keyed limiters and this change adds no IP-keyed one).
- Admin management of other users' keys, listing all keys, an admin "revoke every key of a blocked user" action (blocking already stops every key on the next request).
- A per-request `lastUsedAt` write (the once-per-minute rule of the brief is the contract) and a "last used IP" field.
- Rate-limit headers on anonymous or `/api/v1/auth/*` responses, `X-RateLimit-Reset`, `RateLimit-Policy` (the brief names two headers).
- The deep probe for non-admin keys, for JWTs or for sessions; a distinct status code for "key refused" on `/health` (it stays 404, so the probe is no oracle for key validity).

## Impact

- New: `src/Auth/Entity/ApiKey.php`, `src/Auth/ApiKeyRepositoryInterface.php`, `src/Auth/Repository/DoctrineApiKeyRepository.php`, `src/Auth/ApiKey/` (key generator/hasher, the token handler, extractor, failure handler, JWT-extractor decorator, `ApiKeyVoter`), `src/Auth/Api/ApiKeys/` (resource, input, providers, processors), `src/Auth/RateLimit/` (the API limit subscriber and the header writer), a migration, tests under `tests/Unit/Auth/`, `tests/Integration/Auth/`, `tests/Api/Auth/ApiKeys/`, `tests/Api/Health*`.
- Modified: `config/packages/security.yaml` (`access_token` on the `api` firewall), `config/packages/framework.yaml` (`api_identity` limiter), `.env` (`RATE_LIMIT_API_PER_KEY=600`), `src/Shared/Health/HealthController.php` (admin-key branch), `docs/how-to/local-development.md`, `docs/explanation/requirements.md` (FR-KEY-1…4), `docs/reference/commands.md` if a console helper is added (none planned), `openspec/ROADMAP.md` (row 10 removed at archive time).
- Unchanged: the JWT flow and its tests, `LinkVoter`, the redirect and click paths, the auth and redirect limiters, the web firewall.
