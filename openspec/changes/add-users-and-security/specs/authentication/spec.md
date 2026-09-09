## Purpose
Two ways in, one identity: a session for the browser, a bearer JWT for API clients, both resolving to the same account and the same authorization rules.

## ADDED Requirements

### Requirement: Session login for the web
The web SHALL authenticate with a CSRF-protected form at `/login` (email + password) and log out at `/logout`; sessions carry no remember-me. Unauthenticated requests to protected web pages SHALL redirect to `/login`.

#### Scenario: Valid credentials
- **WHEN** a registered, unblocked user submits valid credentials with a valid CSRF token to `/login`
- **THEN** a session is created and the user is redirected to the post-login page

#### Scenario: Invalid credentials
- **WHEN** a user submits a wrong password
- **THEN** no session is created and the login page shows an authentication error without revealing whether the email exists

#### Scenario: Missing CSRF token
- **WHEN** valid credentials are posted to `/login` without a CSRF token
- **THEN** no session is created and the response is a login failure

### Requirement: JWT for the API
`POST /api/v1/auth/token` with JSON `{"email","password"}` SHALL return 200 with `{"token": <JWT>, "expiresAt": <RFC 3339 UTC>}` for valid credentials of an unblocked user, and 401 `application/problem+json` otherwise. Tokens expire 3600 seconds after issuance; there is no refresh endpoint. Requests under `/api/v1` other than `/api/v1/auth/*` SHALL require `Authorization: Bearer <JWT>`; a missing, malformed or expired token yields 401 `application/problem+json`.

#### Scenario: Token issued and used
- **WHEN** a user posts valid credentials to `/api/v1/auth/token` and then requests `GET /api/v1/me` with the returned bearer token
- **THEN** the token response is 200 with `token` and an `expiresAt` about one hour ahead, and `/api/v1/me` answers 200 with the user's `id`, `email`, `roles` and `createdAt`

#### Scenario: Wrong password
- **WHEN** a client posts a wrong password to `/api/v1/auth/token`
- **THEN** the response status is 401 with `application/problem+json`

#### Scenario: Missing token on a protected operation
- **WHEN** a client requests `GET /api/v1/me` without an `Authorization` header
- **THEN** the response status is 401 with `application/problem+json`

#### Scenario: Expired token
- **WHEN** a client presents a JWT whose expiry is in the past
- **THEN** the response status is 401 with `application/problem+json`

### Requirement: Auth endpoints are rate limited per client IP
`/login`, `/register`, and every path under `/api/v1/auth/` SHALL allow at most 10 requests per minute per client IP (sliding window; the limit is configurable through `RATE_LIMIT_AUTH_PER_IP`). Over the limit the response is 429 with a `Retry-After` header: `application/problem+json` under `/api`, HTML elsewhere. The client IP is the one Symfony derives through the trusted-proxy configuration.

#### Scenario: Eleventh attempt within a minute
- **WHEN** a client IP sends 10 requests to `/api/v1/auth/token` within a minute and then an eleventh
- **THEN** the eleventh response status is 429 with `application/problem+json` and a `Retry-After` header

#### Scenario: Spoofed forwarded header from an untrusted peer
- **WHEN** a client not listed in the trusted proxies sends `X-Forwarded-For: 203.0.113.9` with its requests
- **THEN** the limit is counted against the peer's real address, not against `203.0.113.9`

### Requirement: Public and protected surfaces
`/api/v1/auth/*`, `/api/docs`, `/api/docs.json`, `/api/v1` (documentation), `/health`, `/login` and `/register` SHALL be reachable without authentication; every other path under `/api/v1` SHALL require an authenticated user.

#### Scenario: Documentation stays public
- **WHEN** a guest requests `GET /api/docs.json`
- **THEN** the response status is 200
