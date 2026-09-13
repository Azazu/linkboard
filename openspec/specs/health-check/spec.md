# Health Check

## Purpose
Liveness and dependency probe for operators, Docker healthchecks and CI smoke tests, available from the first deployable build of the service.

## Requirements

### Requirement: Liveness endpoint
The system SHALL answer `GET /health` with HTTP 200 and the JSON body `{"status":"ok"}` whenever the application kernel can handle requests, without touching the database, Redis or any other dependency.

#### Scenario: Application is up
- **WHEN** a client requests `GET /health`
- **THEN** the response status is 200, the content type is `application/json`, and the body is exactly `{"status":"ok"}`

#### Scenario: Dependencies are down but the application is up
- **WHEN** PostgreSQL and Redis are unreachable and a client requests `GET /health`
- **THEN** the response is still 200 with `{"status":"ok"}`

### Requirement: Deep dependency probe
The system SHALL answer `GET /health?deep=1` with a JSON body that reports each dependency (`database`, `redis`) as `"ok"` or `"fail"`, with HTTP 200 when all are `"ok"` and HTTP 503 when any is `"fail"`. Connecting to and querying each dependency MUST time out within 2 seconds so a hung dependency (reachable host, unresponsive service) cannot hang the probe; host name resolution happens before that timeout and is bounded by the platform resolver.

In the `prod` environment the deep probe SHALL run only for a request whose `Authorization: Bearer <API key>` header carries a key (capability `api-keys`) that the system verifies — by the hash of the presented value — as neither revoked nor expired and owned by an unblocked user with `ROLE_ADMIN`. Every other request — no header, a JWT, a session, an unknown, revoked or expired key, a non-admin's or a blocked admin's key, a key the system cannot verify — SHALL be refused with 404 (RFC 9457 problem details, `Cache-Control: no-store`), the same body for every reason, so the endpoint reveals nothing about keys or about why it refused. Sessions and JWTs never authorize the deep probe, because monitoring systems hold keys, not browser or user tokens. Outside `prod` the probe stays open as before.

The verification SHALL be bounded by wall-clock deadlines the application enforces regardless of how the dependency misbehaves — the database lookup runs in a separate process the application terminates at its deadline, so a connection that never completes, a lookup that stalls, a transport that loses the response, or a client stuck in its own cleanup cannot hold the request; each Redis command has its own read timeout: the database phase is allowed 2.5 seconds and the Redis memory (below) 1 second in all for its fixed sequence of four operations — connect, authentication, the read before the lookup and the one write or read after it — each bounded on its own by a deadline that covers the whole operation, so a dependency that answers a little at a time cannot outlast its share, so the command after the lookup always has its share whatever the database phase consumed; a refusal for a verification that could not complete therefore leaves within 5 seconds of the request in every combination of refused, delayed, stalled or response-less dependencies. Host-name resolution of the two DSNs happens before either allowance and is bounded only by the platform resolver, as for the probe's own checks. Such a refusal MUST be logged at `warning` naming the failure class and never the key.

The system SHALL remember each verified admin key (by a hash of its hash, in Redis, together with its expiry and the time of the verification) and SHALL record each denied answer by replacing a per-key denial token with a fresh random one and dropping the remembered verification — a verification is stored only if the denial token is exactly the one observed before the request's lookup began (a token that never repeats, so a denial cannot be mistaken for none after the token expires), so a verification that raced a revocation can never overwrite the revocation's effect. When the database phase could not be reached or answer (not when it answered "no such active admin key"), a remembered verification less than 300 seconds old (counted from the verification itself, not from when it was recorded) whose key has not expired SHALL authorize the probe, which then runs and reports its own checks (a refused database as `database: fail`; a database that still answers its check as `ok`). A denied answer observed while Redis was reachable therefore takes effect on the next request and cannot be undone by a later outage. When Redis was unreachable at the moment of a denied answer, the denial is not recorded: an earlier verification may then authorize the probe during a database outage for at most the remainder of its 300 seconds — this bound is the accepted staleness, and the failed write is logged at `warning`. When neither the database nor Redis can answer, the request is refused. The deep-probe verification MUST NOT update the key's `last_used_at` and MUST NOT log the key, its hash or its prefix.

#### Scenario: All dependencies reachable
- **WHEN** PostgreSQL and Redis accept connections and a client requests `GET /health?deep=1` outside `prod`
- **THEN** the response status is 200 and the body is `{"status":"ok","checks":{"database":"ok","redis":"ok"}}`

#### Scenario: One dependency unreachable
- **WHEN** Redis refuses connections and a client requests `GET /health?deep=1` outside `prod`
- **THEN** the response status is 503 and the body is `{"status":"fail","checks":{"database":"ok","redis":"fail"}}`

#### Scenario: One dependency hangs
- **WHEN** the Redis host is reachable but the service does not answer (process paused) and a client requests `GET /health?deep=1` outside `prod`
- **THEN** the response arrives within 3 seconds with status 503 and `redis` reported as `fail`

