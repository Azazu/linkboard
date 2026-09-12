## Purpose

Long-lived credentials for integrators and monitors: named API keys a user creates, sees once, lists and revokes; how a key authenticates an API request; and the per-identity request limit that every authenticated API call is subject to.

## ADDED Requirements

### Requirement: Create an API key and see it once
`POST /api/v1/api-keys` with JSON `{"name", "expiresAt"?}` SHALL create a key for the authenticated caller and answer 201 with `id`, `name`, `prefix`, `key`, `expiresAt`, `createdAt`, `lastUsedAt` (null) and `revokedAt` (null). `key` is the plaintext credential of the form `lb_` followed by 40 characters of `[A-Za-z0-9]` and SHALL appear in this response only — no other response, log or stored column ever carries it; the system stores `SHA-256(key)` (hex) and `prefix`, the first 8 characters of the key. `name` MUST be 1–64 characters after trimming; `expiresAt`, when present, MUST be an RFC 3339 timestamp in the future; a violation answers 422 `application/problem+json` with one violation per offending field. A user SHALL hold at most 10 active keys (neither revoked nor expired): the request that would create the 11th SHALL answer 409 `application/problem+json` whose `detail` names the limit and creates nothing — also when several creations for the same user run concurrently, in which case exactly as many succeed as the cap allows and the rest answer 409.

#### Scenario: Plaintext once
- **WHEN** a user posts `{"name":"ci deploy"}` to `/api/v1/api-keys` and then lists `GET /api/v1/api-keys`
- **THEN** the creation response is 201 with a `key` matching `^lb_[A-Za-z0-9]{40}$`, a `prefix` equal to the first 8 characters of `key` and `revokedAt` null; the listing shows the key's `id`, `name` and `prefix` but no `key` field

#### Scenario: Hashed at rest
- **WHEN** a key was created
- **THEN** no column of the stored row contains the plaintext, and the row's `key_hash` equals the hex SHA-256 of the plaintext

#### Scenario: Invalid input
- **WHEN** a user posts `{"name":""}`, `{"name":"<65 characters>"}` and `{"name":"ok","expiresAt":"2020-01-01T00:00:00Z"}`
- **THEN** each response status is 422 `application/problem+json` with exactly one violation whose `propertyPath` is `name`, `name` and `expiresAt` respectively

#### Scenario: Eleventh active key
- **WHEN** a user holds 10 active keys and posts another
- **THEN** the response status is 409 `application/problem+json`, `detail` mentions the limit of 10, and the user still holds 10 keys; after revoking one, the next post is 201

#### Scenario: Concurrent creations at the cap
- **WHEN** a user holds 9 active keys and several creation requests for that user run at the same time
- **THEN** exactly one of them is 201 and every other one is 409, and the user holds exactly 10 active keys afterwards

### Requirement: List and revoke own keys
`GET /api/v1/api-keys` SHALL return the caller's keys — including revoked and expired ones — newest first, each with `id`, `name`, `prefix`, `createdAt`, `lastUsedAt`, `expiresAt` and `revokedAt`, paginated like the link collections; another user's keys SHALL never appear. `DELETE /api/v1/api-keys/{id}` SHALL revoke the caller's key: `revokedAt` is set, the row is kept, the response is 204; repeating the revocation answers 204 again. A key of another user, an unknown or malformed `id` SHALL answer 404 `application/problem+json` (other users' keys are invisible). An administrator manages only their own keys through these operations.

#### Scenario: Own keys only
- **WHEN** users A and B each created a key and A requests `GET /api/v1/api-keys`
- **THEN** the response lists exactly A's key with `prefix`, `name`, `createdAt`, `lastUsedAt`, `expiresAt`, `revokedAt` and without `key`

#### Scenario: Revoke, repeat, foreign key
- **WHEN** A deletes their key twice, then deletes B's key by id, then an admin deletes A's key by id
- **THEN** the first two responses are 204 and A's key shows a `revokedAt` timestamp, the third and the fourth are 404 `application/problem+json`

