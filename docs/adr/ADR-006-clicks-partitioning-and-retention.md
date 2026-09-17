# ADR-006: `clicks` is partitioned by month, and retention drops whole months

**Date:** 2026-09-16
**Status:** accepted
**Related:** [ADR-002](ADR-002-cqrs-lite-click-and-analytics.md); specification §3.4 and §9; decided in the change `stretch-partition-clicks`

## Context

`clicks` is the only table in this service that grows without a bound. Every
redirect appends a row and nothing ever removes one; the demo dataset alone is a
million. Two questions follow from that, and the specification asked both of
them in advance: how do you delete a year-old click without rewriting the table
(§9), and how do you keep the reports fast as the table grows (NFR-PERF-2)?

§3.4 was written for this change: "the schema above is designed so that
`occurred_at` can become part of the partition key without changing readers".
Every report already filters `occurred_at >= :from AND occurred_at < :to`.

## Decision

**The partition key is `occurred_at`, ranged, one partition per calendar month
in UTC.**

Range rather than hash: every report filters a period and retention removes a
time range, so partitioning on the reports' own filter column makes both a
partition operation. A month rather than a day or a quarter: it is the unit §9
names, it is what the reports round to, and it keeps the partition count in the
tens rather than the hundreds for years of data.

**The primary key becomes `(id, occurred_at)`**, because PostgreSQL requires the
partition key in every unique constraint. This is not bookkeeping: the
idempotency of a redelivered click message rests on that key. A `ClickRecorded`
message is immutable and carries both values, so a redelivery still collides and
is still acknowledged — but the guarantee is now about the pair, and the test
that proves it redelivers a message from an earlier month, where two rows would
otherwise land in different partitions.

**The retention window is 13 months**, configured by `CLICK_RETENTION_MONTHS`.
Thirteen rather than twelve so that a year-over-year comparison is still
possible on the last month of the window.

**Nothing deletes click data on its own.** `app:clicks:partitions --retention`
drops every partition whose whole range is older than the cutoff; a partition
that straddles the cutoff is kept, and its older rows with it. Dropping a
partition is a catalogue operation, where `DELETE` on a month of a large table
rewrites it and then needs a vacuum — that is the whole reason the table is
partitioned.

**The same command provisions the months a click may legitimately fall in**, the
window back and `CLICK_PARTITION_HORIZON_MONTHS` forward. A click whose month has
no partition cannot be inserted, so this is correctness rather than tidiness.

## What retention does to the published numbers

This is the part §9 asks an ADR to state, because it changes what a number
means.

- **The summary's all-time figures are over the retained history.**
  `totalClicks`, `uniqueVisitors`, `firstClickAt` and `lastClickAt` are computed
  with no period bound. Once a month is dropped they describe what survives, and
  the `analytics` specification says so where the figures are defined.
- **A returning visitor whose earlier clicks were dropped counts as new.**
  Unique visitors are distinct `visitor_hash` values among the rows that exist.
  Inside the retained history the count is exact; across the boundary it is not,
  and no amount of care makes it so once the rows are gone.
- **`links.click_count` and the summary's `totalClicks` diverge, on purpose.**
  The counter is incremented per click and never decremented: it answers "how
  many clicks has this link ever had". The summary answers "how many clicks does
  this service still hold for it". After a drop the first is larger, and they
  are not reconciled, because reconciling them would mean either rewriting
  history or losing it twice.
- **The boundary of what has been removed is recorded and never moves
  backwards.** The window is configuration and can be lengthened; the removal is
  a fact. Without the record, widening the window would re-provision a dropped
  month, a replayed message would pass the age guard, and the lifetime counter
  would be incremented a second time for a click that already counted.

## Alternatives considered

- **`DELETE FROM clicks WHERE occurred_at < …` on a schedule.** Rejected: it
  rewrites rows, leaves bloat for `VACUUM`, and takes locks proportional to the
  data instead of to the catalogue. It also has no natural boundary — a partial
  delete leaves a half-removed month.
- **Keeping everything for ever.** Rejected by §9, and by what the numbers cost:
  the table is 192 MB of rows and 539 MB of indexes at a million clicks.
- **Hash partitioning by `link_id`.** Rejected: it spreads rows evenly, which
  helps nothing here — no report filters by a hash bucket and retention is a
  time range.
- **A `click_daily` aggregate instead of partitioning.** Not rejected —
  deferred, again. §9 lists it separately, and it answers a different question
  (report latency, not storage). What partitioning did to the reports is
  measured in `docs/how-to/benchmarks.md`.
- **Recreating a dropped month to absorb a late message.** Rejected explicitly:
  it destroys the only evidence that the click was already counted.

## Consequences

- A month of clicks leaves in the time a `DROP TABLE` takes.
- Reports that filter a period read only the partitions that period touches.
  The all-time figures still touch every partition of the link.
- The conversion of an existing table is a copy: 3.93 s for 1 030 279 rows, with
  clicks unwritable for that time. A deployment that cannot pause needs a
  different procedure than this migration.
- Somebody has to run `app:clicks:partitions`. If the horizon lapses, inserts
  fail loudly — the message is retried and then parked, not silently dropped —
  and the how-to carries the cron line.
- The schema is one the ORM does not model; `doctrine:schema:validate` disagrees
  with it, as it already did for two unrelated reasons. `make migrations-roundtrip`
  is what guards the migrations instead.

## Supersedes

No ADR clause. ADR-002's boundary is unchanged: the write path still writes by
SQL and the read path still reads by SQL, and neither hydrates a click.
