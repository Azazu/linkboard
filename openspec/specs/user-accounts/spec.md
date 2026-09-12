# User Accounts

## Purpose
Accounts are the ownership root of the service: every link, key and report belongs to a user; admins are users with an extra role granted only from the console.

## Requirements

### Requirement: Self-registration with email and password
A guest SHALL be able to create an account by submitting an email and a password through the web form `/register` or `POST /api/v1/auth/register`. The email MUST be unique case-insensitively; the password MUST be at least 12 characters; the stored value is a password hash, never the password. A new account has the role `ROLE_USER` and is not blocked.

#### Scenario: Successful API registration
- **WHEN** a guest posts `{"email":"ann@example.com","password":"correct-horse-battery"}` to `/api/v1/auth/register`
- **THEN** the response status is 201 with the new account's `id`, `email` and `createdAt`, no password material is returned, and the stored password is a hash that verifies against the submitted password

#### Scenario: Duplicate email differing only by case
- **WHEN** `Ann@Example.com` is already registered and a guest registers `ann@example.com`
- **THEN** the response status is 422 with a `violations` entry for `email`

#### Scenario: Password too short
- **WHEN** a guest registers with an 11-character password
- **THEN** the response status is 422 with a `violations` entry for `password` and no account is created

#### Scenario: Successful web registration
- **WHEN** a guest submits the `/register` form with valid data and a valid CSRF token
- **THEN** the account is created and the guest is redirected to `/login`

### Requirement: Roles change only from the console
The system SHALL expose no HTTP operation that changes a user's roles. `app:user:promote <email>` SHALL add `ROLE_ADMIN`; `app:user:demote <email>` SHALL remove it; both fail with a non-zero exit code for an unknown email.

#### Scenario: Promote an existing user
- **WHEN** an operator runs `app:user:promote ann@example.com`
- **THEN** the command exits 0 and the user's roles contain `ROLE_ADMIN` on the next request

#### Scenario: Unknown email
- **WHEN** an operator runs `app:user:promote nobody@example.com`
- **THEN** the command exits non-zero and no user is changed

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
