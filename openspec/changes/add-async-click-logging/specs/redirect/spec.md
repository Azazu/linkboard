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
Concurrent requests to a link with `maxClicks` SHALL never redirect more times than the limit allows. The authority is a Redis counter `link:{id}:clicks` driven by one atomic script executed with a single `EVAL` per redirect (the script body is sent every time; `EVALSHA` is not used, so a flushed script cache never costs a second round trip): the script receives the link's persisted `clickCount` and `maxClicks`, lifts the key to the persisted count when the key is absent or below it (it never lowers a key), compares the current value with `maxClicks`, increments only when the redirect is allowed, and answers allowed or exhausted; exhausted answers 410 and dispatches nothing. With `maxClicks` M and N > M concurrent first redirects on an absent key, exactly M answer 302, N − M answer 410, the key is seeded exactly once and ends at M. Because the key is lifted to the persisted count on every call, a limit set or changed on a link — including after a period without a limit, during which the counter was not used — takes effect for every redirect that loads the link after the change, without any other step. Each redirect evaluates the `maxClicks` and `clickCount` it loaded in its single `SELECT` and never re-reads them: a request in flight at the moment of a limit change completes under the limit it loaded (an unlimited snapshot does not consult the counter; a snapshot with a higher maximum may increment past a lowered one), the number of such requests is bounded by the requests in flight at the change, every one of them dispatches its message, and once persisted their clicks stop further redirects: the persisted `clickCount` reaching `maxClicks` answers 410 before the counter is consulted (the key is left unchanged), and the counter is lifted to the persisted count on the next call that reaches it. Guarantee boundary: the limit is exact while the key exists and reflects every accepted redirect since it was last seeded. The seed is the persisted `clickCount` **as the seeding request read it in its single `SELECT`** — the request never re-reads it, so clicks persisted between that read and the seeding are not in the seed either. Whenever the key is seeded or lifted (first redirect of a limited link, a limit set after an unlimited period, a Redis data loss), the seed therefore excludes every accepted redirect not yet persisted *at the moment the seeding request read the count* — messages still queued or persisted since that read, messages whose dispatch failed, messages parked on the failed transport, crashes between the increment and the dispatch — so the limit MAY be exceeded by at most that number per seeding, plus the requests in flight at a limit change, compounding with repeated key loss; the `clicks` table stays the exact record of what was persisted. Redis persistence (AOF) is enabled in the Compose stack to make key loss exceptional. Links without `maxClicks` never touch the counter. The persisted `clickCount` reaching `maxClicks` also answers 410 without consulting the counter.

#### Scenario: Concurrent first redirects
- **WHEN** 10 concurrent requests hit a fresh link with `maxClicks` 3 whose counter key does not exist
- **THEN** exactly 3 responses are 302, 7 are 410, the counter key holds 3, and once the transport is consumed the link's `clickCount` is 3

#### Scenario: Seeding from the persisted count
- **WHEN** a link with `maxClicks` 3 has a persisted `clickCount` of 2 and no counter key, and three visitors are redirected in turn
- **THEN** the first answers 302 and the counter key holds 3, the second and third answer 410

#### Scenario: Limit change applies to the next redirect
- **WHEN** a link with `maxClicks` 3 and a counter at 3 is patched to `maxClicks` 5 and a visitor is redirected
- **THEN** the response status is 302 and the counter holds 4

#### Scenario: Limit re-enabled after unlimited redirects
- **WHEN** a link with `maxClicks` 3 and a counter at 3 is patched to no limit, four visitors are redirected and their messages are consumed (persisted `clickCount` 7, counter key still 3), the link is patched to `maxClicks` 10, and then four visitors are redirected
- **THEN** the first three answer 302 with the counter lifted to 7 and ending at 10, the fourth answers 410

#### Scenario: Limit re-enabled while unlimited-period messages are still queued
- **WHEN** a link with `maxClicks` 3 and a counter at 3 is patched to no limit, four visitors are redirected and their messages are *not* consumed (persisted `clickCount` still 3), the link is patched to `maxClicks` 10, and then eight visitors are redirected
- **THEN** the first seven answer 302 (the key was 3 and is not lifted — the four queued clicks are unpersisted, the stated overshoot) and the eighth answers 410; after the queue is consumed the persisted `clickCount` is 14, the next redirect answers 410 from the persisted count without consulting the counter (the key stays 10), and after the limit is raised to 20 the next redirect lifts the key to 14 and answers 302 with the counter at 15

#### Scenario: Limit changed while requests are in flight
- **WHEN** ten redirects have loaded an unlimited link (persisted `clickCount` 0) and pause before recording, the link is patched to `maxClicks` 3, three new redirects run to completion, and then the ten paused redirects complete and their messages are consumed
- **THEN** the three new redirects answer 302 with the counter at 3, the ten paused redirects also answer 302 without consulting the counter (their snapshot has no limit — thirteen accepted redirects, the stated in-flight bound), the persisted `clickCount` becomes 13, the next redirect answers 410 from the persisted count without consulting the counter (the key stays 3), and after the limit is raised to 20 the next redirect lifts the key to 13 and answers 302 with the counter at 14

#### Scenario: A seed snapshot that goes stale before a key loss
- **WHEN** a link with `maxClicks` 3 has a counter at 3 and three accepted messages queued (persisted `clickCount` 0), three more requests load the link (count 0) and pause before recording, the worker persists the three queued clicks, the counter key is lost, and the paused requests then complete
- **THEN** the three paused requests answer 302 (their seed is the count they read, 0 — six accepted redirects for a limit of 3, the stated bound: three were unpersisted when their snapshot was read), and a redirect that loads the link afterwards (count 3) answers 410

#### Scenario: Dispatch failures then key loss show the stated overshoot
- **WHEN** a link with `maxClicks` 3 and persisted `clickCount` 0 serves three redirects while the transport rejects every dispatch (counter 3, nothing persisted), the counter key is then deleted, and three more visitors are redirected
- **THEN** the three later visitors answer 302 (the seed is the persisted 0 — the overshoot equals the three unpersisted accepted redirects, as the guarantee boundary states) and a fourth answers 410

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
