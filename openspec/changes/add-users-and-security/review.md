# Review — add-users-and-security

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** e2f86da8bd1171be01aefd455767f8373a5a3975
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | openspec/specs/health-check/spec.md: Deep dependency probe; openspec/changes/add-users-and-security/design.md: Decisions 4 and 10; tasks.md | The existing health specification says that the production deep probe is refused only until the authorization boundary introduced by this change exists. This plan instead keeps the controller's unconditional production 404 and explicitly makes all of `^/health` public; it neither defines an authorized deep-probe policy nor revises the now-expired condition in the health spec. Decide the post-change contract: implement and test a protected operator/admin deep probe, or explicitly defer it to a named later change and amend the health requirement accordingly. Cover the chosen behavior in the proposal, design, tasks and delta spec. | fixed |
| 2 | blocker | openspec/changes/add-users-and-security/proposal.md: What Changes #10; design.md: Decision 5; tasks.md: 1.2 | The plan requires different committed dev/test `JWT_PASSPHRASE` values and environment-specific key paths, but its only concrete key path is the shared `config/jwt/private.pem` and `make jwt-keys` has no environment argument. A private key generated in dev is encrypted with the dev passphrase; `APP_ENV=test` will then attempt to load that same file with the test passphrase, and `--skip-if-exists` prevents replacing it. Specify environment-isolated key paths (or one deliberate shared test/dev credential policy), make key generation and the CI command explicitly use the matching environment, and add passing tests for token issuance in both dev/test paths without committing key material. | fixed |
| 3 | blocker | openspec/changes/add-users-and-security/design.md: Decision 8 and Tests; tasks.md: 1.3, 6.2 | Decision 8 says `when@test` replaces `cache.rate_limiter` with `cache.adapter.array`, while task 6.2 also claims an integration test will verify the same `auth_ip` limiter against the real Redis pool. The normal test kernel cannot satisfy both, and no second environment, separate Redis-backed limiter/pool, or exact invocation is planned. Define a feasible isolated Redis-limiter test path and its configuration, while retaining deterministic per-test rate-limit tests; otherwise the Redis-backed production security mechanism is never actually verified. | fixed |

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 9ce127b5655541d9de2a2dcd4ab7d1fb8839040d
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — the health delta now explicitly preserves the unconditional production 404 until `add-api-keys-and-rate-limiting` adds an admin-API-key path, and tasks cover the unchanged 404 plus rejection of an admin JWT. |
| 2 | confirmed — environment-scoped key paths and explicit dev/test key generation prevent passphrase/key reuse; the plan verifies both environments and a deliberate mismatch without committing PEM files. |
| 3 | confirmed — the request-path limiter remains deterministic on the test array pool, while an identically configured secondary limiter on a distinct Redis pool provides a feasible real-Redis integration check. |

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 380cb567d2873b9e6e49bbdddd1c2ca24df5c1fc
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | src/Auth/Security/UserProvider.php:34-42; src/Auth/Security/BlockedUserChecker.php:15-18 | Blocking does not revoke an existing web session. `refreshUser()` returns the blocked entity without rejecting it. The installed Symfony `ContextListener::refreshUser()` does not invoke the user checker; its `hasUserChanged()` compares password, roles and identifier, none of which changes on block. Consequently, logging in, blocking that account in another request, then visiting `/` with the existing cookie still renders the authenticated account instead of redirecting to login with the blocked error. Enforce the blocked-account policy on session refresh/request handling and add a regression test that logs in before blocking and reuses that session. The current web test only covers an account blocked before login. Correct the corresponding claim in the checker comment and design decision 6. | fixed |
| 2 | major | config/packages/framework.yaml:22-27 | `auth_ip` explicitly disables locking although design decision 8 requires a Redis lock. Symfony's installed `SlidingWindowLimiter::reserve()` performs separate storage fetch, increment and save operations; `CacheStorage` does not make this sequence atomic. Two concurrent requests can both read nine consumed tokens, both be accepted, and both save ten, exceeding the promised per-IP limit. Configure a shared lock for the production limiter (or an equivalent atomic mechanism), keep the Redis verification path representative, and demonstrate a failing concurrent-input test. Sequential requests and persistence across kernel boots do not exercise this guard. | fixed |
| 3 | major | config/packages/monolog.yaml:4,37-51 | Successful block/unblock actions are not written to the production audit log. The new audit channel has a dedicated handler only in `test`; in `prod` it flows through `main`, a `fingers_crossed` handler activated only by an error, without a passthrough level. A normal successful 200 request therefore buffers the info record and discards it at close/reset. Give the audit channel a production handler that writes successful actions without an unrelated error, and verify both block and unblock against that wiring. The test-only `TestHandler` currently masks this failure of the admin-audit requirement. | fixed |
| 4 | major | src/Auth/Api/RegisterUserProcessor.php:43-46 | The duplicate-insert race is translated into Symfony `ValidationFailedException`, which is not API Platform's `ValidationException` or its `ConstraintViolationListAwareExceptionInterface` and has no configured status mapping. The installed API Platform error listener therefore treats it as 500, without the required email violations, when two registrations pass the uniqueness pre-check and one loses at flush. Use the API Platform validation exception (as the self-block processor already does) and add a test that reaches the flush-conflict branch and verifies 422 plus an email violation. The existing duplicate API test stops at the pre-check, and the repository test proves only the database constraint. | fixed |

