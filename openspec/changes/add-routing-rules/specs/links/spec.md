## MODIFIED Requirements

### Requirement: Create a link with a generated or custom slug
An authenticated user SHALL create a link from a required `targetUrl` and optional `slug`, `expiresAt`, `maxClicks`, `utm` and `rules` (the routing-rules document of the `routing-rules` capability; violations are 422 with paths under `rules`). Without `slug` the system generates a 7-character slug from `[A-Za-z0-9]` using a cryptographically secure source; a collision with an existing slug at insert time is retried with a new candidate a bounded number of times (5), and the request answers 500 (logged) only when every candidate collided. The response is 201 with `id`, `slug`, `shortUrl`, `targetUrl`, `utm`, `rules`, `expiresAt`, `maxClicks`, `isActive: true`, `clickCount: 0`, `createdAt`, `updatedAt`; `rules` is the stored document or null.

#### Scenario: Generated slug
- **WHEN** a user posts `{"targetUrl":"https://example.com/landing"}` to `/api/v1/links`
- **THEN** the response status is 201, `slug` matches `^[A-Za-z0-9]{7}$`, `shortUrl` is the public base URL followed by `/` and the slug, `clickCount` is 0 and `rules` is null

#### Scenario: Custom slug
- **WHEN** a user posts `{"targetUrl":"https://example.com","slug":"spring-sale_2026"}`
- **THEN** the response status is 201 and `slug` is exactly `spring-sale_2026`

#### Scenario: Generated slug collides at insert time
- **WHEN** the first generated candidate is taken by a concurrent insert after the pre-check and before the write
- **THEN** the request still answers 201 with the next free candidate; when five candidates in a row collide the response is 500 and an error is logged

#### Scenario: Link with rules
- **WHEN** a user posts a link with a valid `rules` document
- **THEN** the response status is 201 and `GET /api/v1/links/{id}` returns the same document under `rules`

### Requirement: Update a link
`PATCH /api/v1/links/{id}` with `application/merge-patch+json` SHALL update only the fields present in the body, applying the same validation as creation, and return 200 with the updated link and a new `updatedAt`. Null contract: `expiresAt`, `maxClicks`, `utm` and `rules` present with `null` are cleared; `rules` present with a document replaces the whole stored document (no deep merge); `targetUrl` and `isActive` MUST NOT be `null` when present (422); absent fields are unchanged; a body that is not a JSON object is 400. Deactivated links keep their data.

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

#### Scenario: Rules are replaced whole, cleared with null, kept when absent
- **WHEN** a link has a two-rule document and the owner patches `{"rules":{"version":1,"variants":[{"name":"A","weight":50,"target":"https://example.com/a"},{"name":"B","weight":50,"target":"https://example.com/b"}]}}`, then `{"isActive":true}`, then `{"rules":null}`
- **THEN** after the first patch `rules` is exactly the variants-only document (the two rules are gone), after the second it is unchanged, and after the third it is null; an invalid document in a patch is 422 with paths under `rules` and leaves the stored document unchanged
