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
