# Proposal — add-qr-codes

**Risk-Tier:** high

Tier rationale (the roadmap's `low` is the minimum): AGENTS.md puts every change that adds a dependency or establishes an authorization boundary at `high` — this change adds `endroid/qr-code` (with `bacon/bacon-qr-code` behind it) and opens one new owner-or-admin endpoint that reuses the link voter. Gate 1 on the artifacts and Gate 2 on the code; a demonstrated failing input for every new guard; `design.md` carries the applicability table; a green branch run before Gate 2 (auto review mode). No firewall, voter, migration or secret change.

## Why

A short link is meant to be printed and scanned as much as clicked, and the brief (§2.7, FR-QR-1) asks for a QR code of every link's short URL for its owner. The web UI (roadmap row 11) will offer it as a download button; the API must serve it first. Every prerequisite exists: the link resource, its `shortUrl`, the owner/admin voter and the 404-before-authorization item provider.

## What Changes

1. **`GET /api/v1/links/{id}/qr`** (FR-QR-1): the QR code of the link's `shortUrl` as SVG by default or PNG (512 × 512 px) with `?format=png`, served with the image content type, `Content-Disposition: inline; filename="<slug>.svg|png"` and `Cache-Control: private, max-age=86400` (no server-side cache — rendering takes milliseconds). `format` other than `svg`/`png` answers 422 problem details with one violation on `format`; an `Accept` header that admits neither image type answers 406 problem details.
2. **Same boundary as the link itself**: anonymous callers get 401 from the firewall whatever the id; for an authenticated caller an unknown or malformed id is 404 before the voter runs, then the owner or an admin gets the image and any other user 403. Inactive or expired links still have a QR code (the redirect decides at scan time). There is no public QR endpoint.
3. **New dependency `endroid/qr-code` ^6.1** (PHP ^8.4, `bacon/bacon-qr-code` ^3; PNG through the `gd` extension already in the image). Justification against the anti-overengineering rule: Symfony has no QR support; `bacon/bacon-qr-code` alone is a matrix encoder that needs its renderers wired by hand; `endroid/qr-code` is the brief's stack choice (§5), small, maintained, and gives both writers behind one builder.
4. **Rendering in `src/Link/Qr/`**: a renderer that turns a URL into SVG or PNG bytes at a fixed size, with a snapshot test of the SVG (the brief's §7 exit criterion) and a dimension check of the PNG.
5. **OpenAPI**: the operation is documented with its `format` parameter and both image content types — the first non-JSON response of the API, declared as an exception of the JSON-only rule.
6. **Docs**: QR lines in the how-to's API section (curl for SVG and PNG), FR-QR-1 refined where this proposal fixes what the brief left open (422/406 behaviour, `Content-Disposition`, inactive links).

## Capabilities

### New Capabilities

- `qr-codes`: the QR code of a link's short URL — formats and dimensions, response headers, the `format` parameter and its errors, the authorization boundary, behaviour for inactive, expired and deleted links.

### Modified Capabilities

- `api-docs`: "JSON-only content negotiation" — the QR operation is the declared exception: it produces `image/svg+xml` and `image/png` (and problem details for errors), and the OpenAPI document lists both content types.

## Non-goals

- The web UI's download button and QR preview on `/links/{id}` (`add-web-ui`, row 11) — this change provides the endpoint it will link to.
- A public QR endpoint (`/{slug}.qr`, `/{slug}+`): stated non-goal of the brief; QR codes are for the owner.
- Logos, colours, custom sizes or margins, PDF/EPS output, QR codes for arbitrary URLs.
- Server-side caching of rendered images and ETag/conditional requests: `Cache-Control` covers the client, rendering costs milliseconds.
- Per-endpoint rate limits (`add-api-keys-and-rate-limiting`, row 10).
- Decoding the rendered code in tests (would add a decoder dependency for the test suite alone): the SVG snapshot and the PNG dimensions are the exit criterion.

## Impact

- New: `src/Link/Qr/` (format enum, renderer, the operation's state processor), the `qr` operation on `LinkResource`, `tests/Unit/Link/Qr/` (renderer, snapshot fixture under `tests/Fixture/qr/`), `tests/Api/Link/LinkQrTest.php`.
- Modified: `composer.json` / `composer.lock` (`endroid/qr-code` ^6.1 and its two transitive packages), `src/Link/Api/LinkResource.php` (one operation), `docs/how-to/local-development.md`, `docs/explanation/requirements.md` (FR-QR-1), `openspec/ROADMAP.md` (row 9 removed at archive time).
- Unchanged: schema (no migration), firewall and `access_control`, `LinkVoter`, the redirect, analytics and click paths.