### Requirement: API keys authenticate API requests
A request under `/api/v1` carrying `Authorization: Bearer lb_…` SHALL be authenticated as the key's user when a key whose stored hash equals `SHA-256(presented value)` exists, is not revoked and is not expired; the lookup is by the hash only and no plaintext comparison exists. An unknown, revoked or expired key, or a malformed `lb_` value, SHALL answer 401 `application/problem+json`; a valid key of a blocked user 403 `application/problem+json` with `detail` `blocked`. The authenticated user has the same roles, voters and access rules as with a JWT. The key's `lastUsedAt` SHALL be set to the request time when it is null or older than 60 seconds, and left unchanged otherwise. A presented JWT is never mistaken for a key and a key never for a JWT.

#### Scenario: Key used
- **WHEN** a user creates a key and requests `GET /api/v1/me` with `Authorization: Bearer <key>`
- **THEN** the response is 200 with the user's `id` and `email`, and the key's `lastUsedAt` is now set

#### Scenario: Revoked and expired keys
- **WHEN** a user revokes a key and presents it, and presents another key whose `expiresAt` has passed
- **THEN** each response status is 401 `application/problem+json`

#### Scenario: Unknown key
- **WHEN** a client presents `Bearer lb_` followed by 40 characters that match no stored hash
- **THEN** the response status is 401 `application/problem+json`

#### Scenario: Blocked owner
- **WHEN** a key's owner is blocked and the key is presented
- **THEN** the response status is 403 `application/problem+json` with `detail` `blocked`

#### Scenario: Last used at most once a minute
- **WHEN** a key is used twice within the same minute
- **THEN** after the first request `lastUsedAt` is set and after the second it is unchanged

#### Scenario: JWT still works
- **WHEN** a user requests `GET /api/v1/me` with a valid JWT after keys exist
- **THEN** the response status is 200

### Requirement: Per-identity API rate limit
Every authenticated request under `/api/v1` outside `/api/v1/auth/*` SHALL consume one token of a sliding window of `RATE_LIMIT_API_PER_KEY` requests per minute (default 600), keyed by the API key when the request was authenticated with a key and by the user when it was authenticated with a JWT — consumed once per request, at authentication, before any authorization decision, so a request that is later refused with 403 or 404 counts like a successful one. Responses of limited requests SHALL carry `X-RateLimit-Limit` (the configured limit) and `X-RateLimit-Remaining` (tokens left in the window). Over the limit the request SHALL be refused with 429 `application/problem+json` and a `Retry-After` header without reaching the operation. Two keys of one user have separate windows; a key and a JWT of the same user have separate windows. The limit holds only while its store is reachable: when the limiter's storage or lock fails, the request MUST proceed without the headers and the failure MUST be logged at `warning` — API availability never depends on the limiter's store. Unauthenticated requests and `/api/v1/auth/*` are not subject to this limit (the auth endpoints keep their per-IP limit).

#### Scenario: Headers on every limited response
- **WHEN** a user requests `GET /api/v1/me` with a key, then with a JWT
- **THEN** each response carries `X-RateLimit-Limit: 600` and `X-RateLimit-Remaining: 599` (each identity's first request of the window)

#### Scenario: Over the limit
- **WHEN** a client with one key has consumed the whole window and sends one more request
- **THEN** the response status is 429 `application/problem+json` with a `Retry-After` of at least 1 second and `X-RateLimit-Remaining: 0`, and the operation was not executed

#### Scenario: Refused requests are counted
- **WHEN** an ordinary user repeatedly requests `GET /api/v1/admin/stats/summary` with their key, and then with their JWT
- **THEN** every response is 403 `application/problem+json` carrying `X-RateLimit-Limit` and a decreasing `X-RateLimit-Remaining`, and once the window is exhausted the response is 429

#### Scenario: Separate windows
- **WHEN** a user exhausts the window of key A and then requests with key B and with a JWT
- **THEN** the key-B request and the JWT request are 200

#### Scenario: Store unavailable
- **WHEN** the limiter's store refuses connections and a user requests `GET /api/v1/me` with a key
- **THEN** the response status is 200 without `X-RateLimit-*` headers and a `warning` record names the failure class
