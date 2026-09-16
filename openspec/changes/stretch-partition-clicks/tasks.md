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

- [ ] 1.1 Request Gate 1 on the artifacts (`scripts/gate-run.sh stretch-partition-clicks 1 full`) and disposition every finding before section 2 starts. Verify: the last Gate 1 record in `review.md` reads `approved` or `confirmed` with no finding row left `open`.

## 2. The migration

- [ ] 2.1 The conversion migration: rename the table aside, create the partitioned parent with `PRIMARY KEY (id, occurred_at)`, create `clicks_ensure_partition(month date)`, create a partition for every month present in the data and for the current month, copy the rows, recreate the two indexes and the foreign key, drop the legacy table (design decision 3). Verify: `make migrate` applies it on the local database, which holds the seeded million rows, and the row count before equals the row count after — both recorded here.
- [ ] 2.2 The `down` is a real inverse. Verify: `make migrations-roundtrip` passes — every `down` runs, only the declared survivors stand after a full down, and the schema fingerprint after `down`+`up` equals the one before, with the partitions in it.
- [ ] 2.3 The conversion's cost is a number, not an adjective. Verify: the time the migration takes on the million-row dataset is measured with `time` and recorded here, together with the fact that clicks cannot be written while it runs.
- [ ] 2.4 `clicks_ensure_partition` is the only implementation of how a partition is named and bounded. Verify: `rg -n 'clicks_[0-9]{4}_[0-9]{2}|PARTITION OF' src/ migrations/` shows the naming in the function and nowhere else.

## 3. The entity and the write path

- [ ] 3.1 `src/Click/Entity/Click.php` carries `occurred_at` as the second `#[ORM\Id]` (design decision 2). Verify: `make stan` green at level 9 and the mapping check of `doctrine:schema:validate --skip-sync` still reports the mapping correct.
- [ ] 3.2 The handler is unchanged and its idempotency survives the key change. Verify: `tests/Integration/Click/` — the existing redelivery test passes untouched, and a new case redelivers a message whose `occurred_at` is in an earlier month, asserting one row, one increment, and the row in that month's partition.
- [ ] 3.3 The demonstrated failing input for the horizon: a click whose month has no partition. Verify, executed: the insert raises `no partition of relation "clicks" found`, which is neither of the handler's two guards, so the counter is unchanged and the exception propagates to the transport — asserted, with the recorded message.

## 4. The maintenance command

- [ ] 4.1 `app:clicks:partitions` creates every missing partition from the current month to `now + horizon` (default 3, from an environment variable), is idempotent, and prints only what it created (design decision 5). Verify: an integration test runs it twice — the second run creates nothing and prints nothing created.
- [ ] 4.2 The same command drops every partition whose entire range is older than `now - window` (default 13 months, from an environment variable), names each one, and keeps a partition that straddles the boundary (design decision 6). Verify: an integration test with a partition wholly outside, one straddling and one inside asserts exactly which are gone and that the output names them.
- [ ] 4.3 Retention is never automatic. Verify: `rg -n 'DROP TABLE clicks_|clicks:partitions' src/` shows the drop only inside the command, reachable from no controller, no handler and no kernel subscriber.
- [ ] 4.4 The window and the horizon are configuration, not constants. Verify: both are read from environment variables declared in `.env` with the defaults, and an integration test drives the command with a different window.

## 5. What retention does to the numbers

- [ ] 5.1 The summary's all-time figures over a retained history (`analytics` delta). Verify: an API test with clicks in a month that is then dropped asserts 200, `totalClicks` and `uniqueVisitors` counting only what survives, and `firstClickAt` being the oldest retained click.
- [ ] 5.2 The lifetime counter and the summary diverge on purpose. Verify: the same test asserts `links.click_count` is unchanged by retention while the summary's `totalClicks` falls, and the ADR says which number answers which question.
- [ ] 5.3 A returning visitor whose earlier clicks were dropped counts as new. Verify: the test asserts the unique-visitor count before and after the drop, which is the effect §9 asks the ADR to name.

## 6. The reports do not change

- [ ] 6.1 `src/Analytics/` is untouched. Verify: `git diff main --stat -- src/Analytics/` is empty at Gate 2.
- [ ] 6.2 Every report returns what it returned. Verify: `tests/Api/Analytics` and `tests/Integration/Analytics` pass unchanged, and a report over a period spanning two months returns the same numbers as before the conversion.

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
