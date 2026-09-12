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

- Gate 1 Confirmation 2 (`c0f3504`, Reviewed-Commit `fb42073`): finding 3 confirmed; findings 1–2 changes-requested — the Redis operation budget did not account for the pre-lookup read and the denial writes (a 0.5 s delay timed out at `AUTH`); an integer generation with a TTL can recur after expiry, and the verification-age bound was lost. Two failed confirmations → the user arbitrated: apply both fixes and run a third confirmation. Fixed in the following commit: a fixed sequence of four Redis operations at 0.25 s each (connect, `AUTH`/`PING`, the token read, one post-lookup command) with the post-lookup command always funded; a random 128-bit denial token replaced (never incremented) by one deny `EVAL`; `verified_at` stored and checked against an absolute 300-second age at consult; fake Redis gains `--slow-from-command` and `--accept-delay`; new tests: the reuse-after-expiry interleaving, the 301-second-old memory, the whole delayed sequence incl. connect. Statuses remain fixed.

- Gate 1 Confirmation 3 (`e1c9472`, Reviewed-Commit `8896305`): findings 2–3 confirmed; finding 1 changes-requested on fixture mechanics only — `--slow-from-command=4` never reached the consultation (three RESP commands per request; connect is not one), `--accept-delay` stalls `AUTH` rather than connect (an established connection waits in the accept queue), and no assertion pinned the operation that timed out. The user arbitrated again: fix and run a fourth confirmation. Fixed in the following commit: command index 3; `full-backlog-listener.php` (`listen(0)` with one parked connection, so the next SYN is dropped) for a stalled connect; `MemoryUnavailable` names its operation and the fake Redis writes a command log, and every fixture test asserts the operation and the completed commands; the whole-sequence success and the guard-removal witness (per-command timeout removed) retained. Statuses remain fixed.

## Next step
`scripts/gate-run.sh authorize-deep-probe-by-api-key 1 confirm 1` — the **fourth** confirmation on finding 1, authorised by the user (proposal "User decisions"); if it fails again, stop and report — the user decides. Then `/opsx:apply authorize-deep-probe-by-api-key`.

## Blockers
None.
