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

### Requirement: Every operation documents the statuses it can answer
The OpenAPI document SHALL declare, for each operation, every response status that operation can return: the success statuses it produces, and the failure statuses its firewall, its authorization, its validation, its rate limiter and its content negotiation can produce. An operation behind a firewall SHALL document 401; an operation the rate limiter covers SHALL document 429; an operation that refuses a request at a resource limit SHALL document 409. A status the operation cannot answer SHALL NOT be documented for it.

#### Scenario: The authenticated operations admit that they can refuse an anonymous caller
- **WHEN** a client reads `GET /api/docs.json`
- **THEN** every operation outside the public authentication and documentation paths declares a 401 response

#### Scenario: The rate-limited operations declare their refusal
- **WHEN** a client reads the document
- **THEN** every operation the limiter covers declares a 429 response, and the operation that creates an API key declares a 409 response for the active-key cap

#### Scenario: What an operation answers is what the document declares for it
- **WHEN** a representative request is made to each documented operation, including an anonymous one, one refused by authorization and one refused by validation
- **THEN** the status of each response is declared for that operation in the document, and its content type is the one the document gives for that status

### Requirement: Errors are documented as problem details
Every response the document declares with a status of 400 or higher SHALL offer exactly one content type, `application/problem+json`, whose schema is the RFC 9457 shape the `api-error-format` capability requires, with an example. No error response SHALL be documented as `application/json`.

#### Scenario: One media type per error response
- **WHEN** a client reads the document
- **THEN** no response with a status of 400 or higher lists `application/json`, and each lists `application/problem+json` with a schema carrying `type`, `title`, `status` and `detail`

#### Scenario: A validation failure is documented with its violations
- **WHEN** a client reads an operation that validates input
- **THEN** its 422 response schema carries `violations`, each element with `propertyPath` and `message`

### Requirement: Operations carry examples
Every operation SHALL show an example of what it accepts and what it returns: each property of a request body and of a response body SHALL carry a representative example value, so the generated document and the interactive page present a filled-in payload rather than an empty skeleton. An example SHALL be a value the operation's own schema accepts.

#### Scenario: A response body is shown with values
- **WHEN** a client reads the link resource's operations
- **THEN** the properties of its schema carry example values — a slug, an absolute target URL, a short URL, a click count and a creation time among them

#### Scenario: A request body is shown with values
- **WHEN** a client reads the operations that accept a body
- **THEN** each input property carries an example, and no example contradicts the constraints documented for its property

### Requirement: The rate-limited operations document the headers their limiter sends
An operation a rate limiter covers SHALL document the headers **that limiter** sets, and no others: the retry delay on every refusal, and the remaining allowance on successful responses only where the limiter reports one. A header the operation never sends SHALL NOT be documented for it.

#### Scenario: The delay is documented wherever a limiter can refuse
- **WHEN** a client reads any operation a limiter covers
- **THEN** its 429 response documents the header carrying the retry delay

#### Scenario: The allowance is documented only where it is sent
- **WHEN** a client reads an operation limited per credential, and one limited per client address
- **THEN** the first documents the remaining-allowance headers on its successful responses and the second does not, because its limiter sends none
