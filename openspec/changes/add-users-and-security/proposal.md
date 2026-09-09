# Proposal — add-users-and-security

**Risk-Tier:** high

Tier rationale: authentication, authorization and security-sensitive input (`AGENTS.md` triggers: security firewall, API keys later, voters). Gate 1 + Gate 2, a demonstrated failing input for every new guard, a green branch run before Gate 2.

## Why

Everything after this change is owned by somebody: links belong to users, analytics is per owner, admins moderate. The specification (§1, §2.1, §3.1, decisions D3 and D5) fixes the model — self-registration, session login for the web, JWT for the API, admins promoted from the console, blocking — and nothing in the repository implements it yet. Without it `add-link-crud` cannot express ownership.

## What Changes

1. **`users` table and `User` entity** (`src/Auth/`): UUID v7 id, email unique case-insensitively (index on `lower(email)`), password hash, roles JSON, `is_blocked`, timestamps — §3.1. Reviewed, reversible migration.
2. **Registration** — web form `/register` and API `POST /api/v1/auth/register` (API Platform resource with a DTO input, state processor): email + password ≥ 12 characters, Symfony `auto` hasher, 422 with `violations` on invalid input (this is the first validated input: the 422 shape of spec `api-error-format` is asserted here). No email verification (non-goal).
3. **Two firewalls** (`config/packages/security.yaml`): `api` — stateless, `^/api`, JWT bearer authentication via `lexik/jwt-authentication-bundle` 3.2, `POST /api/v1/auth/token` issues a token (TTL 3600 s, no refresh), `/api/v1/auth/*` and the docs are public; `main` — session form login `/login`, `/logout`, CSRF, no remember-me. Both resolve to the same `User`.
4. **`GET /api/v1/me`** — the current user (id, email, roles, createdAt).
5. **Admin operations**: `GET /api/v1/admin/users` (paginated; email, roles, blocked, created; link count arrives with links), `POST /api/v1/admin/users/{id}/block` and `/unblock`, guarded by `ROLE_ADMIN`; each action logged at `info` with actor and target ids (FR-ADM-2). Web pages for admin come with `add-web-ui`.
6. **Blocking semantics**: a `UserChecker` refuses blocked users on every authenticated request; API answers 403 problem details with `detail: blocked`, the web login shows an error; existing JWTs stop working immediately (checked per request).
7. **Console commands** `app:user:promote <email>` / `app:user:demote <email>` — the only way to change roles.
8. **Auth rate limit**: 10 requests per minute per client IP on `/login`, `/register`, `/api/v1/auth/*` (sliding window, Symfony RateLimiter on the Redis cache pool, `RATE_LIMIT_AUTH_PER_IP` env), 429 with `Retry-After` — problem details under `/api`, HTML elsewhere. Client IP from the trusted-proxy mechanism (`TRUSTED_PROXIES`).
9. **Minimal Twig pages** for `/login` and `/register` (unstyled, functional) so the web firewall is exercisable and tested; the real UI is `add-web-ui`.
10. **Keys and secrets wiring, isolated per environment**: key files live at `config/jwt/%kernel.environment%/{private,public}.pem` (gitignored), so dev and test never share an encrypted key; `JWT_PASSPHRASE` comes from `.env.dev` and `.env.test` (dev/test-only values, committed the way Symfony commits `APP_SECRET` in `.env.dev`) and from the real environment in prod. `make jwt-keys` runs `lexik:jwt:generate-keypair --skip-if-exists` twice, `--env=dev` and `--env=test`; `make init` calls it; CI runs `make jwt-keys EXEC=` (test env only matters there) before `make test-db EXEC=`. `lexik:jwt:check-config --env=dev` and `--env=test` are the verification that each environment can load its own key with its own passphrase.
11. **Deep health probe in prod — deferred explicitly.** The health specification tied the production 404 to "until an authorization boundary exists". This change introduces that boundary but keeps the deep probe unconditionally refused in `prod`: the authorized path will be an **admin API key** in `add-api-keys-and-rate-limiting` (monitoring systems hold keys, not sessions or JWTs). The health requirement is amended accordingly (delta spec `health-check`, MODIFIED), the roadmap row 10 gains the item.
12. **Docs**: how-to (first run generates keys; how to promote an admin; how to get a token with curl), `AGENTS.md` layout unchanged (`src/Auth/` already listed).

Deviation from the roadmap wording "voters skeleton": ownership voters (`LinkVoter`, `ApiKeyVoter`) need a resource to vote on and arrive with those resources; this change enforces authorization boundaries by role (`ROLE_USER` / `ROLE_ADMIN`) through `access_control` and `#[IsGranted]`. Recorded in the roadmap row.

## Capabilities

### New Capabilities

- `user-accounts`: registration (web and API), account data, blocking semantics, role changes only from the console.
- `authentication`: session login for the web, JWT for the API, `/api/v1/me`, rate limit on auth endpoints, failure responses.
- `user-administration`: admin listing and block/unblock operations with the role boundary and audit log lines.

### Modified Capabilities

- `api-error-format`: the "Validation errors carry violations" requirement (422 shape) that was deferred to the first validated input is added now.
- `health-check`: the production deep-probe clause is rewritten — 404 stays unconditional in `prod` until `add-api-keys-and-rate-limiting` authorizes it by an admin API key (sessions/JWTs never do).

## Non-goals

- Email verification, password reset, remember-me, OAuth/social login, refresh tokens (specification §10).
- API keys (`add-api-keys-and-rate-limiting`), ownership voters and any link-related permission (`add-link-crud`).
- Styled web UI, dashboard, admin pages (`add-web-ui`); the two Twig pages here are deliberately bare.
- Per-user rate limits (API keys change); the redirect limit (redirect change).
- Two-factor authentication, session hardening beyond Symfony defaults + `cookie_secure: auto`, `cookie_samesite: lax`.

## Impact

- New: `src/Auth/**` (entity, repository interface + Doctrine repository, DTOs, API resources/processors/providers, security listener/subscribers, console commands, user checker), `migrations/Version*.php` (users), `config/packages/security.yaml`, `config/packages/lexik_jwt_authentication.yaml`, `config/packages/rate_limiter.yaml`, `templates/security/*.html.twig`, `tests/**` for every scenario.
- Modified: `.env` / `.env.dev` / `.env.test` (JWT and rate-limit variables), `.gitignore` (`config/jwt/`), `Makefile` (`jwt-keys`, `init`), `.github/workflows/ci.yml` (`make jwt-keys EXEC=`), `config/packages/framework.yaml` (cache pool on Redis for the limiter, trusted proxies), `config/packages/api_platform.yaml` (validation error resource if needed), docs, `openspec/ROADMAP.md` row 3 note.
- New dependencies (specification §5): `symfony/security-bundle` 8.1 (built in), `lexik/jwt-authentication-bundle` ^3.2 (JWT issuance/validation; Symfony has no issuer — §5 justification), `symfony/rate-limiter` 8.1 (built in), `symfony/form` (the two web pages), `symfony/expression-language` (API Platform's `security` operation attribute and `access_control` expressions need it — discovered at apply time, 500 without it), `symfony/lock` (Redis lock for the rate limiters, Gate 2 finding #2). CI: `make jwt-keys EXEC=` step; the spec table in §4 is unchanged.
