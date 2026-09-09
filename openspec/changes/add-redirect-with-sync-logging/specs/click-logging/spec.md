## Purpose
What a successful redirect leaves behind: exactly one click record and one increment of the link's click count, with the visitor's personal data minimised. This is the write model that analytics later reads; the mechanism that writes it may change, the record and its guarantees do not.

## ADDED Requirements

### Requirement: One click per successful redirect
Every 302 answered by `GET /{slug}` SHALL leave exactly one click record for that link and SHALL increment the link's `clickCount` by one; the record and the increment MUST be committed together or not at all. Responses other than 302 (404, 410, 429, 503) and `HEAD` requests MUST record nothing.

#### Scenario: One redirect, one click
- **WHEN** an anonymous client is redirected once through the link `promo-1`
- **THEN** exactly one click record exists for the link and `GET /api/v1/links/{id}` shows `clickCount` 1

#### Scenario: Non-redirects record nothing
- **WHEN** clients request an unknown slug, an inactive link and an expired link
- **THEN** no click record is created and the inactive and expired links keep their `clickCount`

### Requirement: Click record contents
A click record SHALL carry a unique id, the link reference, `occurred_at`, a `visitor_hash` (hex SHA-256 over a configured salt, the client IP and the user agent), `referer_host` (the host of the `Referer` header, lower-case; null when the header is absent, unparseable or names the service's own public host), `is_bot` false, `resolved_by` `default`, and null `variant`, `country`, `device_type`, `os` and `browser` until routing rules and detection exist. Two requests from the same IP and user agent MUST produce the same `visitor_hash`; a different user agent or a different salt MUST produce a different one.

#### Scenario: Visitor hash is stable per IP and user agent
- **WHEN** the same client IP with the same `User-Agent` is redirected twice, then once more with another `User-Agent`
- **THEN** the first two records share one `visitor_hash` and the third has a different one

#### Scenario: Referer host
- **WHEN** a client is redirected with `Referer: https://News.Example.org/story?id=1`, then without a `Referer`, then with a `Referer` on the service's own public host
- **THEN** the three records have `referer_host` `news.example.org`, null and null

### Requirement: Personal data minimisation
The raw client IP and the raw user agent MUST NOT be persisted in the click record and MUST NOT appear in log records at level `info` or above. The salt MUST come from configuration, never from code; changing it breaks unique-visitor continuity across the change, which is documented.

#### Scenario: Neither IP nor user agent is stored
- **WHEN** a client with IP `203.0.113.7` and `User-Agent: Probe/1.0` is redirected
- **THEN** no column of the click record contains `203.0.113.7` or `Probe/1.0`, and the record's columns are exactly id, link id, occurred_at, country, device_type, os, browser, is_bot, referer_host, visitor_hash, variant, resolved_by

### Requirement: Untrusted header bounds
`User-Agent` MUST be truncated to 1024 bytes and `Referer` to 2048 bytes before hashing or parsing; oversized or malformed values MUST never turn into an error response.

#### Scenario: Oversized user agent
- **WHEN** a client sends an 8 KB `User-Agent` and a 4 KB `Referer` to an active link
- **THEN** the response is 302 and exactly one click record is created

### Requirement: Clicks follow their link on deletion
Deleting a link SHALL delete its click records.

#### Scenario: Cascade
- **WHEN** a link with 2 click records is deleted through `DELETE /api/v1/links/{id}`
- **THEN** no click record for that link remains
