## ADDED Requirements

### Requirement: Click messages are handled idempotently
Every `ClickRecorded` message SHALL carry a unique `click_id` that becomes the click record's primary key. Handling a message SHALL insert the record and increment the link's `clickCount` in one transaction. A message delivered more than once (a redelivery after a lost acknowledgement, a manual retry from the failed transport) SHALL be acknowledged without a second record and without a second increment.

#### Scenario: Redelivery
- **WHEN** the same `ClickRecorded` message is handled twice
- **THEN** exactly one click record with that `click_id` exists, the link's `clickCount` grew by exactly one, and the second handling completed without an error

#### Scenario: Record and increment are one transaction
- **WHEN** the record insert fails for a reason other than a duplicate or a missing link
- **THEN** the link's `clickCount` is unchanged and the failure propagates to the transport's retry policy

### Requirement: Failed messages are retried, then parked
A `ClickRecorded` message whose handling throws SHALL be retried 3 times with exponential backoff (1 s, then 2 s, then 4 s) and, after the last failure, moved to the `failed` transport (the `messenger_messages` table) where it can be listed and retried with the Messenger console (`messenger:failed:show`, `messenger:failed:retry`). The `messenger_messages` table SHALL be created by a reversible migration, not at runtime.

#### Scenario: Persistent failure parks the message
- **WHEN** handling a message fails on every attempt and a worker consumes the transport
- **THEN** the handler ran four times (the first attempt and three retries, each retry delayed by the configured backoff), the message is then on the `failed` transport exactly once, no click record exists for it, and the link's `clickCount` is unchanged

#### Scenario: Migration is reversible
- **WHEN** the migration creating `messenger_messages` is executed and then reverted
- **THEN** the table exists after the migration and is gone after the revert

### Requirement: Messages for deleted links are discarded
A `ClickRecorded` message whose link no longer exists SHALL be acknowledged and discarded on its first handling with an `info` log record naming the link id and the `click_id` — never retried and never moved to the failed transport.

#### Scenario: Link deleted before the message is handled
- **WHEN** a visitor is redirected, the link is deleted through `DELETE /api/v1/links/{id}` before the worker handles the message, and a worker then consumes the transport
- **THEN** no click record exists, the handler ran once, the message is acknowledged on that attempt — neither retried nor on the failed transport — and one `info` record names the link id and the `click_id`; no failure record is written

### Requirement: The queue carries no raw personal data
The `ClickRecorded` message SHALL carry the finished click facts — `click_id`, link id, `occurred_at`, `country`, `device_type`, `os`, `browser`, `is_bot`, `resolved_by`, `variant`, `referer_host` and the salted `visitor_hash` — and MUST NOT carry the client IP, the user agent, the `Accept-Language` or `Referer` header values.

#### Scenario: Message contents
- **WHEN** a visitor with IP `203.0.113.7` and `User-Agent: Probe/1.0` is redirected with `Referer: https://News.Example.org/story`
- **THEN** the dispatched message's serialized form contains the `visitor_hash` and `news.example.org` and contains neither `203.0.113.7` nor `Probe/1.0` nor the full referer URL

## MODIFIED Requirements

### Requirement: One click per successful redirect
Every 302 answered by `GET /{slug}` SHALL dispatch exactly one `ClickRecorded` message, and handling that message SHALL leave exactly one click record for the link and increment the link's `clickCount` by one; the record and the increment MUST be committed together or not at all. The record therefore appears when the worker handles the message, not when the response is sent, and `clickCount` in link responses is eventually consistent (it lags by the queue backlog). Responses other than 302 (404, 410, 429, 503) and `HEAD` requests MUST dispatch nothing. The exceptions are the failure policies of the `redirect` capability ("Failures of the stores"): a 302 whose dispatch failed leaves no message and no record and is logged.

#### Scenario: One redirect, one click
- **WHEN** an anonymous client is redirected once through the link `promo-1` and the transport is consumed
- **THEN** exactly one click record exists for the link and `GET /api/v1/links/{id}` shows `clickCount` 1

#### Scenario: Before the worker runs
- **WHEN** an anonymous client is redirected once through `promo-1` and the transport has not been consumed
- **THEN** no click record exists yet, `clickCount` is still 0, and exactly one `ClickRecorded` message is waiting on the transport

#### Scenario: Non-redirects record nothing
- **WHEN** clients request an unknown slug, an inactive link and an expired link
- **THEN** no message is dispatched, no click record is created and the inactive and expired links keep their `clickCount`

### Requirement: Clicks follow their link on deletion
Deleting a link SHALL delete its click records, SHALL remove its Redis counter key (best effort: a Redis failure is logged at `warning` and does not fail the deletion), and messages still queued for the link SHALL be discarded by the handler (see "Messages for deleted links are discarded").

#### Scenario: Cascade
- **WHEN** a link with 2 click records and a counter key is deleted through `DELETE /api/v1/links/{id}`
- **THEN** the delete response is 204, no click record for that link remains, and the counter key no longer exists

#### Scenario: Redis down at deletion
- **WHEN** the counter store throws while a link is deleted
- **THEN** the delete response is still 204, the link and its click records are gone, and a `warning` record names the link id
