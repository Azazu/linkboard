## 1. Dependencies and wiring

- [ ] 1.1 `make composer ARGS='require symfony/security-bundle lexik/jwt-authentication-bundle symfony/rate-limiter symfony/form'` (recipes reviewed: `security.yaml`, `lexik_jwt_authentication.yaml`, `.env` additions moved into `.env.dev`/`.env.test` per design decision 5). Verify: `make console ARGS='debug:config security'` and `debug:config lexik_jwt_authentication` run; `git diff .env` shows only Flex markers with no secret values.
- [ ] 1.2 `Makefile`: `jwt-keys` target (`lexik:jwt:generate-keypair --skip-if-exists`), called by `init`; `.gitignore`: `/config/jwt/`; CI: `make jwt-keys EXEC=` before `make test-db EXEC=`. Verify: `make jwt-keys` creates `config/jwt/private.pem` and `public.pem`; `git status --short` does not list them; pinned actionlint exits 0 on `ci.yml`.
- [ ] 1.3 `framework.yaml`: `trusted_proxies`/`trusted_headers` from env, cache pool `cache.rate_limiter` on Redis (`cache.adapter.redis`, `REDIS_URL`), `rate_limiter.auth_ip` (design decision 8), array storage under `when@test`. Verify: `make console ARGS='debug:config framework rate_limiter'` shows `auth_ip` with the env-resolved limit.

## 2. Users

- [ ] 2.1 `src/Auth/Entity/User.php`, `src/Auth/UserRepositoryInterface.php`, `src/Auth/Repository/DoctrineUserRepository.php`, `src/Auth/Security/UserProvider.php` (case-insensitive lookup). Verify: `make console ARGS='doctrine:schema:validate --skip-sync'` mapping OK; `make stan` clean.
- [ ] 2.2 `make migration` → review the generated class, add `CREATE UNIQUE INDEX uniq_users_email_lower ON users (lower(email))` and its `down()`; `make migrate`; `make test-db`. Verify: `make console ARGS='doctrine:migrations:migrate prev --no-interaction'` then `migrate` again both succeed (reversible); `\d users` via `dbal:run-sql` shows the functional index.
- [ ] 2.3 Integration test `tests/Integration/Auth/DoctrineUserRepositoryTest.php`: find by email case-insensitively; failing input: inserting `Ann@Example.com` after `ann@example.com` raises `UniqueConstraintViolationException`. Verify: `make test` green including this test.
- [ ] 2.4 Console commands `app:user:promote` / `app:user:demote` + `tests/Integration/Auth/PromoteDemoteCommandTest.php` (exit 0 + role present; unknown email exit 1). Verify: `make console ARGS='app:user:promote nobody@example.com'` exits 1 with a clear message.

## 3. Registration and the 422 shape

- [ ] 3.1 `RegistrationInput` DTO with constraints (`Email`, `Length(min 12)`, `UniqueEmail` custom constraint + validator over the repository), `Registration` API resource `POST /api/v1/auth/register` with `RegisterUserProcessor`, `UserOutput`. Verify: `curl -s -i -X POST http://127.0.0.1:8082/api/v1/auth/register -H 'Content-Type: application/json' -d '{"email":"ann@example.com","password":"correct-horse-battery"}'` → 201 with id/email/createdAt; the same again → 422 with a `violations` entry for `email`.
- [ ] 3.2 `tests/Api/Auth/RegistrationTest.php`: 201 shape and no password material; duplicate differing by case → 422; 11-char password → 422 (no account created); `{"email":"not-an-email","password":"short"}` → 422 with `violations` for `email` and `password` (spec `api-error-format`). Verify: `make test` green.

## 4. Firewalls, JWT, blocking

- [ ] 4.1 `security.yaml` (design decision 4), `lexik_jwt_authentication.yaml` (`token_ttl: 3600`, key paths, passphrase env), `JwtProblemDetailsSubscriber` (success → add `expiresAt`; failure/invalid/expired/not-found → 401 problem details; `AccountStatusException` → 403 `detail: blocked`), `BlockedUserChecker`, `GET /api/v1/me` (`Me` resource with a state provider). Verify: `curl -s -X POST http://127.0.0.1:8082/api/v1/auth/token -H 'Content-Type: application/json' -d '{"email":"ann@example.com","password":"correct-horse-battery"}'` → `{"token":…,"expiresAt":…}`; `GET /api/v1/me` with the bearer → 200; without → 401 problem+json.
- [ ] 4.2 `tests/Api/Auth/TokenTest.php`: issue + `/me`; wrong password 401; missing token 401; malformed token 401; expired token 401 (token minted with `exp` in the past through the JWT manager / encoder with a past timestamp). Failing input for blocking: `tests/Api/Auth/BlockedUserTest.php` — valid JWT, then block via repository, replay → 403 `blocked`. Verify: `make test` green.
- [ ] 4.3 Public/protected surface test `tests/Api/Auth/AccessControlTest.php`: `/api/docs.json`, `/api/v1`, `/health` public; `/api/v1/me` 401 anonymous. Verify: `make test` green.