### Verification
Reviewed the branch diff against `main`, change artifacts, tests, and the installed Symfony/API Platform/Monolog implementations underlying the findings. HEAD and branch match the requested review target; `git diff --check main...change/add-users-and-security` passed. Runtime reproduction and a fresh check run were unavailable: `make check` stopped before executing the style checker because access to `/var/run/docker.sock` was denied; no host PHP executable is installed. Findings above are based on source inspection, not claimed runtime reproductions.

## Confirmation 1 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** c1e430686bdb4c089941a312fff7a07178734098
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — the runtime fix is supported by source inspection: `UserProvider::refreshUser()` now rejects blocked accounts, the exception subscriber preserves the login error, and `LoginTest::testBlockingEndsAnExistingWebSession()` covers reuse of an existing session. However, the explicitly requested claim correction is unfinished: `src/Auth/Security/BlockedUserChecker.php:15-18` still says this checker runs on session refresh, and design decision 6 still asserts `refreshUser` → checker immediately before its new paragraph correctly says the opposite. Replace those stale assertions with the actual split between authentication checking and provider-enforced session refresh; do not merely append another explanation. |
| 2 | confirmed — both `auth_ip` and the Redis verification limiter now use `lock.factory`, backed by Redis through `LOCK_DSN`; the installed sliding-window implementation holds that lock across fetch, update and save. `RateLimiterConcurrencyTest` exercises 25 processes against one Redis-backed limiter key and requires exactly 10 acceptances. The fixture provides a no-lock path, and commit c1e4306 records the demonstrated failing input (17/25 accepted without locking versus 10/25 with locking). These execution results are executor evidence, not independently rerun here. |
| 3 | changes-requested — the dedicated production info-level JSON stream and exclusion from `main` correct the identified configuration defect. The requested verification of both successful actions against that wiring is still missing: `AuditLogWiringTest` only parses YAML; the existing API audit test exercises only block through the test-only `TestHandler`, and the unblock API test does not assert an audit record. Add a verification using the production audit handler wiring that performs successful block and unblock and observes their info records, including actor/target/action, without an unrelated error or fingers-crossed activation. |
| 4 | changes-requested — the processor now throws the correct API Platform `ValidationException`, whose installed implementation supplies status 422 and email violations. The new integration test reaches the real unique-index conflict and asserts the exception's email violation, but calls the processor directly and never verifies the required HTTP 422 response. Complete the requested regression by reaching that conflict through the API error-handling path and asserting status 422 plus an email violation in the problem response; the existing duplicate-request test still stops at the uniqueness pre-check. |

### Verification
Reviewed only `380cb567d2873b9e6e49bbdddd1c2ca24df5c1fc..c1e430686bdb4c089941a312fff7a07178734098` and collateral source, configuration, specifications, tests and installed framework code reachable from findings 1–4. Repository searches checked the affected claims. Branch and HEAD match the requested target; the worktree was initially clean and `git diff --check` for the requested range passed. Runtime verification was unavailable: `make ps` failed because Docker socket access was denied, and `command -v php` found no host PHP. No unrelated findings were introduced; only this review file was modified and no git write commands were run.
