## MODIFIED Requirements

### Requirement: Update a link
`PATCH /api/v1/links/{id}` with `application/merge-patch+json` SHALL update only the fields present in the body, applying the same validation as creation, and return 200 with the updated link and a new `updatedAt`. Null contract: `expiresAt`, `maxClicks`, `utm` and `rules` present with `null` are cleared; `rules` present with a document replaces the whole stored document (no deep merge); `targetUrl` and `isActive` MUST NOT be `null` when present (422); absent fields are unchanged; a body that is not a JSON object is 400. Deactivated links keep their data. A successful update (including deactivation and reactivation) SHALL invalidate the link's cached reports (capability `analytics`); a failure of the cache store MUST NOT fail the update and is logged at `warning` with the link id.

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

#### Scenario: Cached reports are invalidated by a patch
- **WHEN** the owner reads the link's summary report, a click is recorded, and the owner patches `{"isActive":false}` and reads the summary again
- **THEN** the second summary counts the new click; a rejected patch (422) leaves the cached report in place

#### Scenario: Cache store down during a patch
- **WHEN** the cache store refuses connections and the owner patches `{"isActive":false}`
- **THEN** the response status is 200, `isActive` is false and a `warning` record names the link id

### Requirement: Delete a link
`DELETE /api/v1/links/{id}` SHALL remove the link permanently and answer 204; a subsequent `GET` returns 404 and the slug can be used by a new link at once. Rows in dependent tables reference `links(id)` with `ON DELETE CASCADE`; the link's Redis click counter is removed (best effort) and click messages still queued for it are discarded by the handler (capability `click-logging`). Deletion SHALL invalidate the link's cached reports and the global statistics (capability `analytics`) — best effort like the counter: a cache failure is logged at `warning` with the link id and does not fail the deletion — and every report of the deleted link answers 404 afterwards.

#### Scenario: Delete then reuse the slug
- **WHEN** the owner deletes the link with slug `sale` and then posts a new link with slug `sale`
- **THEN** the delete response is 204, the following `GET` of the old id is 404, and the new post is 201

#### Scenario: Counter removed
- **WHEN** the owner deletes a link with `maxClicks` that has been redirected through
- **THEN** the delete response is 204 and the link's counter key no longer exists

#### Scenario: Reports gone with the link
- **WHEN** the owner reads a link's summary report, deletes the link and requests the summary again
- **THEN** the delete response is 204 and the second summary request is 404; an admin's `top-links` report requested after the deletion no longer names the link
