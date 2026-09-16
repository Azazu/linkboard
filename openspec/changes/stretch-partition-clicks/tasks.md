# Tasks — stretch-partition-clicks

Tier `high`: an irreversible migration that converts a populated table, which
`AGENTS.md` and NFR-SEC-7 both name as a trigger. Gate 1 on the artifacts before
any code, Gate 2 on the diff, and a demonstrated failing input for every new
guard.

`src/Analytics/` is untouched — §3.4 promises the readers do not change, and the
diff is the evidence. `phpstan.dist.neon`, `.github/workflows/ci.yml` and the
round-trip script stay as `harden-gate-floor` left them; this change has to pass
that floor, not move it.

## 1. Gate 1

- [x] 1.1 Request Gate 1 on the artifacts (`scripts/gate-run.sh stretch-partition-clicks 1 full`) and disposition every finding before section 2 starts. Verify: the last Gate 1 record in `review.md` reads `confirmed` with no finding row left `open` — round 1 raised three major and one minor, Confirmation 1 returned findings 1 and 2, and Confirmation 2 (`be353c1`) confirmed all four.

## 2. The migration

- [x] 2.1 The conversion migration `migrations/Version20260916120000.php`: rename the table aside, create the partitioned parent with `PRIMARY KEY (id, occurred_at)`, create `clicks_ensure_partition(month date)`, create a partition for every month of the retention window, every month up to the horizon and every month present in the data, copy the rows, recreate the two indexes and the foreign key, drop the legacy table (design decisions 3 and 5). Verify, executed: `make migrate` applied it to the local database — **1 030 279 rows before and 1 030 279 after**, 17 partitions from `clicks_2025_08` to `clicks_2026_12`, the data in `clicks_2026_07` (227 550), `clicks_2026_08` (517 210) and `clicks_2026_09` (285 519), which sum to the row count.
- [x] 2.2 A freshly migrated, empty database accepts everything the project writes to it (Gate 1 round 1, finding 1). Verify, executed on a scratch database created from nothing: the migrations apply and leave 17 partitions, `app:demo:seed --clicks=100 --days=60` succeeds, and an insert dated **2026-03-28** — the suite's oldest click fixture — succeeds. Checked on a fresh database rather than on the populated one the conversion was measured on, because the populated one already had the historical months.
- [x] 2.3 The `down` is a real inverse. Verify, executed: `make migrations-roundtrip` reports `up: 388 schema objects`, only the declared survivors after a full down, and `the round trip reproduced the schema exactly` — the fingerprint includes the partitions' own columns, indexes and constraints.
- [x] 2.4 The conversion's cost is a number, not an adjective. Verify, executed and recorded: `time bin/console doctrine:migrations:migrate` on the 1 030 279-row database — **3.93 s** (`real 0m4.09s` including the console's own boot). Clicks cannot be written for that time: the rename and the copy hold an exclusive lock, and the redirect does not wait on it because the write is already asynchronous.
- [x] 2.5 `clicks_ensure_partition` is the only implementation of how a partition is named and bounded. Verify, executed: `rg -n 'clicks_[0-9]{4}_[0-9]{2}|PARTITION OF' src/ migrations/` returns exactly one line — the `CREATE TABLE … PARTITION OF` inside the function. `tests/Integration/Click/ClickPartitionsCommandTest::testTheMigrationsInitialProvisioningUsesTheCommandsDefaults` pins the one thing that is written twice: the migration's literal 13 and 3 against `ClickRetention::DEFAULT_MONTHS` and `DEFAULT_HORIZON_MONTHS`.

## 3. The entity and the write path

