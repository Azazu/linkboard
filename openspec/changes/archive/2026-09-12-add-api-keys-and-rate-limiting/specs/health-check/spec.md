## MODIFIED Requirements

### Requirement: Deep dependency probe
The system SHALL answer `GET /health?deep=1` with a JSON body that reports each dependency (`database`, `redis`) as `"ok"` or `"fail"`, with HTTP 200 when all are `"ok"` and HTTP 503 when any is `"fail"`. Connecting to and querying each dependency MUST time out within 2 seconds so a hung dependency (reachable host, unresponsive service) cannot hang the probe; host name resolution happens before that timeout and is bounded by the platform resolver. In the `prod` environment the deep probe SHALL be refused with 404 (RFC 9457 problem details, `Cache-Control: no-store`) until the change `authorize-deep-probe-by-api-key` (the follow-up of `add-api-keys-and-rate-limiting`, which provides the keys) authorizes it for requests carrying a valid **admin** API key; sessions and JWTs never authorize the deep probe, because monitoring systems hold keys, not browser or user tokens. Until that change the 404 is unconditional in `prod`.

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
