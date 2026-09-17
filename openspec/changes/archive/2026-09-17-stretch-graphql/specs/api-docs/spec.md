# Delta — api-docs

## MODIFIED Requirements

### Requirement: JSON-only content negotiation
The REST API SHALL accept and produce `application/json` (plus `application/problem+json` for errors) and SHALL NOT expose JSON-LD, Hydra or HAL representations. Two declared exceptions: the QR code operation `GET /api/v1/links/{id}/qr` (capability `qr-codes`), which produces `image/svg+xml` or `image/png` and, for its errors, `application/problem+json` like every other operation — the OpenAPI document SHALL list both image content types and the `format` parameter of that operation; and the GraphQL endpoint (capability `graphql-api`), which answers at `/api/v1/graphql` and at the framework's unversioned `/api/graphql`, in GraphQL's own response shape for everything its executor decides.

#### Scenario: JSON-LD is not available
- **WHEN** a client requests `GET /api/v1` with `Accept: application/ld+json`
- **THEN** the response status is 406 with an `application/problem+json` body

#### Scenario: GraphQL endpoint is absent
- **WHEN** a client requests `GET /api/graphql`
- **THEN** the response is not this API's REST documentation and not a resource representation — the GraphQL entrypoint the framework registers lives there, as `/api/docs` does beside `/api/v1`, and the documented GraphQL path is the versioned `/api/v1/graphql`, which takes `POST` only

#### Scenario: The QR operation is documented with its image types
- **WHEN** a client requests `GET /api/docs.json`
- **THEN** `paths` contains `/api/v1/links/{id}/qr` with a `get` operation whose 200 response lists `image/svg+xml` and `image/png` content and whose parameters include `format` in `query`

#### Scenario: The REST document describes the REST API only
- **WHEN** a client requests `GET /api/docs.json`
- **THEN** the document describes every REST operation as before and names no GraphQL path, because the two protocols publish their shapes separately
