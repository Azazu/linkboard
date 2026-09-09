# Links

## Purpose
A link is the unit users own: a short slug that resolves to a target URL under rules, with optional UTM tags, expiry and click limit. This capability covers creating, reading, updating and deleting links and who may do so; redirecting through them is a separate capability.

## Requirements

### Requirement: Create a link with a generated or custom slug
An authenticated user SHALL create a link from a required `targetUrl` and optional `slug`, `expiresAt`, `maxClicks` and `utm`. Without `slug` the system generates a 7-character slug from `[A-Za-z0-9]` using a cryptographically secure source; a collision with an existing slug at insert time is retried with a new candidate a bounded number of times (5), and the request answers 500 (logged) only when every candidate collided. The response is 201 with `id`, `slug`, `shortUrl`, `targetUrl`, `utm`, `expiresAt`, `maxClicks`, `isActive: true`, `clickCount: 0`, `createdAt`, `updatedAt`.

#### Scenario: Generated slug
- **WHEN** a user posts `{"targetUrl":"https://example.com/landing"}` to `/api/v1/links`
- **THEN** the response status is 201, `slug` matches `^[A-Za-z0-9]{7}$`, `shortUrl` is the public base URL followed by `/` and the slug, and `clickCount` is 0

#### Scenario: Custom slug
- **WHEN** a user posts `{"targetUrl":"https://example.com","slug":"spring-sale_2026"}`
- **THEN** the response status is 201 and `slug` is exactly `spring-sale_2026`

#### Scenario: Generated slug collides at insert time
- **WHEN** the first generated candidate is taken by a concurrent insert after the pre-check and before the write
- **THEN** the request still answers 201 with the next free candidate; when five candidates in a row collide the response is 500 and an error is logged

### Requirement: Slug rules
A custom slug MUST match `^[A-Za-z0-9_-]{3,32}$` as the entire string (a trailing newline is not part of a match), MUST NOT be in the reserved list (at least `api`, `admin`, `login`, `logout`, `register`, `dashboard`, `links`, `api-keys`, `health`, `docs`, `qr`, `assets`, `build`, `bundles`, `_profiler`, `_wdt`, `_error`), and MUST be unique case-sensitively: `abc` and `ABC` are different links. Violations are 422 with a `violations` entry for `slug`. The slug is immutable: an update that carries a slug different from the current one is rejected with 422.

#### Scenario: Reserved word
- **WHEN** a user posts a link with `slug` `admin`
- **THEN** the response status is 422 with a violation on `slug`

#### Scenario: Too short, too long or bad characters
- **WHEN** a user posts `slug` `ab`, a 33-character slug, or `a b`
- **THEN** each response status is 422 with a violation on `slug`

#### Scenario: Case-sensitive uniqueness
- **WHEN** a link with slug `Sale` exists and a user posts a link with slug `sale`
- **THEN** the response status is 201; posting `Sale` again yields 422 with a violation on `slug`

#### Scenario: Slug cannot change
- **WHEN** the owner patches an existing link with `{"slug":"other"}`
- **THEN** the response status is 422 with a violation on `slug` and the stored slug is unchanged

