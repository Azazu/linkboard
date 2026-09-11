# Click Logging

## Purpose
What a successful redirect leaves behind: exactly one click record and one increment of the link's click count, with the visitor's personal data minimised. This is the write model that analytics later reads; the mechanism that writes it may change, the record and its guarantees do not.

## Requirements

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

### Requirement: Click record contents
A click record SHALL carry a unique id, the link reference, `occurred_at`, a `visitor_hash` (hex SHA-256 over a configured salt, the client IP and the user agent), `referer_host` (the host of the `Referer` header, lower-case; null when the header is absent, unparseable, longer than 255 bytes, not valid UTF-8, containing control characters, or naming the service's own public host), and the outcome of the `routing-rules` capability for this visit: `device_type`, `os`, `browser`, `is_bot` from detection (null device, OS or browser when unrecognised; `is_bot` false when unknown), `country` from country resolution (null when unknown), `resolved_by` (`device`, `country`, `language`, `variant` or `default`) and `variant` (the assigned variant's name, null unless `resolved_by` is `variant`). Two requests from the same IP and user agent MUST produce the same `visitor_hash`; a different user agent or a different salt MUST produce a different one.

#### Scenario: Visitor hash is stable per IP and user agent
- **WHEN** the same client IP with the same `User-Agent` is redirected twice, then once more with another `User-Agent`
- **THEN** the first two records share one `visitor_hash` and the third has a different one

#### Scenario: Referer host
- **WHEN** a client is redirected with `Referer: https://News.Example.org/story?id=1`, then without a `Referer`, then with a `Referer` on the service's own public host
- **THEN** the three records have `referer_host` `news.example.org`, null and null

#### Scenario: Resolution recorded
- **WHEN** an iPhone visitor from Germany (test map) with `Accept-Language: de` is redirected through a link whose device rule matches, and then a desktop Linux visitor from an unmapped IP without `Accept-Language` is redirected through a link with variants only
- **THEN** the first record has `device_type` `smartphone`, `os` `iOS`, a non-null `browser`, `is_bot` false, `country` `DE`, `resolved_by` `device`, `variant` null; the second has `device_type` `desktop`, `os` `Linux`, `country` null, `resolved_by` `variant` and `variant` equal to the assigned name

#### Scenario: Plain link
- **WHEN** a visitor is redirected through a link without rules
- **THEN** the record has `resolved_by` `default` and `variant` null while `device_type`, `os`, `browser`, `is_bot` and `country` still reflect detection and resolution

### Requirement: Personal data minimisation
The raw client IP and the raw user agent MUST NOT be persisted in the click record and MUST NOT appear in log records at level `info` or above. The salt MUST come from configuration, never from code; changing it breaks unique-visitor continuity across the change, which is documented.

#### Scenario: Neither IP nor user agent is stored
- **WHEN** a client with IP `203.0.113.7` and `User-Agent: Probe/1.0` is redirected
- **THEN** no column of the click record contains `203.0.113.7` or `Probe/1.0`, and the record's columns are exactly id, link id, occurred_at, country, device_type, os, browser, is_bot, referer_host, visitor_hash, variant, resolved_by

### Requirement: Untrusted header bounds
`User-Agent` MUST be truncated to 1024 bytes and `Referer` to 2048 bytes before hashing or parsing, and the extracted `referer_host` MUST satisfy the column's constraints (at most 255 bytes, valid UTF-8, no control characters) or become null; oversized or malformed values MUST never turn into an error response or a lost click, for limited and unlimited links alike.

#### Scenario: Oversized user agent and referer
- **WHEN** a client sends an 8 KB `User-Agent` and a 4 KB `Referer` to an active link
- **THEN** the response is 302 and exactly one click record is created

#### Scenario: Hostile referer host
- **WHEN** clients send a `Referer` whose host is 300 characters long, and another whose bytes are not valid UTF-8, to an unlimited link and to a link with `maxClicks` 5
- **THEN** every response is 302, every request leaves exactly one click record, and each record's `referer_host` is null

### Requirement: Clicks follow their link on deletion
Deleting a link SHALL delete its click records, SHALL remove its Redis counter key (best effort: a Redis failure is logged at `warning` and does not fail the deletion), and messages still queued for the link SHALL be discarded by the handler (see "Messages for deleted links are discarded").

#### Scenario: Cascade
- **WHEN** a link with 2 click records and a counter key is deleted through `DELETE /api/v1/links/{id}`
- **THEN** the delete response is 204, no click record for that link remains, and the counter key no longer exists

#### Scenario: Redis down at deletion
- **WHEN** the counter store throws while a link is deleted
- **THEN** the delete response is still 204, the link and its click records are gone, and a `warning` record names the link id

### Requirement: Click messages are handled idempotently
Every `ClickRecorded` message SHALL carry a unique `click_id` that becomes the click record's primary key. Handling a message SHALL insert the record and increment the link's `clickCount` in one transaction. A message delivered more than once (a redelivery after a lost acknowledgement, a manual retry from the failed transport) SHALL be acknowledged without a second record and without a second increment.

#### Scenario: Redelivery
- **WHEN** the same `ClickRecorded` message is handled twice
- **THEN** exactly one click record with that `click_id` exists, the link's `clickCount` grew by exactly one, and the second handling completed without an error

#### Scenario: Record and increment are one transaction
- **WHEN** the record insert fails for a reason other than a duplicate or a missing link
- **THEN** the link's `clickCount` is unchanged and the failure propagates to the transport's retry policy

### Requirement: Failed messages are retried, then parked
A `ClickRecorded` message whose handling throws SHALL be retried 3 times with exponential backoff of exactly 1 s, 2 s and 4 s (multiplier 2, no jitter) and, after the last failure, moved to the `failed` transport (the `messenger_messages` table) where it can be listed and retried with the Messenger console (`messenger:failed:show`, `messenger:failed:retry`). The `messenger_messages` table SHALL be created — or, when the previous configuration's auto-setup transport already created it, adopted with its rows — by a migration, never at runtime; the migration's rollback SHALL keep the table and its rows (the table is shared with the transport, which parks messages in its own transactions — a drop would race a concurrent parking), and re-applying the migration SHALL be idempotent.

#### Scenario: Persistent failure parks the message
- **WHEN** handling a message fails on every attempt and a worker consumes the transport
- **THEN** the handler ran four times (the first attempt and three retries, each retry carrying exactly the configured delay and a retry count of 1, 2 and 3 in the worker's retry events), the message is then on the `failed` transport exactly once, no click record exists for it, and the link's `clickCount` is unchanged

#### Scenario: Migration is reversible
- **WHEN** the migration is executed on a fresh database, reverted, and executed again; and when it is executed on a database whose auto-setup transport already created `messenger_messages` holding a parked message, then reverted
- **THEN** the table exists after every execution with exactly one index besides the primary key, the revert keeps the table and its rows, the parked message survives adoption and revert alike, and re-applying changes nothing

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
