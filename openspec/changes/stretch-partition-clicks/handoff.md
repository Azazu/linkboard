# Handoff — stretch-partition-clicks

**Updated:** 2026-09-16 · claude
**State:** proposing
**Branch:** change/stretch-partition-clicks

## Done this session
- Branch `change/stretch-partition-clicks` created from `main` (`ce281e7`, the archive of `harden-gate-floor`, which closed stage 4); change scaffolded with `openspec new change`.
- Scope, from roadmap row 14 and §9 of `docs/explanation/requirements.md`: **monthly range partitioning of `clicks`**, a **retention policy and the job that enforces it**, and an **ADR** on the partition key, the retention window and the effect on unique-visitor counts.
- Order restored by the user on 2026-09-16: row 16 (`stretch-public-hosting`) was started first by the executor's suggestion and is parked on its own branch behind rows 14 and 15.

## What the specification already fixes, and what it leaves open
- **§3.4** designs the table for this: "`occurred_at` can become part of the partition key without changing readers". PostgreSQL requires every unique key to contain the partition key, so the primary key moves from `id` to `(id, occurred_at)` — a readers-invisible change that the proposal has to state rather than discover.
- **§3.4** also fixes the two indexes the reports depend on, including the partial one (`WHERE NOT is_bot`), which have to exist per partition.
- **NFR-SEC-7** puts any migration that partitions at `high` tier with a failing-input test for each new guard — the tier is not the roadmap's to grant.
- **§9** asks the ADR to cover the effect of retention on unique-visitor counts: dropping a month makes a returning visitor look new, which changes a published number, not only storage.

- Artifacts written and `openspec/validate --strict` passes: proposal (tier `high` argued from the irreversible migration rather than the roadmap's minimum), two capability deltas, design (seven decisions plus the applicability table), tasks (24 across nine sections, Gate 1 first, the Gate 2 and archive steps as an un-checkboxed lifecycle section).
- **Read from the source before proposing, not remembered**: `clicks` is `PRIMARY KEY (id)` with an FK to `links` and two indexes, one partial; the handler's idempotency rests entirely on that key, through its `UniqueConstraintViolationException` guard; the `Click` entity is `readOnly` and named by nothing but the architecture test; and the summary's `total`, `uniques`, `first_at` and `last_at` are computed with **no period bound**, which is why retention is a spec change and not only an operational one.
- **Two consequences the proposal had to name rather than discover**: the primary key becomes `(id, occurred_at)` because PostgreSQL requires the partition key in every unique constraint — so the idempotency requirement is reworded and gets a scenario that redelivers a message from an earlier month; and an insert into a month with no partition raises `no partition of relation` — **neither** of the handler's two guards — so the horizon is a correctness property with a failing-input test, not housekeeping.
- **One implementation of how a partition is named and bounded**: a SQL function created by the migration and called by both the migration and the command. This series has been bitten twice by two implementations of one rule agreeing by inspection until they did not.

## Next step
Gate 1: `scripts/gate-run.sh stretch-partition-clicks 1 full` — tier `high`, so the artifacts are reviewed before any implementation. `scripts/pregate-verify.sh gate1` passes.

## Blockers
None.
