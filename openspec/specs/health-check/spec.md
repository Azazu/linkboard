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
The system SHALL answer `GET /health?deep=1` with a JSON body that reports each dependency (`database`, `redis`) as `"ok"` or `"fail"`, with HTTP 200 when all are `"ok"` and HTTP 503 when any is `"fail"`. Connecting to and querying each dependency MUST time out within 2 seconds so a hung dependency (reachable host, unresponsive service) cannot hang the probe; host name resolution happens before that timeout and is bounded by the platform resolver. In the `prod` environment the deep probe SHALL be refused with 404 until an authorization boundary exists (introduced by the users-and-security change).

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
- **WHEN** the application runs with `APP_ENV=prod` and a client requests `GET /health?deep=1`
- **THEN** the response status is 404, the content type is `application/problem+json`, the body carries `type`, `title`, `status: 404` and `detail`, and the response has `Cache-Control: no-store`

### Requirement: Health endpoint is outside the API contour
`/health` SHALL NOT be listed in the OpenAPI document, SHALL NOT require authentication, and SHALL send `Cache-Control: no-store`.

#### Scenario: Not documented, not cached
- **WHEN** a client fetches the OpenAPI document and then requests `GET /health`
- **THEN** the document contains no `/health` path, and the health response carries `Cache-Control: no-store`
