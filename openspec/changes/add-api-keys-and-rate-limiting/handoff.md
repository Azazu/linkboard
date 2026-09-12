# Handoff — add-api-keys-and-rate-limiting

**Updated:** 2026-09-12 · claude
**State:** ready-to-merge
**Branch:** change/add-api-keys-and-rate-limiting

## Done this session
- Branch `change/add-api-keys-and-rate-limiting` created from `main` (`64ac596`, after the archive of `add-qr-codes`); change scaffolded with `openspec new change`.
- Proposal (tier `high`), specs (`api-keys` new — create/see once, list/revoke, key authentication, per-identity limit; `authentication`, `user-accounts`, `health-check` modified), design (one firewall with Symfony's `access_token` beside Lexik's JWT, Lexik's chain extractor decorated to decline `lb_` tokens — verified against `AuthenticatorManager` and factory priorities; SHA-256 hash lookup, once-a-minute `last_used_at` as one conditional `UPDATE`; `api_identity` sliding-window limiter, fail-open, `X-RateLimit-*` headers; prod deep probe via an `ApiKeyResolver`; applicability table), tasks (Gate 1, schema/domain, authentication, key API, limiter, probe, docs, wrap-up with the green-run task before Gate 2). `openspec validate --strict` passes; `scripts/pregate-verify.sh gate1` passes.

**Assumptions recorded in the artifacts:** the 11th active key answers 409 (state conflict, not 422); `X-RateLimit-*` headers only on limited (authenticated, non-auth-endpoint) responses; the API limiter fails open like the redirect limiter.

**Security-relevant parts for review:** a second authenticator on the API firewall and the decorator that keeps the JWT authenticator off API keys; the hashed credential store; the owner-only key resource; the rate-limit subscriber's identity key.

- Gate 1 round 1 (`4f29d74`, Reviewed-Commit `b7678a0`): changes-requested — three majors: the unlocked count-and-insert of the 10-key cap, the limiter hooked after access control, the unbounded probe key lookup. Fixed in the following commit: owner-row lock in one transaction + concurrency test; limiter on `LoginSuccessEvent` (verified: authenticator listener before the access listener) + role-denied tests; bounded fail-closed `ProbeKeyResolver` on the probe's own PDO factory + CI migrating `app` (decision 10, task 5.2). Statuses → fixed.

- Gate 1 Confirmation 1 (`ff5e172`, Reviewed-Commit `f576c8b`): findings 1–2 confirmed, finding 3 changes-requested — the lookup budget (2 s + 2 s) did not fit the spec's 3 seconds and the prod HTTP matrix lacked the refused/stalled cases. Fixed in the following commit: 1 s connect + 1 s statement for the resolver, prod-kernel HTTP tests for a closed port and a locked `api_keys` table with an elapsed-time assertion, the warning asserted at the resolver level.

- Gate 1 Confirmation 2 (`97cafc9`, Reviewed-Commit `b2b8d91`): finding 3 changes-requested again — libpq's minimum `connect_timeout` is 2 s, the 1-second bound was unenforceable, the combined delay untested. Two failed confirmations → user arbitration (2026-09-12): the probe authorization is split into the follow-up change `authorize-deep-probe-by-api-key` (ROADMAP row 10a); this change's scope is keys, key authentication and the per-identity limit. Finding 3 → `wont-fix` with the reason; the health-check delta only repoints the forward reference. The user authorised a third confirmation on the reduced change.

- Gate 1 passed: Confirmation 3 (`03cbd2e`, Reviewed-Commit `0b29a42`) — findings 1–2 confirmed, finding 3 confirmed as resolved by the scope split. Two editorial remnants Codex noted (`tests/Api/Health*` in Impact, the `isAdmin()` failing input in decision 8) removed. Task 0.1 ticked.

- Implemented (2026-09-12): `5816881` — `api_keys` migration (comparator noise trimmed), `ApiKey` entity, `DoctrineApiKeyRepository` (hash lookup, owner scope, `lockOwner`, one-statement `touchLastUsed`), factory, unit + integration tests incl. the migration round trip in two console processes; `89e3846` — `ApiKeyGenerator`; `2ed88f7` — `access_token` on the `api` firewall with `ApiKeyTokenHandler`, `ApiKeyHeaderExtractor`, `ApiKeyAuthenticationFailureHandler`, Lexik's chain extractor decorated to decline `lb_`, `ApiKeyAuthTest`; `eb8f3b1` — the `ApiKey` resource (list/create/revoke), `ApiKeyVoter`, `ApiKeysTest`, the 12-process concurrency test (one success, eleven 409, ten active); `b12d088` — `api_identity` limiter on `LoginSuccessEvent` before access control, headers writer, `ApiRateLimitTest` (role-denied requests counted, fail-open), Redis twin in `RateLimiterStorageTest`; `07adcda` — how-to and FR-KEY-1…4, §7 rows 10/10a. `make check` green: 602 tests / 8465 assertions. Tasks 1.1–6.1 ticked with evidence; every guard has a demonstrated failing input in its commit body (notable: without `lockOwner()` the race produced two successes in two of three runs; the provider's owner scope alone keeps a foreign `DELETE` at 404 even with the processor's check removed; a `kernel.request` limiter at priority 7 never counts role-denied requests).

**Security-relevant parts for review:** the second authenticator on the `api` firewall and the decorator that keeps the JWT authenticator off `lb_` tokens (`2ed88f7`); the hash-only credential store and the constant 401 (`5816881`, `2ed88f7`); the owner-only key resource with the serialised cap (`eb8f3b1`); the limiter's identity key and its position before access control (`b12d088`). Observed while implementing: `doctrine:schema:validate` reports two pre-existing differences unrelated to `api_keys` (the partial index predicate spelling on `clicks`, the messenger index name); the test kernel needs `cache:clear --env=test` after resource-metadata or security changes.

- Branch run 34698485045 on `9bb1ba0`: completed, success (https://github.com/Azazu/linkboard/actions/runs/34698485045). Task tokens that are not paths lost their backticks (pregate); 6.2 and 6.3 ticked (6.3 at the gate request).

- Gate 2 round 1 (`4314c02`, Reviewed-Commit `e9da6ce`): changes-requested — major: `expiresAt` accepted relative text through the serializer's DateTime fallback; minor: revocation idempotency relied on an in-memory snapshot. Fixed in `e8ad617`: `expiresAt` validated on the raw string (`Assert\DateTime(format: RFC3339)`, future check in the processor against the clock; rejections for `tomorrow`, a timestamp without zone, an impossible date, date-only; a valid value echoed), revocation as one conditional `UPDATE … WHERE revoked_at IS NULL` with a repository test; both failing inputs demonstrated; design decision 5 / applicability, the spec scenario and task 3.1 updated; statuses → fixed. `make stan`/`make cs` clean, the affected suites green (16 tests).

- Gate 2 Confirmation 1 (`83f64bb`, Reviewed-Commit `004c843`): finding 2 confirmed; finding 1 changes-requested on a collateral — `expiresAt: ""` skipped the DateTime validator and hit a LogicException (500). Fixed in the following commit: `NotBlank(allowNull: true)` (empty rejected, null/omitted = never expires), the processor renders a parse failure as a 422 violation; tests for `""` and `null`; two-layer failing-input demonstration recorded.

- Gate 2 passed: Confirmation 2 (`da272dc`, Reviewed-Commit `a8b70a5`) — both findings confirmed (raw-string `expiresAt` validation with the empty-string guard, conditional revocation).

- Branch run 34700349986 on `2a5700c`: completed, success (https://github.com/Azazu/linkboard/actions/runs/34700349986) — the merge head; since then only this file changes. `make check` on the head: 603 tests green.

## Next step
`/git:merge add-api-keys-and-rate-limiting` (verifier passes). After the merge: the user pushes `main`, the executor checks the `main` run, then `/opsx:archive add-api-keys-and-rate-limiting` (specs `api-keys` new; `authentication`, `user-accounts`, `health-check` modified; ROADMAP row 10 removed, row 10a stays).

## Blockers
None.
