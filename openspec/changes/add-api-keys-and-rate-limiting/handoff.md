# Handoff — add-api-keys-and-rate-limiting

**Updated:** 2026-09-12 · claude
**State:** awaiting-gate-1
**Branch:** change/add-api-keys-and-rate-limiting

## Done this session
- Branch `change/add-api-keys-and-rate-limiting` created from `main` (`64ac596`, after the archive of `add-qr-codes`); change scaffolded with `openspec new change`.
- Proposal (tier `high`), specs (`api-keys` new — create/see once, list/revoke, key authentication, per-identity limit; `authentication`, `user-accounts`, `health-check` modified), design (one firewall with Symfony's `access_token` beside Lexik's JWT, Lexik's chain extractor decorated to decline `lb_` tokens — verified against `AuthenticatorManager` and factory priorities; SHA-256 hash lookup, once-a-minute `last_used_at` as one conditional `UPDATE`; `api_identity` sliding-window limiter, fail-open, `X-RateLimit-*` headers; prod deep probe via an `ApiKeyResolver`; applicability table), tasks (Gate 1, schema/domain, authentication, key API, limiter, probe, docs, wrap-up with the green-run task before Gate 2). `openspec validate --strict` passes; `scripts/pregate-verify.sh gate1` passes.

**Assumptions recorded in the artifacts:** the 11th active key answers 409 (state conflict, not 422); `X-RateLimit-*` headers only on limited (authenticated, non-auth-endpoint) responses; the API limiter fails open like the redirect limiter; the deep probe never writes `last_used_at`.

**Security-relevant parts for review:** a second authenticator on the API firewall and the decorator that keeps the JWT authenticator off API keys; the hashed credential store; the owner-only key resource; the rate-limit subscriber's identity key; the production probe's admin-key branch.

- Gate 1 round 1 (`4f29d74`, Reviewed-Commit `b7678a0`): changes-requested — three majors: the unlocked count-and-insert of the 10-key cap, the limiter hooked after access control, the unbounded probe key lookup. Fixed in the following commit: owner-row lock in one transaction + concurrency test; limiter on `LoginSuccessEvent` (verified: authenticator listener before the access listener) + role-denied tests; bounded fail-closed `ProbeKeyResolver` on the probe's own PDO factory + CI migrating `app` (decision 10, task 5.2). Statuses → fixed.

- Gate 1 Confirmation 1 (`ff5e172`, Reviewed-Commit `f576c8b`): findings 1–2 confirmed, finding 3 changes-requested — the lookup budget (2 s + 2 s) did not fit the spec's 3 seconds and the prod HTTP matrix lacked the refused/stalled cases. Fixed in the following commit: 1 s connect + 1 s statement for the resolver, prod-kernel HTTP tests for a closed port and a locked `api_keys` table with an elapsed-time assertion, the warning asserted at the resolver level.

## Next step
`scripts/gate-run.sh add-api-keys-and-rate-limiting 1 confirm 1` — the second confirmation on finding 3; if it fails again, stop and ask the user to arbitrate (AGENTS.md). Then `/opsx:apply add-api-keys-and-rate-limiting`.

## Blockers
None.