## 5. Admin operations

- [ ] 5.1 `UserAdmin` resource: `GET /api/v1/admin/users` (provider, pagination ≤ 100), `POST /api/v1/admin/users/{id}/block`, `/unblock` (processors, self-block 422, unknown id 404, idempotent), operation `security: is_granted("ROLE_ADMIN")`, audit log lines on the `audit` channel with `action`, `actor_id`, `target_id`. Verify: with an admin token, `curl` list → 200; block a user → 200 `isBlocked: true`; the same with a user token → 403.
- [ ] 5.2 `tests/Api/Admin/UserAdminTest.php` matrix: anonymous 401 / user 403 / admin 200 for list and block; unknown id 404; self-block 422; block twice 200; unblock then the user logs in again; block then the user's old JWT → 403. Audit: a log handler test asserts one `info` record with `action user.block`, `actor_id`, `target_id`, no `@`. Verify: `make test` green.

## 6. Rate limit

- [ ] 6.1 `AuthRateLimitSubscriber` (paths and methods per design decision 8; 429 with `Retry-After`; problem details under `/api`, `templates/security/rate_limited.html.twig` otherwise). Verify: `for i in $(seq 1 11); do curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8082/api/v1/auth/token -H 'Content-Type: application/json' -d '{"email":"x@example.com","password":"wrong-password-here"}'; done` prints ten 401 then a 429.
- [ ] 6.2 `tests/Api/Auth/RateLimitTest.php`: 11th request 429 with `Retry-After` and problem+json; spoofed `X-Forwarded-For` from an untrusted peer is counted against the real peer (failing input for the trusted-proxy rule). `tests/Integration/Auth/RateLimiterStorageTest.php`: the `auth_ip` limiter with the Redis pool consumes and persists a token (the Redis path is real). Verify: `make test` green.

## 7. Web pages

- [ ] 7.1 `src/Web/Security/LoginController`, `RegistrationController` + `RegistrationFormType`, `templates/security/login.html.twig`, `register.html.twig`, a placeholder `/` page for the post-login target. Verify: `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8082/login` → 200; `/register` → 200.
- [ ] 7.2 `tests/Web/Security/LoginTest.php`, `RegistrationTest.php`: register → 302 to `/login`; login OK → 302; wrong password → error message without email disclosure; missing CSRF → failure; blocked user → "blocked" message. Verify: `make test` green (suite `Web` added to `phpunit.dist.xml`).

## 8. Docs, plan, wrap-up

- [ ] 8.1 `docs/how-to/local-development.md`: keys on first run, `make jwt-keys`, promote an admin, get a token with curl, trusted proxies note; `docs/reference/commands.md`: `make jwt-keys`, `app:user:promote/demote`. `openspec/ROADMAP.md` row 3: "voters skeleton" → "role-based authorization boundaries; voters arrive with links". Verify: files re-read whole; every command run in its exact form.
- [ ] 8.2 Sweep: `rg -n 'voters skeleton' openspec docs` returns nothing outside the archive; `docs/explanation/requirements.md` §7 row 3 wording matches. Verify: the rg output.
- [ ] 8.3 `make check` green; commit per block (`feat(auth):`, `feat(admin):`, `test:`, `docs:`) with the agent trailer; the commit bodies name every failing input demonstrated. Verify: `git log --oneline main..HEAD`.
- [ ] 8.4 **Green Actions run on the exact branch head before Gate 2**: the user pushes the change branch; the executor polls the run list for the head SHA and then `/actions/runs/{run_id}/jobs` until `workflow`, `detect` and `php` are all `success`; URL and SHA recorded in `handoff.md`. A red run is fixed and re-pushed first.
- [ ] 8.5 `openspec validate add-users-and-security --strict` and `scripts/pregate-verify.sh gate2 add-users-and-security` pass; request Gate 2. Verify: no FAIL line.

## Post-merge acceptance (not a Gate 2 task)

- After the user pushes `main`, the executor checks the `main` run (run list by SHA + jobs endpoint) and reports it before offering the archive.
