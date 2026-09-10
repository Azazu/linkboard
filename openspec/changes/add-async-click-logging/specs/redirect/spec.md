## ADDED Requirements

### Requirement: The redirect performs no database write
Serving `GET /{slug}` or `HEAD /{slug}` SHALL execute at most one SQL `SELECT` (the link by slug) and no SQL `INSERT`, `UPDATE` or `DELETE`; for a link with `maxClicks` it SHALL execute exactly one Redis command (the counter script of "Click limit is exact under concurrency"), for a link without `maxClicks` none. The click record is produced by dispatching one message to the asynchronous transport after the counter has answered; the response never waits for the record to be written.

#### Scenario: Statement kinds during a redirect
- **WHEN** a visitor is redirected through an unlimited link and then through a link with `maxClicks` 5
- **THEN** each request executed exactly one `SELECT` on `links` and no `INSERT`, `UPDATE` or `DELETE`; the second request executed one Redis counter command and the first none; one `ClickRecorded` message per request is on the transport

## MODIFIED Requirements

### Requirement: Public redirect endpoint
`GET /{slug}` and `HEAD /{slug}` SHALL be served without authentication, outside `/api`, and MUST NOT start a session or set a cookie. The slug MUST be matched exactly and case-sensitively against `^[A-Za-z0-9_-]{3,32}$`; a path that is not a slug is not this endpoint. Paths of application routes are never redirect slugs because the reserved-slug list of the `links` capability blocks them on write.

#### Scenario: Anonymous visitor is redirected
- **WHEN** an anonymous client requests `GET /promo-1` and `promo-1` is an active, unexpired link without a click limit
- **THEN** the response status is 302 and the response carries no `Set-Cookie` header

#### Scenario: A slug that begins with "api" is still public
- **WHEN** an anonymous client requests `GET /api-promo` for an active link, once without credentials and once with `Authorization: Bearer not-a-token`
- **THEN** both responses are 302; `GET /api/v1/me` with the same invalid bearer stays 401

#### Scenario: Slugs are case-sensitive
- **WHEN** only the link `Promo` exists and a client requests `GET /promo`
- **THEN** the response status is 404

#### Scenario: HEAD mirrors GET without recording
- **WHEN** a client sends `HEAD /promo-1` for an active link
- **THEN** the status and headers equal those of `GET /promo-1`, the body is empty, no counter command is issued and no message is dispatched (HEAD answers from the link state alone, so it is unaffected by counter or transport failures — see "Failures of the stores")

### Requirement: Click limit is exact under concurrency
Concurrent requests to a link with `maxClicks` SHALL never redirect more times than the limit allows. The authority is a Redis counter `link:{id}:clicks` driven by one atomic script executed with a single `EVAL` per redirect (the script body is sent every time; `EVALSHA` is not used, so a flushed script cache never costs a second round trip): the script seeds the key from the link's persisted `clickCount` when the key is absent, compares the current value with `maxClicks`, increments only when the redirect is allowed, and answers allowed or exhausted; exhausted answers 410 and dispatches nothing. With `maxClicks` M and N > M concurrent first redirects on an absent key, exactly M answer 302, N − M answer 410, the key is seeded exactly once and ends at M. Guarantee boundary: the limit holds exactly while the key exists. When the key is absent (first redirect of a limited link, or a Redis data loss) the seed is `clickCount` as persisted by the click handler, which lags the counter by the queue backlog, so the limit may be exceeded by at most the clicks that were queued but not yet persisted at seeding time; Redis persistence (AOF) is enabled in the Compose stack to make key loss exceptional. Links without `maxClicks` never touch the counter. The persisted `clickCount` reaching `maxClicks` also answers 410 without consulting the counter.

#### Scenario: Concurrent first redirects
- **WHEN** 10 concurrent requests hit a fresh link with `maxClicks` 3 whose counter key does not exist
- **THEN** exactly 3 responses are 302, 7 are 410, the counter key holds 3, and once the transport is consumed the link's `clickCount` is 3

#### Scenario: Seeding from the persisted count
- **WHEN** a link with `maxClicks` 3 has a persisted `clickCount` of 2 and no counter key, and three visitors are redirected in turn
- **THEN** the first answers 302 and the counter key holds 3, the second and third answer 410

#### Scenario: Limit change applies to the next redirect
- **WHEN** a link with `maxClicks` 3 and a counter at 3 is patched to `maxClicks` 5 and a visitor is redirected
- **THEN** the response status is 302 and the counter holds 4

### Requirement: Failures of the stores
Link lookup, the click counter and the message transport are three steps with three failure policies. If the link cannot be looked up (the link store is unreachable or errors), the endpoint SHALL answer 503 with `Retry-After: 5` for every slug and log at `error`; nothing can be resolved without the link. If the link has `maxClicks` and the counter cannot answer (Redis unreachable, the script fails), the endpoint SHALL answer 503 with `Retry-After: 5` and log at `warning` — the limit cannot be guaranteed; a link without `maxClicks` never consults the counter and is unaffected by its failure. If the counter allowed the redirect (or the link has no limit) and dispatching the message fails, the endpoint SHALL still answer 302 and log at `error` without personal data — a 302 that leaves no click record; the redirect never waits for or fails on logging. `HEAD` issues no counter command and no message and is unaffected by either failure. A crash between the counter's increment and the dispatch leaves one counted click without a record; this is bounded to one click per crash and is not prevented.

#### Scenario: Link lookup fails
- **WHEN** the link store errors while looking up `promo-1`
- **THEN** the response is 503 with `Retry-After: 5` and an `error` log record names the failure class

#### Scenario: Click write fails for a limited link
- **WHEN** the link was resolved, the counter throws, and the link has `maxClicks` 10
- **THEN** the response is 503 with `Retry-After: 5`, no message is dispatched, and a `warning` record names the link id and the failure class

#### Scenario: Counter unavailable does not affect an unlimited link
- **WHEN** the counter would throw and a visitor is redirected through a link without `maxClicks`
- **THEN** the response is 302, the counter was never called, and one message is dispatched

#### Scenario: Click write fails for an unlimited link
- **WHEN** the link was resolved, the transport rejects the dispatch, and the link has no `maxClicks`
- **THEN** the response is 302 to the destination, no click record exists for it, and an `error` record names the link id and the failure class but no IP or user agent

#### Scenario: Transport down after the counter allowed
- **WHEN** the transport rejects the dispatch for a link with `maxClicks` 10 whose counter allowed the redirect
- **THEN** the response is 302 to the destination, no click record exists, the counter still holds the increment, and an `error` record names the link id and the failure class
