## MODIFIED Requirements

### Requirement: Read and list own links
`GET /api/v1/links/{id}` SHALL return the link with `shortUrl` and `clickCount`; `clickCount` is maintained by the asynchronous click handler and is eventually consistent — it lags the redirects by the queue backlog. `GET /api/v1/links` SHALL list only the caller's links in the pagination envelope (`items`, `totalItems`, `page`, `itemsPerPage`; default 30, maximum 100), newest first by default, with filters `isActive` (boolean) and `slug` (case-sensitive substring) and `order[createdAt]` / `order[clickCount]` in `asc` or `desc`.

#### Scenario: Only the caller's links
- **WHEN** user A owns two links and user B owns one, and A requests `GET /api/v1/links`
- **THEN** `totalItems` is 2 and every item's `id` belongs to A

#### Scenario: Filters and order
- **WHEN** A owns links `promo-1` (active) and `promo-2` (inactive) and requests `GET /api/v1/links?isActive=false&slug=promo`
- **THEN** `items` contains only `promo-2`; `GET /api/v1/links?order[createdAt]=asc` returns A's links oldest first

#### Scenario: Click count catches up
- **WHEN** a link is redirected through twice and the owner reads it before and after the transport is consumed
- **THEN** `clickCount` is 0 before and 2 after

### Requirement: Delete a link
`DELETE /api/v1/links/{id}` SHALL remove the link permanently and answer 204; a subsequent `GET` returns 404 and the slug can be used by a new link at once. Rows in dependent tables reference `links(id)` with `ON DELETE CASCADE`; the link's Redis click counter is removed (best effort) and click messages still queued for it are discarded by the handler (capability `click-logging`).

#### Scenario: Delete then reuse the slug
- **WHEN** the owner deletes the link with slug `sale` and then posts a new link with slug `sale`
- **THEN** the delete response is 204, the following `GET` of the old id is 404, and the new post is 201

#### Scenario: Counter removed
- **WHEN** the owner deletes a link with `maxClicks` that has been redirected through
- **THEN** the delete response is 204 and the link's counter key no longer exists
