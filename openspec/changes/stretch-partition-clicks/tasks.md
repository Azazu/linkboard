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

- [ ] 2.1 The conversion migration: rename the table aside, create the partitioned parent with `PRIMARY KEY (id, occurred_at)`, create `clicks_ensure_partition(month date)`, create a partition for **every month of the retention window, every month up to the horizon, and every month present in the data**, copy the rows, recreate the two indexes and the foreign key, drop the legacy table (design decisions 3 and 5). Verify: `make migrate` applies it on the local database, which holds the seeded million rows, and the row count before equals the row count after — both recorded here.
- [ ] 2.2 A freshly migrated, empty database accepts everything the project writes to it (Gate 1 round 1, finding 1). Verify, executed on a database created from nothing: the migrations apply, then `app:demo:seed --clicks=1000 --days=60` succeeds, and the analytics fixtures — whose oldest click is **2026-03-28**, about six months back against a 13-month window — insert without error. This is checked on a fresh database, not on the populated one the conversion was measured on, because the populated one has the historical months already.
- [ ] 2.3 The `down` is a real inverse. Verify: `make migrations-roundtrip` passes — every `down` runs, only the declared survivors stand after a full down, and the schema fingerprint after `down`+`up` equals the one before, with the partitions in it.
- [ ] 2.4 The conversion's cost is a number, not an adjective. Verify: the time the migration takes on the million-row dataset is measured with `time` and recorded here, together with the fact that clicks cannot be written while it runs.
- [ ] 2.5 `clicks_ensure_partition` is the only implementation of how a partition is named and bounded. Verify: `rg -n 'clicks_[0-9]{4}_[0-9]{2}|PARTITION OF' src/ migrations/` shows the naming in the function and nowhere else.

## 3. The entity and the write path

- [ ] 3.1 `src/Click/Entity/Click.php` carries `occurred_at` as the second `#[ORM\Id]` (design decision 2). Verify: `make stan` green at level 9 and the mapping check of `doctrine:schema:validate --skip-sync` still reports the mapping correct.
- [ ] 3.2 The handler gains exactly one guard — the expiry-boundary check of design decision 5b — and its insert, its transaction and its two exception guards are untouched; the idempotency survives the key change. Verify: `git diff main -- src/Click/Handler/ClickRecordedHandler.php` shows the guard and nothing else, `tests/Integration/Click/` — the existing redelivery test passes untouched, and a new case redelivers a message whose `occurred_at` is in an earlier month, asserting one row, one increment, and the row in that month's partition.
- [ ] 3.3 The demonstrated failing input for the horizon: a click **inside the window** whose month has no partition. Verify, executed: the insert raises `no partition of relation "clicks" found`, which is neither of the handler's two exception guards, so the counter is unchanged and the exception propagates to the transport — asserted, with the recorded message.
- [ ] 3.4 A message older than the expiry boundary is discarded, not parked (design decision 5b; Gate 1 round 1, finding 2). Verify: an integration test covers the three ways it arrives — a redelivery after the record's month was dropped, a first delivery dated before the window, and a retry from the failed transport — asserting for each that the handling is acknowledged, no record is written, `links.click_count` is unchanged by it, and the discard is logged with the link id and the click id. Two further cases pin the distinctions: a message inside the window with no partition is **retried**, not discarded; and a message for a **deleted link** whose month has no partition is retried too, then discarded as a deleted link on the attempt after the partition exists (Gate 1 confirmation 1, finding 2).
- [ ] 3.5 Widening the retention window does not resurrect a dropped click (Gate 1 confirmation 1, finding 2). Verify, executed: a month is dropped under a short window, the window is then configured long enough that the month is provisioned again, and a message from that month is replayed — the test asserts it is acknowledged and discarded, no row is written, and `links.click_count` is unchanged. Without the recorded boundary this case records the click a second time, which is what makes it the demonstrated failing input for the guard.

## 4. The maintenance command

