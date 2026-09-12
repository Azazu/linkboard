## MODIFIED Requirements

### Requirement: Blocked accounts are refused everywhere
An account with `is_blocked = true` SHALL NOT be able to log in, and every authenticated request presenting its credentials (session, JWT or API key) SHALL be refused: under `/api` with 403 `application/problem+json` and `detail` equal to `blocked`; on the web with a redirect to `/login` carrying the error. Blocking takes effect on the next request, without waiting for any token or key to expire.

#### Scenario: Blocked user with a valid JWT
- **WHEN** a user obtained a JWT and is then blocked, and sends a request with that token
- **THEN** the response status is 403 with `application/problem+json` and `detail` `blocked`

#### Scenario: Blocked user with a valid API key
- **WHEN** a user created an API key and is then blocked, and sends a request with that key
- **THEN** the response status is 403 with `application/problem+json` and `detail` `blocked`; unblocking makes the same key work again on the next request

#### Scenario: Blocked user tries to log in on the web
- **WHEN** a blocked user submits valid credentials to `/login`
- **THEN** no session is created and the login page shows that the account is blocked