### Requirement: Target URL policy
`targetUrl` MUST be an absolute URL of at most 2048 characters with scheme `http` or `https` and a host, without userinfo (`user@host`), and without backslashes or control characters anywhere (`parse_url` and browsers read those differently, so no address judgement on such a URL is trustworthy). The host MUST NOT be `localhost` or a literal IP in the loopback (`127.0.0.0/8`, `::1`), link-local (`169.254.0.0/16`, `fe80::/10`) or private (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7`) ranges. The host MUST first be mapped to ASCII per UTS #46 as browsers do (fullwidth `１２７.０.０.１` is `127.0.0.1`, `ｌｏｃａｌｈｏｓｔ` is `localhost`, `пример.рф` is its punycode, `。` and `．` are dots), the single trailing dot removed only after that mapping; a host the mapping rejects, or one with an empty label, MUST be rejected. A literal IP MUST be recognised in every form the WHATWG URL host parser accepts (shorthand `127.1`, decimal `2130706433`, hexadecimal `0x7f000001`, octal `0177.0.0.1`, trailing dot) and judged by the resulting address; a host that ends in a number but is not a valid IPv4, and any percent-encoded host, MUST be rejected. An empty string is not a URL and MUST be rejected wherever `targetUrl` is present. No name resolution is performed at validation time. Violations are 422 with a violation on `targetUrl`.

#### Scenario: Accepted store links
- **WHEN** a user posts `https://apps.apple.com/app/id123` or `https://play.google.com/store/apps/details?id=com.example`
- **THEN** each response status is 201

#### Scenario: Rejected schemes and hosts
- **WHEN** a user posts each of `market://details?id=x`, `ftp://example.com/f`, `javascript:alert(1)`, `http://localhost/`, `http://127.0.0.1/`, `http://[::1]/`, `http://10.0.0.5/`, `http://172.16.9.1/`, `http://192.168.1.1/`, `http://169.254.169.254/latest/meta-data`, `http://[fe80::1]/`, `http://[fd00::1]/`, `not a url`, and a 2049-character `https://` URL
- **THEN** each response status is 422 with a violation on `targetUrl`

### Requirement: UTM, expiry and click limit fields
`utm` MUST be an object with only the keys `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, each a string of at most 255 characters; `expiresAt` MUST be a future RFC 3339 timestamp; `maxClicks` MUST be a positive integer. All three are optional and independent; violations are 422 on the field.

#### Scenario: Valid optional fields
- **WHEN** a user posts a link with `utm` `{"utm_source":"newsletter","utm_campaign":"spring"}`, `expiresAt` one day ahead and `maxClicks` 100
- **THEN** the response status is 201 and echoes the three values

#### Scenario: Invalid optional fields
- **WHEN** a user posts `utm` `{"utm_foo":"x"}`, or `expiresAt` in the past, or `maxClicks` 0
- **THEN** each response status is 422 with a violation on the offending field

### Requirement: Read and list own links
`GET /api/v1/links/{id}` SHALL return the link with `shortUrl` and `clickCount`. `GET /api/v1/links` SHALL list only the caller's links in the pagination envelope (`items`, `totalItems`, `page`, `itemsPerPage`; default 30, maximum 100), newest first by default, with filters `isActive` (boolean) and `slug` (case-sensitive substring) and `order[createdAt]` / `order[clickCount]` in `asc` or `desc`.

#### Scenario: Only the caller's links
- **WHEN** user A owns two links and user B owns one, and A requests `GET /api/v1/links`
- **THEN** `totalItems` is 2 and every item's `id` belongs to A

#### Scenario: Filters and order
- **WHEN** A owns links `promo-1` (active) and `promo-2` (inactive) and requests `GET /api/v1/links?isActive=false&slug=promo`
- **THEN** `items` contains only `promo-2`; `GET /api/v1/links?order[createdAt]=asc` returns A's links oldest first

### Requirement: Update a link
`PATCH /api/v1/links/{id}` with `application/merge-patch+json` SHALL update only the fields present in the body, applying the same validation as creation, and return 200 with the updated link and a new `updatedAt`. Null contract: `expiresAt`, `maxClicks` and `utm` present with `null` are cleared; `targetUrl` and `isActive` MUST NOT be `null` when present (422); absent fields are unchanged; a body that is not a JSON object is 400. Deactivated links keep their data.

#### Scenario: Deactivate and change the target
- **WHEN** the owner patches `{"isActive":false,"targetUrl":"https://example.org/new"}`
- **THEN** the response status is 200, `isActive` is false and `targetUrl` is `https://example.org/new`

#### Scenario: Absent fields are preserved
- **WHEN** a link has `maxClicks` 100 and `expiresAt` one day ahead and the owner patches `{"isActive":false}`
- **THEN** the response status is 200, `isActive` is false, and `maxClicks` and `expiresAt` are unchanged

#### Scenario: Explicit null clears one field
- **WHEN** the same link is patched with `{"maxClicks":null}`
- **THEN** the response status is 200, `maxClicks` is null and `expiresAt` is unchanged

#### Scenario: Null where a value is required
- **WHEN** the owner patches `{"targetUrl":null}` or `{"isActive":null}`
- **THEN** each response status is 422 with a violation on the field

#### Scenario: Invalid patch
- **WHEN** the owner patches `{"targetUrl":"http://10.0.0.1/"}`
- **THEN** the response status is 422 with a violation on `targetUrl` and the stored link is unchanged

### Requirement: Delete a link
`DELETE /api/v1/links/{id}` SHALL remove the link permanently and answer 204; a subsequent `GET` returns 404 and the slug can be used by a new link at once. Rows in dependent tables reference `links(id)` with `ON DELETE CASCADE`.

#### Scenario: Delete then reuse the slug
- **WHEN** the owner deletes the link with slug `sale` and then posts a new link with slug `sale`
- **THEN** the delete response is 204, the following `GET` of the old id is 404, and the new post is 201

### Requirement: Ownership and admin access
Item operations (`GET`, `PATCH`, `DELETE` on `/api/v1/links/{id}`) SHALL be allowed for the link's owner and for users with `ROLE_ADMIN`; any other authenticated user receives 403 `application/problem+json`; anonymous callers receive 401. `GET /api/v1/admin/links` SHALL list every user's links (same envelope, filters and order) for admins only, each item carrying `ownerId`.

#### Scenario: Stranger, admin, anonymous
- **WHEN** user B requests `GET`, `PATCH` and `DELETE` on a link owned by A
- **THEN** each response status is 403 with `application/problem+json`; the same requests by an admin succeed (200, 200, 204); the same requests without a token are 401

#### Scenario: Admin listing
- **WHEN** an admin requests `GET /api/v1/admin/links` while A and B own links
- **THEN** the response lists both users' links with `ownerId`, and a regular user requesting the same path gets 403

### Requirement: Admin actions on links are audited
When a user with `ROLE_ADMIN` who is not the owner updates, deactivates, reactivates or deletes a link, the system SHALL write one log record at level `info` on the audit channel with `action` (`link.update`, `link.deactivate`, `link.activate` or `link.delete`), `actor_id`, `target_id` (the link id) and `owner_id`, and without slug, URL or email. Owners acting on their own links are not audited.

#### Scenario: Admin deactivates and deletes a user's link
- **WHEN** an admin patches A's link with `{"isActive":false}` and then deletes it
- **THEN** the audit log contains exactly one `link.deactivate` and one `link.delete` record, each with exactly the context keys `action`, `actor_id`, `target_id`, `owner_id` (the admin's, the link's and A's ids), and no record contains the slug, the target URL or any email address

#### Scenario: Admin updates and reactivates a user's link
- **WHEN** an admin patches A's inactive link with `{"targetUrl":"https://example.org/moved"}` and then with `{"isActive":true}`
- **THEN** the audit log contains exactly one `link.update` and one `link.activate` record with the same three ids and the same exact context shape, and neither contains `example.org/moved`, the slug or an email; a single patch that changes `isActive` together with another field writes exactly one record, `link.activate` or `link.deactivate`

#### Scenario: Owner is not audited
- **WHEN** A deactivates their own link
- **THEN** no audit record is written

#### Scenario: Rejected or failed admin actions leave no record
- **WHEN** a stranger's PATCH is refused with 403, an admin's PATCH is rejected with 422 (invalid `targetUrl`), and an admin's PATCH fails during persistence (the write is aborted)
- **THEN** none of the three writes an audit record
