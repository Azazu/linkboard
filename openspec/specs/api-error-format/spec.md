# API Error Format

## Purpose
One machine-readable error format for every failure under `/api`, so API clients never have to parse HTML or framework-specific payloads.

## Requirements

### Requirement: Problem details for every API error
Every response under `/api` with a status of 400 or higher SHALL have content type `application/problem+json` and a body conforming to RFC 9457 with at least `type`, `title`, `status` and `detail`. This includes routing failures (404, 405), content negotiation failures (406), and unexpected server errors (500). The shape of validation errors (422 with `violations`) is specified together with the first resource that validates input.

#### Scenario: Unknown API route
- **WHEN** a client requests `GET /api/v1/does-not-exist`
- **THEN** the response status is 404, the content type is `application/problem+json`, and the body has `status: 404` and a non-empty `title`

#### Scenario: Wrong method
- **WHEN** a client sends `DELETE /api/v1`
- **THEN** the response status is 405 with an `application/problem+json` body

### Requirement: No internal details outside dev
Outside the `dev` environment an error body SHALL NOT contain stack traces, file paths, class names or exception messages of unexpected errors; a 500 SHALL carry a generic `detail`.

#### Scenario: Unexpected exception in test environment
- **WHEN** an operation throws an unexpected exception in the `test` environment
- **THEN** the response status is 500, the body is `application/problem+json`, and it contains neither `trace` nor a file path nor the exception message
