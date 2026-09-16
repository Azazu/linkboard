# Design — stretch-partition-clicks

## Context

See `proposal.md` — Why. What the design has to work with, read from the source
rather than remembered:

- `clicks` is created by `migrations/Version20260909123249.php` as an ordinary
  table with `PRIMARY KEY (id)`, an FK `link_id → links(id) ON DELETE CASCADE`,
  the composite index `(link_id, occurred_at)` and the partial index
  `(link_id, occurred_at) WHERE NOT is_bot`.
- The write path is `src/Click/Handler/ClickRecordedHandler.php`: one
  transaction, `Connection::insert('clicks', …)` then the counter increment,
  with two guards — `UniqueConstraintViolationException` means a redelivery and
  is logged at debug, `ForeignKeyConstraintViolationException` means the link is
  gone and is logged at info. **The first guard is the idempotency
  requirement's whole mechanism**, and it currently rests on the key being `id`.
- `src/Click/Entity/Click.php` is `readOnly: true` and is named by nothing but
  `tests/Unit/Architecture/AnalyticsKeepsItsDistanceTest.php`: no repository,
  no fixture factory, no `find()`. It exists so the schema tooling knows the
  table.
- Every analytics query reads the table by SQL (`src/Analytics/Query/*.php`),
  and the summary's `total`, `uniques`, `first_at` and `last_at` are computed
  with **no period bound** — they are the all-time figures the proposal calls
  breaking.
- `harden-gate-floor` added `make migrations-roundtrip`, which runs every `down`
  and compares a schema fingerprint. This change's migration has to survive it.

## Goals / Non-Goals

**Goals:**

- A month of clicks can be removed in the time it takes to drop a table.
- Reports read exactly what they read today, and prove it by passing unchanged.
- The horizon is a correctness property with a test, not an operational habit.
- Retention's effect on published numbers is written down where the numbers are
  defined, not only in an ADR.

**Non-Goals** (beyond `proposal.md` — Non-goals):

- Not an online conversion. The migration copies the rows with the table locked;
  a deployment that cannot take that pause needs a different procedure, and this
  design says so rather than pretending.
- Not automatic partition creation on insert. A trigger that creates a partition
  under a failing insert hides the horizon instead of maintaining it.

## Decisions

### 1. Range partitioning by month on `occurred_at`

`PARTITION BY RANGE (occurred_at)`, one partition per calendar month in UTC,
named `clicks_YYYY_MM`, bounds `[first day of the month, first day of the next)`.

*Why range and not hash.* Hash partitioning spreads rows evenly and buys
nothing here: every report filters a time period and retention removes a time
range. Range on the reports' own filter column is what makes both a partition
operation.

*Why a month.* §9 names it. It is also the unit the reports round to
(`date_trunc('day'|'hour')` inside a period that is at most 366 days), and it
keeps the partition count in the tens for years rather than the hundreds.

*What this does not guarantee.* Pruning helps a query whose period is narrower
than the table; it does nothing for the all-time figures of the summary, which
still touch every partition of the link. Whether it moves the one missed report
is measured in section 8 of the tasks, not claimed here.

### 2. The primary key becomes `(id, occurred_at)`

PostgreSQL requires every unique constraint on a partitioned table to contain
the partition key.

*What that costs, precisely.* Uniqueness is now on the pair. A redelivered
`ClickRecorded` carries the same `click_id` **and** the same `occurred_at` —
it is an immutable DTO, serialized once — so the collision still happens and the
handler's `UniqueConstraintViolationException` guard still fires. Two rows with
one `click_id` and different timestamps would be possible in principle and are
unreachable through the transport; the requirement is reworded to say the pair,
and the scenario that proves it is a redelivery of a message from an earlier
month, where the two rows would land in different partitions if the guarantee
were lost.

*The entity.* `Click` gains `occurred_at` as a second `#[ORM\Id]`. Nothing
resolves a `Click` by identifier, so nothing else moves; the architecture test
that forbids `src/Analytics/` from naming the entity keeps passing because the
analytics code is untouched.

### 3. Converting a populated table: rename, create, copy, drop

```
ALTER TABLE clicks RENAME TO clicks_legacy;          -- keeps the rows
CREATE TABLE clicks (…) PARTITION BY RANGE (occurred_at);
SELECT clicks_ensure_partition(month) for every month in the data, and the current month;
INSERT INTO clicks SELECT … FROM clicks_legacy;
DROP TABLE clicks_legacy;
```

*Why not `ATTACH` the existing table as one partition.* It avoids the copy, but
the attached table must already carry a `CHECK` matching the bounds or
PostgreSQL scans it to validate, and its primary key would have to be the new
composite one — which is the rewrite the copy does anyway. One clear mechanism
beats a clever one that ends in the same place.

*The `down` is a real inverse*, not a stub: it recreates the ordinary table with
`PRIMARY KEY (id)`, copies every row back out of the partitions, and drops the
partitioned table and the function. `make migrations-roundtrip` runs it on an
empty database, so the copy is trivial there; on a populated one it is the same
`INSERT … SELECT` in reverse.

*What is locked and for how long.* The rename and the copy hold an exclusive
lock: no click is written for the duration. Measured on the local million-row
dataset during apply and recorded in the tasks — a number, not an adjective.