#### Scenario: Database accepts connections but does not answer
- **WHEN** the database accepts the connection but a query on the probe's connection does not complete within 2 seconds
- **THEN** the query is cancelled by a server-side statement timeout and the `database` check reports `fail` within 3 seconds

#### Scenario: Deep probe in production without authorization
- **WHEN** the application runs with `APP_ENV=prod` and a client requests `GET /health?deep=1` with no header, with an admin's JWT, with a valid key of a non-admin user, with a revoked admin key, with an expired admin key, and with the key of a blocked admin
- **THEN** each response status is 404, the content type is `application/problem+json`, the body is identical for every case and carries `type`, `title`, `status: 404` and `detail`, the response has `Cache-Control: no-store`, and no key's `last_used_at` changed

#### Scenario: Deep probe in production with an admin key
- **WHEN** the application runs with `APP_ENV=prod` and a monitor requests `GET /health?deep=1` with `Authorization: Bearer <valid key of an unblocked admin>`
- **THEN** the response is the probe's own answer (200 or 503 with the `checks` body) with `Cache-Control: no-store`, and the key's `last_used_at` is unchanged

#### Scenario: Database refuses connections during verification
- **WHEN** in `prod` the database refuses connections, no verification of the presented admin key is remembered, and the monitor requests the deep probe
- **THEN** the response is the identical 404 within 5 seconds of the request, the probe's dependency checks did not run, and a `warning` names the failure class

#### Scenario: Connection accepted but never completed, and a lost response
- **WHEN** in `prod` the database endpoint accepts the TCP connection and never completes the handshake, or completes it and then never delivers the response to the lookup, and the monitor requests the deep probe with a key that is not remembered
- **THEN** each response is the identical 404 within 5 seconds of the request and the probe's dependency checks did not run

#### Scenario: Delayed connection and stalled lookup
- **WHEN** in `prod` the database accepts the connection only after a delay and then does not answer the lookup statement, and the monitor requests the deep probe with an admin key that is not remembered
- **THEN** the response is the identical 404 within 5 seconds of the request and the probe's dependency checks did not run

#### Scenario: Redis that accepts and never answers during verification
- **WHEN** in `prod` the database is unreachable and Redis accepts the connection but never answers, and the monitor requests the deep probe
- **THEN** the response is the identical 404 within 5 seconds of the request and a `warning` names the failure class

#### Scenario: Every Redis operation slow
- **WHEN** in `prod` the database is unreachable and Redis answers the authentication and every command only after a delay, and the monitor requests the deep probe
- **THEN** the response leaves within 5 seconds of the request whatever the delay: a short delay on every operation still lets the memory answer, a delay longer than one command's share — even if only on the command after the lookup — ends the verification with the identical 404 and a `warning`

#### Scenario: A dependency that answers a piece at a time
- **WHEN** in `prod` the database is unreachable and Redis begins its reply to one of the memory's commands but delivers it a couple of bytes at a time, far too slowly to finish within that command's share
- **THEN** that command ends at its share, not when the reply finishes: the response is the identical 404 within 5 seconds of the request and a `warning` names the operation that ran out

#### Scenario: Database outage reported to a remembered monitor
- **WHEN** in `prod` a monitor's admin key was verified within the last 300 seconds, the database then refuses connections, and the monitor requests the deep probe again
- **THEN** the probe runs on the strength of the remembered verification and answers 503 with `checks.database` `"fail"`; had the lookup stalled on a database that still answers its check, the probe would have run and reported `database` as `"ok"`

#### Scenario: Revocation is forgotten immediately while the database is up
- **WHEN** in `prod` a monitor's admin key was verified and remembered, the key is revoked, and the monitor requests the deep probe
- **THEN** the response is the identical 404 and a subsequent database outage does not restore the monitor's access

#### Scenario: A verification that raced a revocation cannot undo it
- **WHEN** a request read the key as valid, the key is then revoked, a second request reads it as revoked and records the denial, and only then the first request's verification reaches the memory
- **THEN** the stale verification is not stored (a denial was recorded after that request began), and a following database outage refuses the monitor

#### Scenario: A denial token that expired cannot be mistaken for none
- **WHEN** a denial was recorded and its token later expired, a request read the (expired) state as its baseline and the key as valid, the key is then revoked and a second request records the denial, and only then the first request's verification reaches the memory
- **THEN** the stale verification is not stored — the new denial token differs from the baseline — and a following database outage refuses the monitor

#### Scenario: Redis unreachable when a denial is observed
- **WHEN** in `prod` a remembered monitor's key is revoked, the request that observes the revocation cannot reach Redis, and the database then becomes unreachable within 300 seconds of the last verification
- **THEN** the revocation itself answered 404, a `warning` named the memory failure, and the probe still runs for that monitor until the remembered verification expires from the memory (at most 300 seconds) — the accepted staleness

### Requirement: Health endpoint is outside the API contour
`/health` SHALL NOT be listed in the OpenAPI document, SHALL NOT require authentication, and SHALL send `Cache-Control: no-store`.

#### Scenario: Not documented, not cached
- **WHEN** a client fetches the OpenAPI document and then requests `GET /health`
- **THEN** the document contains no `/health` path, and the health response carries `Cache-Control: no-store`