- [x] 3.1 `src/Click/Entity/Click.php` carries `occurred_at` as the second `#[ORM\Id]` (design decision 2). Verify, executed: `doctrine:schema:validate --skip-sync` reports `The mapping files are correct`, and `make stan` is green at level 9.
- [x] 3.2 The handler gains exactly one guard — the expiry-boundary check of design decision 5b — and its insert, its transaction and its two exception guards are untouched; the idempotency survives the key change. Verify, executed: `git diff main -- src/Click/Handler/ClickRecordedHandler.php` shows the guard, the two new constructor arguments and the docblock, and nothing else; `tests/Integration/Click/ClickRecordedHandlerTest.php` passes with its existing cases untouched plus a new one that handles a message from two months ago twice — one row, one increment, and `tableoid::regclass` naming that month's partition.
- [x] 3.3 The demonstrated failing input for the horizon: a click **inside the window** whose month has no partition. Verify, executed: `testAClickInsideTheWindowWithNoPartitionIsRetriedNotSwallowed` drops a partition inside the window and asserts the handler throws a DBAL exception carrying `no partition of relation`, that `links.click_count` is unchanged, and that nothing was logged as discarded — so it reaches the transport's retry policy rather than either of the two guards.
- [x] 3.4 A message older than the expiry boundary is discarded, not parked (design decision 5b; Gate 1 round 1, finding 2). Verify, executed: a data provider covers a first delivery dated 20 months back and a retry from the failed transport 14 months back; a third case handles a message, drops its month, records the boundary and redelivers it. Each asserts the handling returns normally, no row is written, `links.click_count` is unchanged by it, and one `info` record carries the link id and the click id. Two further cases pin the distinctions: a message inside the window with no partition is **retried**, and a message for a **deleted link** whose month has no partition is retried too, then discarded as a deleted link once the partition exists (Gate 1 confirmation 1, finding 2).
- [x] 3.5 Widening the retention window does not resurrect a dropped click, and no handler can straddle a retention run (Gate 1 confirmation 1, finding 2; Gate 2 round 1, finding 2). Verify, executed: `testWideningTheWindowDoesNotResurrectADroppedClick` records the click, drops its month under a two-month window, recreates the month, then replays under a thirteen-month window — no row, and the counter keeps its single increment. And the race the sequential test cannot reach: `testTheHandlerWaitsWhileRetentionIsDroppingRatherThanDecidingAgainstAStaleSchema` holds retention's exclusive advisory lock on a second connection, gives the handler a 250 ms `lock_timeout`, and asserts the handler fails waiting rather than deciding — with no row written and nothing counted. Demonstrated failing input: removing the shared lock from the handler makes it insert straight through, and the test says so.

## 4. The maintenance command

- [x] 4.1 `app:clicks:partitions` creates every missing partition from the first month of the retention window to `now + horizon` (horizon default 3, both from environment variables), and additionally for any month in which a record already exists; it is idempotent and prints only what it created (design decision 5; Gate 1 confirmation 1, finding 1). Verify, executed: `testItCreatesTheWindowAndTheHorizonAndIsIdempotent` asserts the window's first month, the current month and the horizon are all present and that a second run prints `nothing to create` and changes no partition; `testItRecreatesAMissingHistoricalPartition` drops a month six months back and asserts the command recreates it and names it.
- [x] 4.2 The same command drops every partition whose entire range is older than `now - window`, names each one, and keeps a partition that straddles the boundary (design decision 6). Verify, executed: with clicks four months, two months and one day old and a window of two months, the four-month partition is gone and named in the output, the month the cutoff falls in keeps its older click, and two of the three clicks survive.
- [x] 4.3 Retention is never automatic. Verify, executed: `rg -n 'DROP TABLE clicks|clicks:partitions' src/` returns one line — the command's own name in its `#[AsCommand]` — so no controller, handler or subscriber can reach a drop; and `testNothingIsDroppedWithoutTheRetentionOption` asserts a run without `--retention` drops nothing, records no boundary and says so.
- [x] 4.4 The window and the horizon are configuration, not constants. Verify, executed: both are declared in `.env` with their defaults and read through `#[Autowire(env: …)]`; the command tests drive it with windows of 1, 2, 4 and 13 months, and `ClickRetention` is tagged `app.startup_check`, so a value the application cannot parse stops it booting rather than reaching the click handler — `CLICK_RETENTION_MONTHS=0 bin/console list` fails with the setting's name.
- [x] 4.5 Dropping records how far the data has been removed — **the end of the last month actually removed**, not the configured cutoff — in the same transaction as the drops, and that record never moves backwards (design decisions 5b and 6; Gate 2 round 1, finding 1). Verify, executed: `testTheRecordedBoundaryIsTheEndOfTheLastMonthActuallyRemoved` asserts the exact date is the first day of the month the run kept; `testAClickInTheSurvivingStraddlingMonthIsStillRecordableAfterWidening` asserts a first delivery from that surviving month is not behind the boundary; `testTheBoundaryNeverMovesBackwards` asserts a later thirteen-month run leaves the record alone. Demonstrated failing input: recording the cutoff again fails both of the first two.
- [x] 4.6 The configuration is validated before any statement that changes the schema (Gate 1 round 1, finding 3). Verify, executed: a data provider drives `0`, `-1`, an empty value, `soon`, `1.5` and ` 3` through each of the two settings — twelve cases — and each asserts a non-zero exit, a message naming that setting, an unchanged set of partitions, an unchanged row count and no recorded boundary. A window of `0` is the case worth spelling out: it puts the cutoff at this instant and would make the current, populated month eligible for dropping.

