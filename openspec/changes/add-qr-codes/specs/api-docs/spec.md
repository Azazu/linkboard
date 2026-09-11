## MODIFIED Requirements

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
