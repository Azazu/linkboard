# Handoff — authorize-deep-probe-by-api-key

**Updated:** 2026-09-12 · claude
**State:** awaiting-gate-1
**Branch:** change/authorize-deep-probe-by-api-key

## Done this session
- Branch `change/authorize-deep-probe-by-api-key` created from `main` (`dce4bc9`, after the archive of `add-api-keys-and-rate-limiting`); change scaffolded with `openspec new change`.
- Proposal (tier `high`), the health-check delta (admin key authorizes the `prod` probe; total budget 4 s enforced in code → 404 within 5 s in every failure mode; fail-closed identical 404; a Redis memory of the last verified key hash, TTL 300 s, consulted only when the database is unavailable, forgotten immediately on a negative answer — so the probe can report a database outage to a monitor verified before it), design (`KeyLookupProcess` — the lookup in a standalone child process killed at 2.5 s, `BoundedRedisCommands`, the generation-ordered `ProbeMemory`, `ProbeAuthorizer` with the three-valued database step, fixtures for black-hole, delayed and response-losing connections and a slow fake Redis, the applicability table), tasks (Gate 1, bounded clients, authorizer, prod end-to-end tests incl. the combined delayed-connect + stalled-statement case, CI migrating `app`, docs, wrap-up). `openspec validate --strict` and `scripts/pregate-verify.sh gate1` pass.

**Design choice worth the reviewer's attention:** the Redis memory is what makes the deep probe useful during the outage it exists to detect; its cost is ≤ 300 s of authorization staleness for a revoked key *only while the database is down*. The alternative without it (the previous change's plan) was rejected as fail-closed-but-blind.

**Security-relevant parts for review:** the authorization boundary on a production endpoint outside the firewall — the regex-before-I/O, the three-valued database step, the memory's consult-only-on-unavailable rule, the identical 404, the no-`last_used_at`/no-logging rules.

- Gate 1 round 1 (`69b0e92`, Reviewed-Commit `8b8fbd5`): changes-requested — three majors: a synchronous client cannot enforce the deadline after the handshake; `SET`/`DEL` do not order under interleaving; the outage report was broader than the execution path. Fixed in the following commit: the database phase on ext-pgsql's asynchronous API (`pgsql` added to the image and CI), per-command Redis timeouts, a reserved Redis allowance, a CAS-ordered memory anchored to the database's answer time with the Redis-unreachable-at-denial case admitted, the probe's own report instead of a promised status, new scenarios/tests (lost response, Redis stall, interleaving). Statuses → fixed.

- Gate 1 Confirmation 1 (`73d50cc`, Reviewed-Commit `c1b35dd`): finding 3 confirmed; findings 1–2 changes-requested — ext-pgsql's cleanup blocks on `PQgetResult` too, and `clock_timestamp()` does not order snapshots. Fixed in the following commit: the lookup in a killed child process (`bin/probe-key-lookup` + `KeyLookupProcess`, no new extension), a generation-ordered memory with the interleaving established by the real mechanism, a slow fake Redis for cumulative timeouts. Statuses remain fixed.

## Next step
`scripts/gate-run.sh authorize-deep-probe-by-api-key 1 confirm 1` — the second confirmation on findings 1–2; if either fails again, stop and ask the user to arbitrate (AGENTS.md). Then `/opsx:apply authorize-deep-probe-by-api-key`.

## Blockers
None.
