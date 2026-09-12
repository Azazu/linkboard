# API Documentation

## Purpose
Generated, always-current API documentation and a fixed versioned base path, so integrators and reviewers can discover the API without reading code.

## Requirements

### Requirement: Versioned base path
All API operations SHALL be served under the base path `/api/v1`. The base path itself SHALL answer with the API documentation: the OpenAPI document for `Accept: application/json`, the interactive documentation page for `Accept: text/html`. No operation is served directly under `/api/` except the documentation endpoints.

#### Scenario: Base path serves the OpenAPI document to API clients
- **WHEN** a client requests `GET /api/v1` with `Accept: application/json`
- **THEN** the response status is 200 and the body is the same OpenAPI document as `GET /api/docs.json`

#### Scenario: Every documented path is versioned
- **WHEN** a client requests `GET /api/docs.json`
- **THEN** every key of `paths` starts with `/api/v1/` (vacuously true while no resource exists; asserted from the first resource on)

### Requirement: OpenAPI document and Swagger UI
The system SHALL serve an OpenAPI 3.1 document at `GET /api/docs.json` (`application/json`) and an interactive Swagger UI at `GET /api/docs` (`text/html`), both generated from the registered resources and reflecting the `/api/v1` base path.

#### Scenario: OpenAPI document is valid JSON with the right version
- **WHEN** a client requests `GET /api/docs.json`
- **THEN** the response status is 200 and the body is a JSON object with `openapi` starting with `3.1` and an `info.title` equal to `Linkboard API`

#### Scenario: Swagger UI is served
- **WHEN** a browser requests `GET /api/docs`
- **THEN** the response status is 200 with content type `text/html` and the page loads the OpenAPI document from `/api/docs.json`

### Requirement: JSON-only content negotiation
The API SHALL accept and produce `application/json` (plus `application/problem+json` for errors) and SHALL NOT expose JSON-LD, Hydra, HAL or GraphQL endpoints. The one declared exception is the QR code operation `GET /api/v1/links/{id}/qr` (capability `qr-codes`), which produces `image/svg+xml` or `image/png` and, for its errors, `application/problem+json` like every other operation; the OpenAPI document SHALL list both image content types and the `format` parameter of that operation.

#### Scenario: JSON-LD is not available
- **WHEN** a client requests `GET /api/v1` with `Accept: application/ld+json`
- **THEN** the response status is 406 with an `application/problem+json` body

#### Scenario: GraphQL endpoint is absent
- **WHEN** a client requests `GET /api/graphql`
- **THEN** the response status is 404

#### Scenario: The QR operation is documented with its image types
- **WHEN** a client requests `GET /api/docs.json`
- **THEN** `paths` contains `/api/v1/links/{id}/qr` with a `get` operation whose 200 response lists `image/svg+xml` and `image/png` content and whose parameters include `format` in `query`
