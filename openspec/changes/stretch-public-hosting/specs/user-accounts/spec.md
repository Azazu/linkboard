# Delta — user-accounts

## MODIFIED Requirements

### Requirement: Self-registration with email and password
A guest SHALL be able to create an account by submitting an email and a password through the web form `/register` or `POST /api/v1/auth/register`. The email MUST be unique case-insensitively; the password MUST be at least 12 characters; the stored value is a password hash, never the password. A new account has the role `ROLE_USER` and is not blocked.

Self-registration SHALL be governed by one setting, which is **on by default**, so that an instance that says nothing behaves exactly as described above. When it is off, **both** entry points SHALL answer 404 — the web form and the API operation alike — no account SHALL be created by either, and the web UI SHALL stop offering the form; the setting is the only thing that governs this, so closing registration cannot be defeated by reaching the surface the other one is not. An account that already exists SHALL still be able to sign in, and every other account operation SHALL be unaffected.

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

#### Scenario: Closed registration refuses both entry points
- **WHEN** registration is off and a guest requests `GET /register`, posts the `/register` form with valid data and a valid CSRF token, and posts valid data to `/api/v1/auth/register`
- **THEN** every one of them answers 404 — the API one as problem details — and no account was created by any of them

#### Scenario: Closed registration is not advertised
- **WHEN** registration is off and a guest opens the sign-in page
- **THEN** it carries no link to the registration form

#### Scenario: Closed registration does not touch anyone who already has an account
- **WHEN** registration is off and an existing account signs in through the web and obtains a token through the API
- **THEN** both succeed exactly as they do with registration open
