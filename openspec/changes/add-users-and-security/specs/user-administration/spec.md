## Purpose
Operators need to see who is registered and stop abusers; this is the first authorization boundary of the API and every action leaves an audit line.

## ADDED Requirements

### Requirement: Admin-only user listing
`GET /api/v1/admin/users` SHALL return a paginated collection (`page`, `itemsPerPage` ≤ 100) of users with `id`, `email`, `roles`, `isBlocked`, `createdAt` for callers with `ROLE_ADMIN`, and 403 `application/problem+json` for any other authenticated user.

#### Scenario: Admin lists users
- **WHEN** an admin requests `GET /api/v1/admin/users`
- **THEN** the response status is 200 and the body lists every user with the five fields and no password material

#### Scenario: Regular user is refused
- **WHEN** a user without `ROLE_ADMIN` requests `GET /api/v1/admin/users`
- **THEN** the response status is 403 with `application/problem+json`

### Requirement: Block and unblock
`POST /api/v1/admin/users/{id}/block` SHALL set `isBlocked` to true and `POST /api/v1/admin/users/{id}/unblock` SHALL set it to false, answering 200 with the updated user for admins, 404 for an unknown id, 403 for non-admins. An admin SHALL NOT be able to block their own account (422). Both operations are idempotent.

#### Scenario: Block then the user is refused
- **WHEN** an admin blocks user U and U then sends a request with a previously valid JWT
- **THEN** the block response is 200 with `isBlocked: true`, and U's request is refused with 403 `blocked`

#### Scenario: Unblock restores access
- **WHEN** an admin unblocks a blocked user U and U logs in again
- **THEN** the unblock response is 200 with `isBlocked: false` and U obtains a token

#### Scenario: Admin blocks themselves
- **WHEN** an admin posts to `/api/v1/admin/users/{own id}/block`
- **THEN** the response status is 422 and the account stays unblocked

#### Scenario: Blocking twice
- **WHEN** an admin blocks an already blocked user
- **THEN** the response status is 200 with `isBlocked: true`

### Requirement: Admin actions are audited
Every block and unblock SHALL write one log line at level `info` containing the actor's user id, the target's user id and the action name, and no email addresses.

#### Scenario: Log line on block
- **WHEN** an admin blocks a user
- **THEN** the application log contains one `info` record with `action: user.block`, `actor_id` and `target_id`, and the record contains no `@`