## 5. What retention does to the numbers

- [x] 5.0 "Retained" means present, not "inside the window" (Gate 1 round 1, finding 4). Verify, executed: `testWithoutARetentionRunEveryFigureIsOverTheWholeHistory` asserts a ten-month-old click still counts where the command has never run, and `testAMonthTheCutoffFallsInKeepsItsOlderRows` asserts a month the cutoff falls inside keeps its older click and the summary counts it.
- [x] 5.1 The summary's all-time figures over a retained history (`analytics` delta). Verify, executed: `testAfterAMonthIsDroppedTheAllTimeFiguresAreOverWhatSurvives` asserts 200, `totalClicks` counting only the surviving click, and `firstClickAt` being the oldest retained click rather than the link's first ever.
- [x] 5.2 The lifetime counter and the summary diverge on purpose. Verify, executed: the same test asserts `links.click_count` is unchanged by retention and is now greater than the summary's `totalClicks` — the two answer different questions, which the ADR states.
- [x] 5.3 A returning visitor whose earlier clicks were dropped counts as new. Verify, executed: `testAVisitorWhoseEarlierClicksWereDroppedCountsAsNew` asserts two unique visitors before retention, and two again afterwards — one surviving and one returning visitor whose earlier clicks are gone, counted as new. That is the effect §9 asks the ADR to name.

## 6. The reports do not change

- [x] 6.1 `src/Analytics/` is untouched. Verify, executed: `git diff main --stat -- src/Analytics/` is empty.
- [x] 6.2 Every report returns what it returned. Verify, executed: the whole suite passes on the partitioned table — **931 tests, 22 190 assertions** — with `tests/Api/Analytics` and `tests/Integration/Analytics` unchanged; their fixtures span 2026-03 to 2026-09, so the reports are read across partition boundaries.
- [x] 6.3 The fixed-date fixtures do not expire with the calendar (Gate 1 confirmation 1, finding 1). Verify, executed: `make test-db` provisions `CLICK_FIXTURE_FROM`…`CLICK_FIXTURE_TO` (2026-01-01 to 2027-01-01, declared once in the `Makefile`) through the same `clicks_ensure_partition` function; `tests/Integration/Click/FixtureMonthsTest.php` asserts the suite's oldest fixture date inserts and that no click fixture in the tree is dated before the declared range — naming what that scan cannot see, a date built from a variable or a call spread over lines.

## 7. Documents

