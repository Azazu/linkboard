# Click Logging

## Purpose
What a successful redirect leaves behind: exactly one click record and one increment of the link's click count, with the visitor's personal data minimised. This is the write model that analytics later reads; the mechanism that writes it may change, the record and its guarantees do not.

## Requirements

### Requirement: One click per successful redirect
While the click store is reachable, every 302 answered by `GET /{slug}` SHALL leave exactly one click record for that link and SHALL increment the link's `clickCount` by one; the record and the increment MUST be committed together or not at all. Responses other than 302 (404, 410, 429, 503) and `HEAD` requests MUST record nothing. The single exception is the write-failure policy of the `redirect` capability ("Failures of the stores"): a 302 for a link without `maxClicks` whose click write failed leaves no record and is logged.

#### Scenario: One redirect, one click
- **WHEN** an anonymous client is redirected once through the link `promo-1`
- **THEN** exactly one click record exists for the link and `GET /api/v1/links/{id}` shows `clickCount` 1

#### Scenario: Non-redirects record nothing
- **WHEN** clients request an unknown slug, an inactive link and an expired link
- **THEN** no click record is created and the inactive and expired links keep their `clickCount`

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
Deleting a link SHALL delete its click records.

#### Scenario: Cascade
- **WHEN** a link with 2 click records is deleted through `DELETE /api/v1/links/{id}`
- **THEN** no click record for that link remains
