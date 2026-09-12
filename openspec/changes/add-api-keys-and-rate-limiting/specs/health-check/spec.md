## MODIFIED Requirements

### Requirement: Deep dependency probe
The system SHALL answer `GET /health?deep=1` with a JSON body that reports each dependency (`database`, `redis`) as `"ok"` or `"fail"`, with HTTP 200 when all are `"ok"` and HTTP 503 when any is `"fail"`. Connecting to and querying each dependency MUST time out within 2 seconds so a hung dependency (reachable host, unresponsive service) cannot hang the probe; host name resolution happens before that timeout and is bounded by the platform resolver. In the `prod` environment the deep probe SHALL run only for a request carrying `Authorization: Bearer <API key>` that resolves (capability `api-keys`: hash lookup, not revoked, not expired) to an unblocked user with `ROLE_ADMIN`; every other request — no header, an unknown, revoked or expired key, a key of a non-admin or blocked user, a JWT, a session — SHALL be refused with 404 (RFC 9457 problem details, `Cache-Control: no-store`), the same 404 for every reason so the probe reveals nothing about keys. The key lookup itself MUST be bounded — connecting and the single lookup statement each time out within 1 second, so the lookup ends within 2 seconds — and MUST fail closed: when the lookup cannot be completed — the database refuses connections, stalls, or lacks the schema — the response is the same 404 within the lookup's budget, the probe is not executed, and a `warning` names the failure class; the lookup never records a use of the key. Sessions and JWTs never authorize the deep probe, because monitoring systems hold keys, not browser or user tokens. Outside `prod` the probe stays open as before.

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
- **WHEN** the application runs with `APP_ENV=prod` and a client requests `GET /health?deep=1` without a header, with a JWT, with a revoked admin key and with a valid key of a non-admin user
- **THEN** each response status is 404, the content type is `application/problem+json`, the body carries `type`, `title`, `status: 404` and `detail`, and the response has `Cache-Control: no-store`

#### Scenario: Deep probe in production while the database is down
- **WHEN** the application runs with `APP_ENV=prod`, the database refuses connections or does not answer, and a monitor requests `GET /health?deep=1` with a syntactically valid API key
- **THEN** the response is the 404 problem details (identical to the unauthorized one, with `Cache-Control: no-store`) within 3 seconds of the request, the probe's dependency checks were not run (no `checks` in the body), and a `warning` record names the failure class

#### Scenario: Deep probe in production with an admin key
- **WHEN** the application runs with `APP_ENV=prod` and a monitor requests `GET /health?deep=1` with `Authorization: Bearer <valid key of an unblocked admin>`
- **THEN** the response is the probe's own answer (200 or 503 with the `checks` body) with `Cache-Control: no-store`