- [x] 7.1 `docs/adr/ADR-006-clicks-partitioning-and-retention.md`: the partition key and granularity, the window and why 13 months, the effect on unique visitors across the boundary, the lifetime counter's divergence, the recorded removal boundary, and what partitioning costs. Verify: `docs/adr/README.md` gains its row and every link resolves (checked by `scripts/pregate-verify.sh`).
- [x] 7.2 `docs/explanation/requirements.md` §3.4 says the table is partitioned and by what — with the composite key and the reason — and §9's stretch list records the item as done with a link to the ADR. Verify, executed: the file re-read around both edits; `rg -n 'Partitioning by month is a stretch|optional table partitioning' docs/` returns nothing stale.
- [x] 7.3 `docs/how-to/local-development.md` and `docs/reference/commands.md` carry the command, the two environment variables and a cron line for the job. Verify, executed: both commands run in the exact form the documents print them — `make console ARGS='app:clicks:partitions'` reports the horizon provisioned and no month dropped, and `make console ARGS='app:clicks:partitions --retention'` reports `Nothing to drop: no month lies entirely before 2025-08-16…`, leaving all 1 030 279 clicks and an empty boundary table, because the data is three months old against a thirteen-month window.

## 8. The measurement

- [x] 8.1 The benchmark is re-run as published, on a million clicks, after the conversion. Verify, executed three times: `docs/how-to/benchmarks.md` section 2 carries the new table and says plainly what partitioning did — **nothing beyond each report's own run-to-run spread**, because the dataset's whole history is 60 days and of seventeen partitions only three hold rows, so a 30-day period has almost nothing to prune. The missed `devices` report still misses (316.8 ms against 324.3 before); the global report `top-links` sits on 300.0 ms and is the noisy one (426.8 / 251.0 / 300.0 across the three runs). The recipe gained `ANALYZE clicks`, because a partitioned table keeps statistics per partition and the 426.8 ms run was made on empty ones — measuring the planner's ignorance rather than the schema. The section states that the answer to report latency remains the `click_daily` aggregate this plan left out.
- [x] 8.2 The README's benchmark rows match. Verify, executed: the README re-read whole after the edit; the rows now read `8 of 9 reports, p95 64–300 ms` and `a link's device breakdown — p95 317 ms — missed`, and the paragraph beneath says what partitioning did and why a 60-day dataset cannot show it.

## 9. Wrap-up

- [x] 9.1 `make check` green inside the container with no environment override — **931 tests, 22 190 assertions**; `openspec validate stretch-partition-clicks --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated with the measured conversion time, the row counts and the benchmark numbers.
- [x] 9.2 Green Actions run on the exact branch head: the user pushes; the executor queries `https://api.github.com/repos/Azazu/linkboard/actions/runs?branch=change/stretch-partition-clicks` until the run for `git rev-parse HEAD` is `completed` / `success`; URL and SHA recorded in `handoff.md`. Verify, executed: run **35138605772** on `629ae79`, `completed`/`success`, all four jobs green — `detect`, `workflow`, `php` and `migrations`, the last being what proves the conversion **and its `down`** work on a PostgreSQL this machine did not set up.

## After every task above is complete — the gate, not a task

Gate 2 is requested once section 9 is done, and it is deliberately not a
checkbox: `scripts/gate-run.sh` runs `scripts/pregate-verify.sh` first, and that
floor rejects any unchecked task. The lifecycle steps are:

1. `scripts/gate-run.sh stretch-partition-clicks 2 full`.
2. Fix every finding, update its Status in `review.md`, and re-review with
   `scripts/gate-run.sh stretch-partition-clicks 2 confirm <round>`.
3. The gate has passed when the last Gate 2 record reads `approved` or
   `confirmed` with no finding row left `open`; `scripts/workflow-verify.sh
   merge stretch-partition-clicks` is what checks that before the merge.
4. After the user merges: `scripts/workflow-verify.sh archive
   stretch-partition-clicks`, then the archive commit on `main`, which is where
   row 14 leaves `openspec/ROADMAP.md`.
