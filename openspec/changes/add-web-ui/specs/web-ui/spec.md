## Purpose
The server-rendered pages a person uses in a browser: what each page shows, who may see it, how its forms behave, and the headers, content security policy and cookie flags that every web response carries. The API (`links`, `api-keys`, `analytics`) stays the authority for the rules themselves; this capability says how the pages enforce and present them.

## ADDED Requirements

### Requirement: The owner-facing pages
The system SHALL serve, to a signed-in user, `/dashboard` (their own totals, a clicks-per-day chart over their links, and their ten most recent links), `/links` (their links, paginated, filterable by active state and by a slug fragment, orderable by creation time or click count), `/links/new`, `/links/{id}` (the link's details, its short URL with a copy control, and its QR code), `/links/{id}/edit`, and `/api-keys` (their keys and the controls to create and revoke one). Every one of these pages SHALL require an authenticated session: a guest SHALL be sent to `/login` and no page content SHALL be disclosed. `/login` and `/register` remain public.

#### Scenario: A signed-in owner reaches every page
- **WHEN** a signed-in user requests `/dashboard`, `/links`, `/links/new`, `/links/{id}` and `/links/{id}/edit` for a link they own, and `/api-keys`
- **THEN** each response is 200 and carries that user's own data

#### Scenario: A guest is sent to the login page
- **WHEN** a client with no session requests any of those paths
- **THEN** the response redirects to `/login` and its body contains none of the page's content

#### Scenario: A short link whose slug begins with a page's name is still a short link
- **WHEN** a guest requests `/dashboard-sale`, `/links-promo` and `/api-keys-promo`, which are valid slugs of existing links
- **THEN** each response is the redirect the `redirect` capability defines, no response sends the client to `/login`, and none starts a session

#### Scenario: The dashboard shows the signed-in user's own figures
- **WHEN** two users each own links with clicks, and one of them opens `/dashboard`
- **THEN** the totals and the chart series count only that user's links, and the recent-links list holds only their links

### Requirement: A link page belongs to its owner
The system SHALL refuse every link page for a link the signed-in user neither owns nor administers. The pages SHALL render that refusal as 404, identical to the response for an identifier no link has, so a signed-in stranger who guesses or is handed an identifier learns nothing from the page. This is the pages' rendering of the same authorization decision the API makes; the API's own answer for that caller stays what the `links` and `qr-codes` capabilities require (403 for a stranger, 404 for an unknown identifier) and is not changed by this capability. An administrator SHALL reach another user's link pages, as they reach the link through the API.

#### Scenario: A stranger cannot tell an existing link from an unknown one
- **WHEN** a signed-in user requests `/links/{id}`, `/links/{id}/edit` and the delete action for a link owned by somebody else, and then the same paths with an identifier no link has
- **THEN** every response is 404 and the bodies are indistinguishable

#### Scenario: The API's own answer is unchanged
- **WHEN** that same user requests `GET /api/v1/links/{id}` for the link owned by somebody else
- **THEN** the response is the 403 problem document the `links` capability requires — the pages' 404 is a rendering decision, not a change to the API

#### Scenario: An administrator reaches another user's link
- **WHEN** an administrator requests `/links/{id}` for another user's link
- **THEN** the response is 200 and shows that link

### Requirement: Pages work without JavaScript
The system SHALL render every page's content and accept every form submission without JavaScript: navigation is plain links, submissions are plain form posts, and the only features that require scripting are the dashboard chart, the copy-to-clipboard control and the rules editor's convenience behaviour, each of which SHALL degrade to readable content or a plain text field. Every page SHALL declare a viewport for small screens and SHALL NOT impose a fixed pixel width on its layout, so it stays usable at 375 pixels.

#### Scenario: The links list and the forms work with scripting disabled
- **WHEN** a client that runs no JavaScript signs in, lists links, creates one through `/links/new` and edits it
- **THEN** each step completes and the resulting pages show the data

#### Scenario: The chart degrades to its numbers
- **WHEN** the dashboard is rendered and its chart cannot run
- **THEN** the page still shows the totals and the recent links

### Requirement: Forms are CSRF-protected and report the same violations as the API
The system SHALL protect every state-changing web form with a CSRF token and SHALL refuse a submission without a valid one. When a submission violates a rule, the page SHALL be re-rendered with HTTP 422, the submitted values kept, and a message for each violated field taken from the same constraints the API reports — a link's target URL, slug, UTM members, expiry, click limit and routing rules included.

#### Scenario: A submission without a valid token changes nothing
- **WHEN** a link-creating POST arrives without a valid CSRF token
- **THEN** no link is created and the response is not a successful creation

#### Scenario: An invalid target URL is reported on its field
- **WHEN** a signed-in user submits `/links/new` with a target the URL policy refuses
- **THEN** the response is 422, no link is created, and the page shows the violation against the target field

#### Scenario: A slug that is taken is reported on its field
- **WHEN** a signed-in user submits `/links/new` with a custom slug another link already uses
- **THEN** the response is 422 and the page shows that the slug is taken

### Requirement: Links are created, changed and deleted from the UI under the API's rules
The system SHALL let an owner create a link with a generated or a custom slug, change its target, expiry, click limit, UTM members, routing rules and active state, and delete it — enforcing exactly the rules the `links` capability states, including the immutable slug, the target-URL policy, the routing-rules limits, and the audit line an administrator's action on another user's link produces. A deletion SHALL ask for confirmation before it happens and SHALL be refused without a valid CSRF token. After a change, the pages and the link's cached reports SHALL show the new state.

#### Scenario: A link created from the UI is the link the API returns
- **WHEN** an owner creates a link through `/links/new` with a target, UTM members, an expiry and a click limit
- **THEN** the link exists with those values, its slug obeys the slug rules, and the API returns the same link for its owner

#### Scenario: Editing changes only what was submitted
- **WHEN** an owner submits the edit form with a new target and an emptied expiry
- **THEN** the target changes, the expiry is cleared, and the slug, click count and creation time are untouched

#### Scenario: Deleting asks first
- **WHEN** an owner opens a link's page and deletes the link
- **THEN** the deletion happens only on a confirmed, CSRF-protected submission, after which the link is gone from the list and its page is 404

### Requirement: The routing-rules editor accepts a document and shows its violations
The system SHALL let an owner edit a link's routing rules both as a structured editor and as the raw JSON document, SHALL validate the document against exactly the limits the `routing-rules` capability states, and SHALL show each violation next to the part of the document it concerns, identifying that part by its path. An invalid document SHALL leave the stored rules untouched, and the refused submission SHALL come back in the view it was made in, still carrying what was entered, so a correction is made on that page rather than typed again.

#### Scenario: An invalid rules document is refused with its paths
- **WHEN** an owner submits a rules document whose first rule has an unknown match key and whose variants' weights do not sum to 100
- **THEN** the response is 422, the stored rules are unchanged, and the page shows a message for each violation naming the path inside the document

#### Scenario: A document that is not JSON is refused in the view it was typed in
- **WHEN** an owner chooses the JSON view and submits text that is not valid JSON
- **THEN** the response is 422 in that same view, showing the parse error and the text that was entered, the stored rules are unchanged, and correcting the text on that page saves it

#### Scenario: A valid document replaces the stored rules
- **WHEN** an owner submits a valid rules document
- **THEN** the stored rules are that document in its canonical form and the link's page shows them

### Requirement: The API-keys page shows a new key once
The system SHALL send a newly created API key's plaintext in exactly one response — the page that follows its creation — and SHALL be unable to send it again, because only its hash is stored; later renderings of the page SHALL show the prefix and the key's metadata, as the API does. That page SHALL instruct the reader to copy the value now, SHALL be marked so that the navigation layer keeps no copy of it and HTTP caches do not store it, and SHALL clear the value from a document the browser restores from its own history whenever scripting is available. A browser that restores an already-rendered document without scripting, a screenshot and the reader's clipboard are outside this capability's control, and no part of it claims otherwise. The page SHALL list the user's own keys, revoked and expired ones included, and SHALL revoke a key on a confirmed, CSRF-protected submission. It SHALL show another user's key neither in the list nor through a revocation.

#### Scenario: The plaintext is shown once
- **WHEN** a signed-in user creates an API key from `/api-keys` and then opens `/api-keys` again
- **THEN** the first response shows the plaintext once and the second shows only the prefix and the metadata

#### Scenario: The page that showed a key is marked against being kept
- **WHEN** the page that showed a new key is rendered
- **THEN** the response carries `Cache-Control: no-store`, the page tells the navigation layer not to cache it, the element holding the value is marked as temporary so a navigation snapshot drops it, and the page carries the behaviour that empties that element when the browser restores the document from history

#### Scenario: A back navigation in a real browser shows no plaintext
- **WHEN** a browser that runs scripting creates a key, navigates away — both within the application and as a full document load — and returns through its history
- **THEN** neither return shows the plaintext; and with the protections that govern each of those two returns removed, the plaintext reappears, which is how the protections are known to be the reason

#### Scenario: Another user's key cannot be revoked
- **WHEN** a signed-in user submits a revocation for a key that belongs to somebody else
- **THEN** the response is 404, and that key still authenticates

### Requirement: Every web response carries the hardening headers
The system SHALL send, on every response the web pages produce — the API-keys page included — `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, a `Referrer-Policy`, and a `Content-Security-Policy` that admits scripts, styles, images, fonts and connections only from the application's own origin — no external origin, no `unsafe-inline` for scripts — and that forbids framing and the injection of a base URI. Any inline script the asset pipeline requires SHALL be admitted by a nonce that is unpredictable and different on every response. Session cookies SHALL be `HttpOnly` and `SameSite=Lax`, and SHALL be `Secure` outside development.

#### Scenario: A rendered page carries the headers and a fresh nonce
- **WHEN** a signed-in user requests `/dashboard` twice, and then `/api-keys` and the page that shows a newly created key
- **THEN** each response carries the four headers, the policy names only the application's own origin, and the nonce in the second dashboard response differs from the first and matches the one on that response's inline script

#### Scenario: Only the API documentation is outside the policy
- **WHEN** the documentation page at `/api/docs` is requested
- **THEN** it carries `X-Content-Type-Options`, `X-Frame-Options` and a `Referrer-Policy` but no content security policy, because its Swagger UI bootstraps with an inline script this capability does not control — and no other HTML page of the application is exempt

#### Scenario: The policy admits no external origin
- **WHEN** any web page is rendered
- **THEN** its policy lists no host other than the application's own origin, and its markup references no script, style, font or image from another origin

### Requirement: The UI links to the API documentation
The system SHALL link the Swagger UI at `/api/docs` from every page's footer.

#### Scenario: The documentation is one click away
- **WHEN** any page of the UI is rendered
- **THEN** its footer contains a link to `/api/docs`
