## MODIFIED Requirements

### Requirement: JWT for the API
`POST /api/v1/auth/token` with JSON `{"email","password"}` SHALL return 200 with `{"token": <JWT>, "expiresAt": <RFC 3339 UTC>}` for valid credentials of an unblocked user, and 401 `application/problem+json` otherwise. Tokens expire 3600 seconds after issuance; there is no refresh endpoint. Requests under `/api/v1` other than `/api/v1/auth/*` SHALL require `Authorization: Bearer <credential>`, where the credential is either a JWT or an API key (capability `api-keys`; a key starts with `lb_`, a JWT never does — the prefix decides which authenticator handles the request and neither credential is ever evaluated as the other); a missing, malformed, expired or revoked credential yields 401 `application/problem+json`. Both credentials resolve to the same user entity and the same authorization rules.

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

#### Scenario: API key on the same header
- **WHEN** a user requests `GET /api/v1/me` with `Authorization: Bearer <their API key>` and a stranger requests `GET /api/v1/links/{id}` of that user's link with their own key
- **THEN** the first response is 200 with the user's `email` and the second is 403 `application/problem+json` — the key's user is subject to the same voters as with a JWT
