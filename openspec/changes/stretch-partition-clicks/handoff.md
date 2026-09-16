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

- Gate 1 round 1 (`364b31c`, Reviewed-Commit `fe05c78`): changes-requested — three major and one minor, all four real, and the first would have failed in CI rather than locally.
  1. **A freshly migrated database could not accept its own fixtures.** The provisioning reached forwards only, so an empty database would carry the current month alone — while `app:demo:seed` writes over the preceding 60 days and the analytics fixtures write clicks as far back as **2026-03-28** (read from `tests/`, not assumed). Every one of those inserts would have failed on a database nobody had populated first; converting the already-seeded local database hid it exactly as the reviewer said. The provisioned range now reaches from the first month of the retention window to `now + horizon`, so "any click inside the window is storable on a freshly migrated database" is a stated property with its own task, checked on a database created from nothing.
  2. **Retention and the transport contradicted each other.** After a month is dropped, a redelivery from that month hits the missing partition and would be **parked** — against the idempotency requirement's promise that a redelivery is always acknowledged; and recreating the month to absorb it would destroy the only evidence the click was counted and permit a second lifetime increment. Decided: a message whose `occurred_at` is older than the window is acknowledged, discarded and logged, exactly as one for a deleted link is. It covers the three ways such a message arrives, and a fourth test case pins the distinction from the operational failure — inside the window with no partition is retried, not discarded.
  3. **The destructive command trusted its configuration.** A window of `0` puts the cutoff at this instant and makes the current, populated month eligible; `-1` puts it in the future and makes every month eligible — and an unset variable reads as an empty string. Both settings are now parsed and validated before any statement that changes the schema, with a demonstrated failing input per case asserting the partitions and row counts are identical afterwards.
  4. **"Retained" was defined as "inside the window"**, which contradicts both whole-month retention (a straddling month keeps older rows) and on-demand execution (an unrun command keeps everything). Retained now means present; the window decides which months become *eligible*.

## Next step
Gate 1 confirmation: `scripts/gate-run.sh stretch-partition-clicks 1 confirm 1`. All four findings are `fixed` in `review.md`; `openspec validate --strict` and `scripts/pregate-verify.sh gate1` pass, 28 tasks.

## Blockers
None.
