# Tasks — harden-gate-floor

Tier `high`: this change edits `phpstan.dist.neon` and
`.github/workflows/ci.yml`, the floor and the verifier every later change is
judged by, which `AGENTS.md` names as a trigger. Gate 1 on the artifacts before
any implementation, Gate 2 on the diff, and a demonstrated failing input for
every new check.

`docker-compose.yml`, `.env` and the migrations themselves stay untouched: the
round trip exists to test the migrations as they are, and a change that edited
them to make its own check pass would prove nothing.

## 1. Gate 1

- [x] 1.1 Request Gate 1 on the artifacts (`scripts/gate-run.sh harden-gate-floor 1 full`) and disposition every finding before section 2 starts. Verify: the last Gate 1 record in `review.md` reads `confirmed` with no finding row left `open` — round 1 raised three major and one minor, Confirmation 1 returned finding 2, and Confirmation 2 (`6cf163d`) confirmed all of them.

## 2. The typed row reader, and `src` at level 9

- [x] 2.1 `src/Shared/Db/Row.php`: `int()`, `float()`, `string()`, `nullableString()` and `nullableFloat()` read one column of an `array<string, mixed>` row, accept the shapes PDO returns for PostgreSQL (int or decimal string for an integer, float/int/numeric string for a number, string for text, `null` only where the signature allows it) and throw `App\Shared\Db\UnexpectedColumnValue` naming the column and the type received for anything else (design decision 2). Verify, executed: `tests/Unit/Shared/Db/RowTest.php` — 21 tests, 58 assertions — covers each accepted shape and 16 rejected ones, including `int` of `null` (the cast that used to return `0`), `int` of `'1.5'`, `string` of an `int` (no silent formatting), and a missing column through every reader; the message assertions pin the column name, and one case pins that a present-but-null column is a different message from a missing one.
- [x] 2.2 The five classes of `src/Analytics/Query/` read every row value through the reader instead of casting; `BreakdownQuery` gained a `total()` helper so that "no rows" is the one place a zero is the answer rather than a cast over a missing column. Verify, executed: `vendor/bin/phpstan analyse --level=9 src/Analytics` reports `No errors`, and `tests/Api/Analytics tests/Integration/Analytics` pass unchanged (66 tests, 663 assertions with the reader's own tests) — the SQL is untouched, so an unchanged number means the reading kept its meaning.
- [x] 2.3 The remaining `src` findings, each a narrowing at the boundary rather than a cast: `src/Shared/Db/OneResult.php` narrows what `getOneOrNullResult()` returns for the two Doctrine repositories (a runtime check, so it holds in production too, not a docblock assertion); the three API Platform sites narrow `$context['request']` with `instanceof Request`; `ListQueryFactory` refuses an `order` whose key is not a field name or whose value is not a word instead of stringifying it; `LinkPages`' three form parameters are typed `FormInterface<LinkFormData>` instead of `<mixed>`; `RulesDocumentMapper` builds a `list<string>|null` and falls back to raw mode; `DemoSeedCommand` throws on a link id that is not a string. Verify, executed: `vendor/bin/phpstan analyse --level=9 src` reports `No errors`; `tests/Unit/Shared/Db/OneResultTest.php` and `tests/Unit/Web/Link/RulesDocumentMapperTest.php` cover the two new behaviours, `tests/Api/Link/ListLinksTest.php` gains `order[0]=desc` and `order[createdAt][]=desc` as 400s, and the whole suite passes (878 tests, 11 182 assertions).
- [x] 2.4 Nothing was silenced. Verify, executed: `git diff main -- src/` contains no added `@phpstan-ignore`, `@psalm` or `@phpstan-var` line, and `phpstan.dist.neon` still carries exactly the one `ignoreErrors` entry it had before this change (the Symfony 8 reflection hook on `src/Kernel.php`).

## 3. The typed JSON accessor, and `tests` at level 9

- [x] 3.1 `tests/Support/Json.php`: `decode()`/`decodeList()` for a body and `string()`, `int()`, `float()`, `bool()`, `nullableString()`, `map()`, `items()` and `objects()` for one field, each asserting through `PHPUnit\Framework\Assert` so a missing or wrong-typed field fails naming the field (design decision 3). Verify, executed: `tests/Unit/Support/JsonTest.php` — 13 tests, 142 assertions — checks every reader's return type and nine failure modes, each asserting the message names the field (`"nope" is present`, `"tags" is an object, not a list`), plus a body that is a list, a body that is a bare string and a response with no body at all. No `assert()` and no `@phpstan-var`: the two readers that cannot be expressed as a single PHPUnit assertion narrow with `Assert::fail()`, which returns `never`.
- [ ] 3.2 `tests/Api` converted (157 findings, 20 files). Verify: `vendor/bin/phpstan analyse --level=9 tests/Api` clean and the suite green; committed as its own commit.
- [ ] 3.3 `tests/Integration` converted (70 findings). Verify: as above, its own commit.
- [ ] 3.4 `tests/Unit` converted (49 findings, almost all in `tests/Unit/Link/Rules/RulesSchemaParityTest.php`). Verify: as above, its own commit.
- [ ] 3.5 `tests/Web` and `tests/Support` converted (18 findings). Verify: as above, its own commit.
- [ ] 3.6 The conversion changed no assertion's meaning, and the numbers are explained rather than expected to match (Gate 1 round 1, finding 4). Verify: the accessor asserts as it reads, so the assertion count **must** rise — recorded here are the test count before and after (unchanged except for the tests this change adds, named), the assertion count before and after, and the number of accessor calls introduced, so the delta is accounted for rather than assumed away. Each converted file is additionally checked for the original assertions still being present: `git diff` for the group shows no removed `self::assert` line that was not replaced by an accessor reading the same value.

## 4. The level

- [ ] 4.1 `phpstan.dist.neon` moves to `level: 9` over `src` and `tests`, with no baseline file and no new `ignoreErrors` entry (design decision 1). Verify: `make stan` green at the new level, and `rg -n 'level' phpstan.dist.neon` shows one level.

## 5. The migration round trip

- [ ] 5.1 `scripts/migrations-roundtrip.sh`: the nine steps of design decision 4 against a scratch database named for the configured one plus `_roundtrip_` and eight random hex characters, POSIX `sh`, printing the name before creating it. Verify: `sh -n scripts/migrations-roundtrip.sh` passes (CI's `workflow` job runs that over every script), and the run against the real database is recorded in task 5.3.
- [ ] 5.2 Ownership comes from an exclusive create, not from a check (Gate 1 round 1, finding 2 and its confirmation): the scratch database is taken with `CREATE DATABASE` over the configured connection, which fails atomically on a name that already exists — measured, `bin/console dbal:run-sql 'CREATE DATABASE …'` exits `7` with `already exists` the second time — and `doctrine:database:create` is deliberately not used, because it reports an existing database as a notice and exits `0`. A failed create aborts before any migration and any drop; the `trap` drops exactly the database whose create succeeded in this process, and never the configured one. Verify: demonstrated in tasks 7.5, 7.6 and 7.7.
- [ ] 5.3 The fingerprint SQL lives in `scripts/schema-fingerprint.sql` — one sorted listing from `information_schema.columns`, `pg_indexes` and `pg_constraint`, written against `current_schema()` so it can be pointed at any schema — and the shell script reads that file rather than carrying its own copy. Verify: `rg -n 'information_schema' scripts/` shows the query in exactly one file, and `tests/Integration/Db/SchemaFingerprintTest.php` reads the same file.
- [ ] 5.4 `make migrations-roundtrip` runs it through the usual `EXEC` indirection (the container locally, natively in CI). Verify, executed and recorded: the target run against the real database reports identical fingerprints and drops its scratch database; two runs in a row both pass and operate on different database names; a run interrupted after creation leaves a database the next run neither needs nor touches.
- [ ] 5.5 The tables that survive a full `down` are declared, not assumed empty: `doctrine_migration_versions` and `messenger_messages`, the latter with the reason its migration gives (design decision 4). Verify: the script names both and fails on any other survivor — demonstrated in task 7.3.

## 6. CI

- [ ] 6.1 A `migrations` job in `.github/workflows/ci.yml` with its own `postgres` service, running `make migrations-roundtrip EXEC=`, separate from the `php` job whose database the health tests depend on (design decision 5). Verify: the job appears as its own check on the branch run and is green, with the run id and SHA recorded in `handoff.md`.
- [ ] 6.2 The job does not disturb the existing ones. Verify: the `workflow`, `detect` and `php` jobs are unchanged in the diff, and the branch run shows all four green.

## 7. The demonstrated failing inputs

Two layers, because one cannot do the other's job (design decisions 4a and 6):
the stub suite proves the script's control flow, and a real-PostgreSQL test
proves the fingerprint SQL sees what the listing promises.

- [ ] 7.1 `scripts/migrations_roundtrip_test.sh` in the shape `scripts/gate_run_test.sh` established: a throwaway repository, a fixture `bin/console` on the script's path, one case per rule, no database. Verify: CI's `workflow` job already runs every `scripts/*_test.sh`, and the suite is green there.
- [ ] 7.2 A second listing that differs fails the round trip and the message names the differing line. Verify: the fixture returns a different listing the second time; the suite asserts a non-zero exit and that the diff is printed. This case proves the comparison, **not** the SQL — task 7.8 is what proves the SQL.
- [ ] 7.3 An unexpected surviving table fails the round trip. Verify: the fixture leaves a table the declared set does not name; the suite asserts the failure names that table.
- [ ] 7.4 A failing `down` fails the round trip rather than being skipped, and the scratch database is still dropped. Verify: the fixture exits non-zero on the down step; the suite asserts the script's exit code and that the drop was issued for the database it created.
- [ ] 7.5 A create collision aborts the run, whatever is in the existing database. Verify: the fixture makes `CREATE DATABASE` fail with `already exists` — once for an existing **empty** database and once for one with tables — and the suite asserts, for both, a non-zero exit, a message naming the database, **no migration command issued** and **no drop issued**.
- [ ] 7.6 Two overlapping runs drop only their own database. Verify: the suite starts one run whose fixture blocks inside the migration step on a sentinel file, runs a second to completion against the same configured database, and asserts from the fixture's command log that the second dropped only the name its own create returned, that the first's database was never named by the second, and that releasing the first leaves it dropping only its own.
- [ ] 7.7 The guard against operating on the configured database, and against dropping one this run did not create. Verify: one case supplies a `DATABASE_URL` whose derived name equals the configured one and asserts the script refuses having issued nothing; another makes the create fail and asserts the `trap` issues no drop.
- [ ] 7.8 `tests/Integration/Db/SchemaFingerprintTest.php` against real PostgreSQL (Gate 1 round 1, finding 3): in a throwaway schema with `search_path` pointed at it, the fingerprint changes when an index is added or dropped, when a column's nullability, default or type changes, and when a constraint is added or dropped — each case asserting the differing line names the object. Verify: removing any one of the three extractions from `scripts/schema-fingerprint.sql` turns a case red; the removals are executed and the failures recorded, then restored.
- [ ] 7.9 The limits of the listing are stated where the promise is. Verify: `scripts/schema-fingerprint.sql` and the test name what the fingerprint does not cover — sequences, triggers, functions, comments, grants — so nobody reads a green round trip as "the schema is identical in every respect".

## 8. The documents say what the floor is

- [ ] 8.1 `docs/explanation/requirements.md`: NFR-QA-1's analysis level, the tooling table and the summary table (three places, measured by `rg -n 'level 8' docs/`), plus the reversibility claim of section 5 now naming what proves it. Verify: `rg -n 'level 8' README.md docs/ openspec/ AGENTS.md` returns nothing but historical references in archived changes.
- [ ] 8.2 `openspec/config.yaml`: the analysis level, and the `Stage: scaffold; application code arrives through OpenSpec changes` line the user folded into this change on 2026-09-16 — the text every agent, Codex at the gates included, receives as project context. Verify: the file re-read whole after the edit; `rg -n 'scaffold' openspec/config.yaml` returns nothing.
- [ ] 8.3 `docs/how-to/local-development.md`'s floor table and `docs/reference/commands.md` gain the new target and the new level. Verify: both re-read whole after the last edit; every command in them run in its exact form.
- [ ] 8.4 `docs/explanation/requirements.md`'s stage-plan row for 13a is reconciled with what was actually done (the row's exit criterion names the raised level and the reversibility check). Verify: the row read against this change's tasks after the last edit; the roadmap row itself is removed at archive time, which is a lifecycle step below, not a task — a task that could only be checked after the archive commit can never be checked before Gate 2, which is the deadlock `scripts/pregate-verify.sh` and `scripts/workflow-verify.sh` create by design (Gate 1 round 1, finding 1).

## 9. Wrap-up

- [ ] 9.1 `make check` green inside the container with no environment override, at level 9; `openspec validate harden-gate-floor --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated with the counts before and after and the recorded round-trip output.
- [ ] 9.2 Green Actions run on the exact branch head: the user pushes; the executor queries `https://api.github.com/repos/Azazu/linkboard/actions/runs?branch=change/harden-gate-floor` until the run for `git rev-parse HEAD` is `completed` / `success`; URL and SHA recorded in `handoff.md`. Verify: the run's `head_sha` equals the branch head and all four jobs are green.

## After every task above is complete — the gate, not a task

Gate 2 is requested once section 9 is done, and it is deliberately not a
checkbox: `scripts/gate-run.sh` runs `scripts/pregate-verify.sh` first, and that
floor rejects any unchecked task — so a task that included "run Gate 2 and
disposition its findings" could never be both truthful and satisfied
(`harden-quality-and-docs`, Gate 1 round 1, finding 1). The lifecycle step is:

1. `scripts/gate-run.sh harden-gate-floor 2 full`.
2. Fix every finding, update its Status in `review.md`, and re-review with
   `scripts/gate-run.sh harden-gate-floor 2 confirm <round>`.
3. The gate has passed when the last Gate 2 record reads `approved` or
   `confirmed` with no finding row left `open`; `scripts/workflow-verify.sh
   merge harden-gate-floor` is what checks that before the merge.
4. After the user merges: `scripts/workflow-verify.sh archive harden-gate-floor`,
   then the archive commit on `main`, which is where row 13a leaves
   `openspec/ROADMAP.md`. That removal is deliberately not a task either, for
   the same reason: it happens after every task must already be checked.
