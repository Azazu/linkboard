## ADDED Requirements

### Requirement: Validation errors carry violations
A request rejected by validation SHALL return 422 with an `application/problem+json` body that includes a `violations` array; each element has `propertyPath` and `message`, and `code` when the constraint provides one. The `detail` member summarizes the violations.

#### Scenario: Invalid registration payload
- **WHEN** a client posts `{"email":"not-an-email","password":"short"}` to `/api/v1/auth/register`
- **THEN** the response status is 422, the content type is `application/problem+json`, and `violations` contains one element with `propertyPath` `email` and one with `propertyPath` `password`, each with a non-empty `message`
