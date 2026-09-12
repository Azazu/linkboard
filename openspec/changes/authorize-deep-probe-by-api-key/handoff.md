# Handoff — authorize-deep-probe-by-api-key

**Updated:** 2026-09-12 · claude
**State:** proposing
**Branch:** change/authorize-deep-probe-by-api-key

## Done this session
- Branch `change/authorize-deep-probe-by-api-key` created from `main` (`dce4bc9`, after the archive of `add-api-keys-and-rate-limiting`); change scaffolded with `openspec new change`.
- Proposal (tier `high`), the health-check delta (admin key authorizes the `prod` probe; total budget 4 s enforced in code → 404 within 5 s in every failure mode; fail-closed identical 404; a Redis memory of the last verified key hash, TTL 300 s, consulted only when the database is unavailable, forgotten immediately on a negative answer — so the probe can report a database outage to a monitor verified before it), design (`Deadline` on `hrtime`, `BoundedDatabaseConnection`/`BoundedRedisConnection` factories shared with the probe, `ProbeAuthorizer` with the three-valued database step, fixtures for black-hole and delayed connections, the applicability table), tasks (Gate 1, bounded clients, authorizer, prod end-to-end tests incl. the combined delayed-connect + stalled-statement case, CI migrating `app`, docs, wrap-up). `openspec validate --strict` and `scripts/pregate-verify.sh gate1` pass.

**Design choice worth the reviewer's attention:** the Redis memory is what makes the deep probe useful during the outage it exists to detect; its cost is ≤ 300 s of authorization staleness for a revoked key *only while the database is down*. The alternative without it (the previous change's plan) was rejected as fail-closed-but-blind.

**Security-relevant parts for review:** the authorization boundary on a production endpoint outside the firewall — the regex-before-I/O, the three-valued database step, the memory's consult-only-on-unavailable rule, the identical 404, the no-`last_used_at`/no-logging rules.

## Next step
Gate 1 (high tier): `scripts/gate-run.sh authorize-deep-probe-by-api-key 1 full`; findings via `/workflow:fix-findings`. Then `/opsx:apply authorize-deep-probe-by-api-key`.

## Blockers
None.
