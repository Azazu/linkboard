## ADDED Requirements

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

### Requirement: The rate-limited operations document their headers
An operation the rate limiter covers SHALL document the headers that limiter sets — the remaining allowance on a successful response, and the retry delay on a refusal — so a client can honour them without reading the implementation.

#### Scenario: The allowance is documented where it is sent
- **WHEN** a client reads an operation the limiter covers
- **THEN** its success responses document the rate-limit headers and its 429 response documents `Retry-After`