### 4. One implementation of "ensure a partition", in SQL

The migration creates `clicks_ensure_partition(month date) RETURNS boolean` and
calls it; the maintenance command calls the same function. There is no second
place that knows how a partition is named and bounded.

*Why not build the DDL in PHP.* This series has been bitten twice by two
implementations of one rule agreeing by inspection until they did not — the test
passphrase in `harden-quality-and-docs`, and the scratch database name in
`harden-gate-floor`. A function in the schema is the version of "shared" that a
migration and a command can both reach.

*What this does not guarantee.* The fingerprint of `schema-fingerprint.sql`
covers columns, indexes and constraints — **not functions**, which it says
plainly. A changed function body round-trips silently; the function's behaviour
is covered by tests instead.

### 5. The horizon is a correctness property

`app:clicks:partitions` (name settled in the tasks) creates every missing
partition from the current month to `now + horizon` months, default 3,
configured by environment variable. It is idempotent and prints only what it
created.

*What happens without it, stated because it is the failure mode:* an insert
into a month with no partition raises `no partition of relation "clicks" found`
— not a unique violation, not a foreign key violation, so **neither guard
catches it**. The handler lets it propagate, the transport retries, and the
message ends in the failure transport rather than being acknowledged as a
duplicate. That is the correct behaviour and the test asserts exactly it: the
click is not lost silently, the counter is not incremented, and the message is
retried.

### 6. Retention: whole months, on demand, with a declared window

The same command drops every partition whose **entire** range is older than
`now - window`, default 13 months so that a year-over-year comparison is still
possible, configured by environment variable. A partition that straddles the
boundary is kept and named. Nothing else in the application drops click data.

*Why dropping and not `DELETE`.* Dropping a partition is a catalogue operation;
`DELETE` on a month of a large table rewrites and then needs a vacuum. That is
the whole reason the table is partitioned.

*What retention changes that is visible.* The summary's all-time figures become
figures over the retained history — written into the `analytics` spec, not only
into the ADR — and `links.click_count`, which is a lifetime counter incremented
per click and never decremented, will exceed the summary's `totalClicks` once a
month is dropped. The two numbers answer different questions and the ADR says
which is which rather than making them agree by accident.

### 7. The ADR

`docs/adr/ADR-006-clicks-partitioning-and-retention.md`: the partition key and
granularity, the window and why 13 months, the effect on unique-visitor counts
across the boundary, the lifetime counter's divergence, and what partitioning
costs (a planning step per partition, a maintenance command that must run, a
schema the ORM's tooling does not model).

## Applicability

| Question | Answer |
|---|---|
| Crash before/after an external effect | The conversion is one migration in one transaction — PostgreSQL's DDL is transactional, so a crash mid-copy leaves the original table under its original name and Doctrine's version row unwritten. The retention drop is also transactional per run: a crash leaves the partitions it had not dropped. Both are re-runnable, which is the property the tests assert. |
| Concurrent writers | A click arriving during the conversion waits on the exclusive lock and is written afterwards, to the partitioned table; the redirect never waits on it because the write is already asynchronous. A click arriving for a month retention is dropping is a click outside the window — the only way to lose a live write is a window shorter than the clock skew, which the command refuses by keeping a straddling month. |
| Deletion/expiry | The heart of this change. Retention is deletion, it is irreversible, and it runs only when the command runs: the window is declared, a straddling month is kept, every dropped partition is named in the output, and nothing in the request path or the worker can trigger it. |
| Idempotency of retries | Two senses, both tested: the maintenance command is safe to run repeatedly (creates nothing the second time, drops nothing the second time), and the handler's redelivery guarantee survives the key change — that is decision 2's scenario. |
| Empty/zero/null inputs | An empty `clicks` table at migration time (a fresh database, which is what CI runs) creates the current month's partition and nothing else; a link with no retained clicks answers the summary with zeros and nulls, which is the existing scenario the spec keeps. |
| Authorization boundary | n/a — no endpoint, no role, no voter changes. The command is a console command, reachable only by whoever can run the container. |
| Money rounding | n/a. |

## Risks / Trade-offs

- **Pruning may not move the missed report** → the change publishes the measured
  numbers either way; the `click_daily` aggregate stays where §9 put it, and the
  README's one miss stays a miss rather than acquiring an explanation.
- **A partitioned table is a schema the ORM does not model** →
  `doctrine:schema:validate` already disagrees with this schema for two
  unrelated reasons (`harden-gate-floor`, Non-goals); this adds a third. The
  round trip, not `schema:validate`, is what guards the migrations.
- **The migration is time-dependent**: which partitions it creates depends on
  the data and on the current month → within one `make migrations-roundtrip` run
  both `up`s see the same month, so the comparison holds; across a month
  boundary a database migrated in January and one migrated in February differ by
  a partition, which is what the maintenance command exists to even out.
- **A horizon that is never maintained ends in lost clicks** — after the
  retries — → the failure is loud (the transport's failure queue, an error
  record), the command is documented with a cron line, and the test proves the
  loud failure rather than a silent one.
- **One more moving part in the demo** → the seed writes 60 days of history,
  which is inside the window and inside the partitions the migration creates
  from the data; the benchmark recipe is re-run as part of this change, so if
  that is wrong it fails there.
