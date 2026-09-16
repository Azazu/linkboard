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

## Next step
`/opsx:propose stretch-partition-clicks`. Four things the proposal must establish rather than assume:

1. **The migration path for a table that already holds rows.** `clicks` is not empty anywhere this runs — the local database carries a million demo rows. Converting an ordinary table into a partitioned one is not an `ALTER`: it is a new parent, the old table attached or copied, and a swap. Which of those, what it costs on a million rows, and what happens to writes while it runs, are the decision this change is about — and it is irreversible in the way the tier means.
2. **What the round trip of `harden-gate-floor` says about it.** `make migrations-roundtrip` now runs every `down` and compares the schema; a partitioning migration has to be reversible under that check, or the check has to be told why not — in writing, not by exception.
3. **The retention window and who enforces it.** A number (90 days? 12 months?), a command, and whether it runs from the worker, from cron, or by hand. `app:demo:seed` and the benchmarks both write months of history, so the window interacts with what the README publishes.
4. **The measurement.** Partitioning is a performance change; the series has a benchmark recipe (`docs/how-to/benchmarks.md`) and a published miss — `devices` p95 324 ms against 300 — so this change can state what it did to the numbers rather than assert an improvement.

## Blockers
None.
