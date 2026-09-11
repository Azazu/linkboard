## Purpose

The QR code of a link's short URL, served to the link's owner or an administrator as an SVG or PNG image so a short link can be printed and scanned; the API side of what the web UI will offer as a download.

## ADDED Requirements

### Requirement: QR code of the short URL
`GET /api/v1/links/{id}/qr` SHALL return a QR code that encodes the link's `shortUrl` exactly (the public base URL and the slug, nothing else). Without a `format` parameter, or with `format=svg`, the response SHALL be an SVG document with content type `image/svg+xml`; with `format=png` it SHALL be a PNG image of 512 × 512 pixels with content type `image/png`. Every image response SHALL carry `Content-Disposition: inline; filename="<slug>.<svg|png>"` and `Cache-Control: private, max-age=86400`, and SHALL set no cookie. Rendering MUST be deterministic: the same short URL and format produce byte-identical images.

#### Scenario: SVG by default
- **WHEN** the owner requests `GET /api/v1/links/{id}/qr` for a link with slug `spring-sale`
- **THEN** the response status is 200 with `Content-Type` `image/svg+xml`, `Content-Disposition` `inline; filename="spring-sale.svg"`, `Cache-Control` `private, max-age=86400`, and the body is an SVG document whose root element declares a 512-pixel width and height

#### Scenario: PNG on request
- **WHEN** the owner requests `.../qr?format=png`
- **THEN** the response status is 200 with `Content-Type` `image/png`, `Content-Disposition` `inline; filename="spring-sale.png"`, and the body is a PNG image 512 pixels wide and 512 pixels high

#### Scenario: Deterministic image
- **WHEN** the owner requests the SVG twice, and requests the SVG of a second link with a different slug
- **THEN** the first two bodies are byte-identical and the third differs

### Requirement: Format parameter
The `format` query parameter SHALL accept exactly `svg` and `png`. Any other value, including an empty one, SHALL answer 422 `application/problem+json` with a `violations` array holding one element whose `propertyPath` is `format`. An `Accept` header that admits neither `image/svg+xml` nor `image/png` SHALL answer 406 `application/problem+json`; a wildcard or absent `Accept` is served.

#### Scenario: Unknown format
- **WHEN** the owner requests `.../qr?format=gif` and `.../qr?format=`
- **THEN** each response status is 422 `application/problem+json` with exactly one violation whose `propertyPath` is `format`

#### Scenario: Client insists on JSON
- **WHEN** the owner requests `.../qr` with `Accept: application/json`
- **THEN** the response status is 406 `application/problem+json`

### Requirement: Authorization boundary of QR codes
The QR code SHALL be served to the link's owner and to users with `ROLE_ADMIN`; any other authenticated user receives 403 `application/problem+json`, an anonymous caller 401, and an unknown or malformed `id` 404 (before any authorization check, so an unknown id looks the same to everyone). Inactive and expired links keep their QR code (the redirect decides what a scan does); a deleted link's QR code answers 404. There is no unauthenticated QR endpoint.

#### Scenario: Owner, admin, stranger, anonymous
- **WHEN** user A owns a link and A, an admin, user B and an anonymous client each request its QR code
- **THEN** A's and the admin's responses are 200 `image/svg+xml`, B's is 403 `application/problem+json` and the anonymous one is 401

#### Scenario: Unknown link
- **WHEN** an authenticated user requests `GET /api/v1/links/not-a-uuid/qr` and `GET /api/v1/links/{random uuid}/qr`
- **THEN** each response status is 404 `application/problem+json`

#### Scenario: Inactive and expired links keep their code
- **WHEN** the owner deactivates a link, and owns another link whose `expiresAt` has passed, and requests the QR code of each
- **THEN** both responses are 200 `image/svg+xml`

#### Scenario: Deleted link
- **WHEN** the owner deletes a link and requests its QR code
- **THEN** the response status is 404 `application/problem+json`
