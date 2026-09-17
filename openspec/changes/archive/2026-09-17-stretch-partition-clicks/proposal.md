# Proposal — stretch-partition-clicks

**Risk-Tier:** high

## Why

`clicks` is the one table that only grows. Every redirect appends a row and
nothing ever removes one: the demo dataset alone is 1 020 279 rows, and a table
that grows without a bound has no answer to "delete the clicks older than a
year" other than a `DELETE` that rewrites the table and a `VACUUM` that follows
it. §3.4 of the specification was written for this change — "the schema above is
designed so that `occurred_at` can become part of the partition key without
changing readers" — and §9 asks for the partitioning, the retention policy and
an ADR covering the partition key, the window, and what retention does to the
unique-visitor numbers.

The reports are the second reason, and they are measured rather than assumed:
every one of them filters `occurred_at >= :from AND occurred_at < :to`, and
today every one of them scans the whole table's index for it. The published
benchmark has one miss — a link's `devices` report at p95 324 ms against a
300 ms target — and partition pruning is the mechanism that should move it.
This change states what it did to the numbers rather than claiming an
improvement.

## What Changes

- **`clicks` becomes a range-partitioned table, by month on `occurred_at`.**
  One partition per calendar month, named `clicks_YYYY_MM`. Readers are
  untouched: every query still reads `clicks`.
- **The primary key becomes `(id, occurred_at)`.** PostgreSQL requires the
  partition key in every unique constraint. This is not cosmetic: the
  idempotency of redelivery rests on that key, so the requirement that names it
  changes and gets a test that proves the guarantee survives.
- **A maintenance command provisions the months a click may legitimately fall
  in and drops the expired ones.** The range reaches backwards over the whole
  retention window, not only forwards: a click whose month has no partition
  cannot be inserted, and the first thing that happens to a fresh database is a
  seed or a fixture writing months into the past. It is a correctness concern,
  not housekeeping. Its configuration is validated before it issues any
  statement that changes the schema.
- **A declared retention window**, configured by an environment variable and
  enforced only by that command — nothing drops data on its own.
- **A click older than the expiry boundary is discarded rather than parked.**
  Retention and the transport disagree otherwise: after a month is dropped, a
  redelivery from that month would hit a missing partition and be parked,
  contradicting the promise that a redelivery is always acknowledged. The
  handler gains one guard on the message's own timestamp — its insert, its
  transaction and its two exception guards are untouched. The boundary is not
  the configured window but the later of the window and **how far the data has
  actually been removed**, recorded when a partition is dropped and never moved
  backwards: otherwise lengthening the window would resurrect a dropped month
  and let a replayed message be counted twice.
- **BREAKING for the all-time figures.** The summary report's `totalClicks`,
  `uniqueVisitors`, `firstClickAt` and `lastClickAt` are defined over *all* of a
  link's clicks. Once a partition is dropped they are over the retained history,
  and a visitor whose earlier clicks were dropped counts as new when they
  return. The specification says so rather than letting the number quietly
  change meaning.
- **An ADR** on the partition key, the window, the effect on unique visitors,
  and what a partitioned table costs — §9 asks for exactly these.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `click-logging`: the click record's key includes `occurred_at`; the storage's
  shape (monthly partitions), the horizon a click needs to be insertable, and
  the retention policy become stated behaviour.
- `analytics`: the summary report's all-time figures are over the retained
  history, not over all clicks that ever happened.

## Non-goals

- **No `click_daily` aggregate.** §9 lists it separately and the user kept it
  out of this plan on 2026-09-16. If partition pruning does not move the missed
  report, this change says so with the number rather than reaching for the next
  mechanism.
- **No change to any report's SQL.** §3.4 promises readers are untouched, and
  that promise is the evidence: the analytics suites pass unchanged, and a diff
  of `src/Analytics/` is part of the definition of done.
- **No automatic deletion.** Retention runs when the command runs. A cron entry
  is documented, not installed; nothing in the application drops a partition.
- **No sub-monthly partitions, no hash partitioning, no partitioning of any
  other table.** The month is the unit §9 names.
- **Not a rewrite of the write path.** The handler keeps its one transaction,
  its insert and its two exception guards; it gains exactly one guard, for a
  message older than the retention window, and nothing else.

## Impact

- **Schema**: a reviewed, reversible migration that converts a populated table —
  the irreversible-migration trigger `AGENTS.md` and NFR-SEC-7 both name, which
  is what puts this at `high` regardless of the roadmap's minimum.
- **`src/Click/`**: the entity's mapping (composite key), a maintenance command,
  and one guard in the handler for a message older than the window; the
  handler's SQL is unchanged.
- **`src/Analytics/`**: nothing. That is a claim this change has to defend.
- **Tests**: the redelivery guarantee under the new key, a click that lands in
  no partition, a replay after the window is widened, retention dropping a month
  and what that does to the summary, a freshly migrated database accepting the
  suite's own historical fixtures, and the round trip of `harden-gate-floor`
  over a partitioned schema.
- **Documents**: §3.4 and §9 of the brief, the ADR, `docs/how-to/` for the job
  and the window, `docs/reference/commands.md`, and the benchmark numbers.
- **No new dependency.**

## User decisions

- **2026-09-16 — order and scope.** The user restored the roadmap's order after
  the executor had suggested starting with row 16, and scoped the remaining work
  to rows 14, 15 and 16: the two stretch items of §9 without a roadmap row
  (`click_daily` and link-page metadata) stay out, recorded as possible
  continuations.