- [ ] 4.1 `app:clicks:partitions` creates every missing partition **from the first month of the retention window to `now + horizon`** (horizon default 3, both from environment variables), and additionally for any month in which a record already exists; it is idempotent and prints only what it created (design decision 5; Gate 1 confirmation 1, finding 1). Verify: an integration test drops a historical partition inside the window and asserts the command **recreates it**, that the months up to the horizon exist afterwards, and that a second run creates nothing and prints nothing created.
- [ ] 4.2 The same command drops every partition whose entire range is older than `now - window` (default 13 months, from an environment variable), names each one, and keeps a partition that straddles the boundary (design decision 6). Verify: an integration test with a partition wholly outside, one straddling and one inside asserts exactly which are gone and that the output names them.
- [ ] 4.3 Retention is never automatic. Verify: `rg -n 'DROP TABLE clicks_|clicks:partitions' src/` shows the drop only inside the command, reachable from no controller, no handler and no kernel subscriber.
- [ ] 4.4 The window and the horizon are configuration, not constants. Verify: both are read from environment variables declared in `.env` with the defaults, and an integration test drives the command with a different window.
- [ ] 4.5 Dropping records how far the data has been removed, in the same transaction as the drops, and that record never moves backwards (design decisions 5b and 6). Verify: an integration test asserts the boundary after a run, that a later run with a longer window leaves it where it is, and — the crash case — that no committed state has the partitions dropped without the boundary moved or the boundary moved without the partitions dropped.
- [ ] 4.6 The configuration is validated before any statement that changes the schema (Gate 1 round 1, finding 3). Verify, one demonstrated failing input per case, executed: a window of `0`, of `-1`, of an empty value and of a non-number, and the same four for the horizon — each makes the command exit non-zero naming the setting, and the test asserts that the set of partitions and the row counts are **identical** to what they were before the run. A window of `0` is the case worth spelling out: it puts the cutoff at this instant and would make the current, populated month eligible for dropping.

## 5. What retention does to the numbers

- [ ] 5.0 "Retained" means present, not "inside the window" (Gate 1 round 1, finding 4). Verify: a test asserts a month that straddles the cutoff keeps its older rows and the summary counts them, and that on a deployment where the command has never run every figure is over the whole history — the two cases where the window and what is retained deliberately differ.
- [ ] 5.1 The summary's all-time figures over a retained history (`analytics` delta). Verify: an API test with clicks in a month that is then dropped asserts 200, `totalClicks` and `uniqueVisitors` counting only what survives, and `firstClickAt` being the oldest retained click.
- [ ] 5.2 The lifetime counter and the summary diverge on purpose. Verify: the same test asserts `links.click_count` is unchanged by retention while the summary's `totalClicks` falls, and the ADR says which number answers which question.
- [ ] 5.3 A returning visitor whose earlier clicks were dropped counts as new. Verify: the test asserts the unique-visitor count before and after the drop, which is the effect §9 asks the ADR to name.

## 6. The reports do not change

- [ ] 6.1 `src/Analytics/` is untouched. Verify: `git diff main --stat -- src/Analytics/` is empty at Gate 2.
- [ ] 6.2 Every report returns what it returned. Verify: `tests/Api/Analytics` and `tests/Integration/Analytics` pass unchanged, and a report over a period spanning two months returns the same numbers as before the conversion.
- [ ] 6.3 The fixed-date fixtures do not expire with the calendar (Gate 1 confirmation 1, finding 1). Verify: `make test-db` provisions the declared fixture range through the same `clicks_ensure_partition` function — the range named in one place — so a suite whose oldest click is 2026-03-28 keeps passing when that date falls outside the production retention window; a test asserts a click at the oldest declared fixture date inserts on a freshly created test database.

## 7. Documents

- [ ] 7.1 `docs/adr/ADR-006-clicks-partitioning-and-retention.md`: the partition key and granularity, the window and why 13 months, the effect on unique visitors across the boundary, the lifetime counter's divergence, and what partitioning costs. Verify: `docs/adr/README.md` gains its row and every link resolves.
- [ ] 7.2 `docs/explanation/requirements.md` §3.4 says the table is partitioned and by what, §9's stretch list records the item as done, and the summary's definition matches the `analytics` delta. Verify: the file re-read whole after the edit; `rg -n 'partitioning is a stretch|optional table partitioning' docs/` returns nothing stale.
- [ ] 7.3 `docs/how-to/local-development.md` and `docs/reference/commands.md` carry the command, the two environment variables and a cron line for the job. Verify: every command in them run in its exact form.

## 8. The measurement

- [ ] 8.1 The benchmark is re-run as published, on a million clicks, after the conversion. Verify: `docs/how-to/benchmarks.md` section 2 carries the new table, and the change states what pruning did to each report — including whether the missed `devices` report (p95 324 ms) still misses.
- [ ] 8.2 The README's benchmark rows match. Verify: the README re-read whole after the edit; a target that is still missed is still published as missed.

## 9. Wrap-up

- [ ] 9.1 `make check` green inside the container with no environment override; `openspec validate stretch-partition-clicks --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated with the measured conversion time, the row counts and the benchmark numbers.
- [ ] 9.2 Green Actions run on the exact branch head: the user pushes; the executor queries `https://api.github.com/repos/Azazu/linkboard/actions/runs?branch=change/stretch-partition-clicks` until the run for `git rev-parse HEAD` is `completed` / `success`; URL and SHA recorded in `handoff.md`. Verify: all four jobs green, the `migrations` job among them — which is what proves the conversion and its `down` work on a database this machine did not set up.

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
