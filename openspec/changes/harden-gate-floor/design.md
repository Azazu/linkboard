# Design — harden-gate-floor

## Context

See `proposal.md` — Why. What the design has to work with, measured on
2026-09-16 rather than remembered:

- PHPStan at level 9 reports **361 errors: 67 in `src`, 294 in `tests`** across
  42 test files. In `src` they are `cast.int` (35), `cast.string` (14),
  `argument.type` (9), `cast.double` (6) and `return.type` (3); 54 of the 67 sit
  in the five classes of `src/Analytics/Query/`, which read DBAL rows. In
  `tests` the shape is `offsetAccess.nonOffsetAccessible` (123) —
  `json_decode(...)['token']` — plus `argument.type` (70) and casts.
- All five migrations have a `down()`. Four drop what they created; the fifth,
  `Version20260910142101`, deliberately does not: `messenger_messages` is shared
  with the failed transport, which parks messages in its own transactions, and
  the class comment argues that a conditional drop would race one.
- `doctrine:schema:validate` fails today, for reasons unrelated to
  reversibility: `doctrine:schema:update --dump-sql` wants to recreate the
  partial index `idx_clicks_link_occurred_human` (`WHERE NOT is_bot`) and to
  rename `idx_messenger_messages_queue` to the transport's generated name.
- The round trip itself was rehearsed before this design was written, on a
  scratch database: up → down to `first` → up again leaves the schema
  fingerprint byte-identical (`987a2130…` both times), and the only tables left
  after the full down are `doctrine_migration_versions` and
  `messenger_messages`.

## Goals / Non-Goals

**Goals:**

- One analysis level for the whole repository, stated in one sentence and true.
- `mixed` leaving the database or an HTTP response gets converted where its type
  is checked, not cast where it is assumed.
- A migration whose `down` is wrong fails CI, and the failure names what differs.
- Everything CI runs here, a developer can run locally with one `make` target.

**Non-Goals** (beyond `proposal.md` — Non-goals):

- Not a rewrite of the analytics queries: the SQL is unchanged, only how its
  result is read.
- Not a general-purpose hydration layer. The row reader converts four scalar
  shapes and refuses everything else; a query needing more grows the reader in
  the change that needs it.
- The round trip does not test data migration. Every `down` here drops or keeps
  a table; none rewrites rows, so there is no data to preserve and the job says
  so rather than implying coverage it does not have.

## Decisions

### 1. One level, no baseline, no per-path exemption

`phpstan.dist.neon` moves `level: 8` to `level: 9` over both `src` and `tests`,
and all 361 findings are fixed.

*Alternatives considered.* A **baseline file** (`phpstan-baseline.neon`) would
have made the change a one-line edit and left 361 findings frozen in a file
nobody reads — the opposite of a floor. **Two configs**, `src` at 9 and `tests`
at 8, was the user's second option and is defensible, but it makes "what level
is this project at?" a two-part answer. **Level 9 with the five level-9
identifiers ignored under `tests/`** is the same exemption wearing a
suppression's clothes. The user chose the whole project on 2026-09-16, knowing
the test diff is 294 findings across 42 files.

*What this does not guarantee.* Level 9 is about `mixed`; it is not level 10,
and `treatPhpDocTypesAsCertain: false` stays, so a wrong PHPDoc is still only
as good as the code that wrote it.

### 2. `src` reads DBAL rows through a typed reader

A new `src/Shared/Db/Row` converts one column of an `array<string, mixed>` row:
`int()`, `float()`, `string()` and `nullableString()`. Each accepts the shapes
PostgreSQL actually returns through PDO — an `int` or a numeric string for a
number, a string for text, `null` only where the signature says so — and throws
an exception naming the column and the type it got for anything else.

*Why a reader and not a PHPDoc shape.* `@return array{clicks: int, share:
float}` on the fetch helper would satisfy PHPStan and prove nothing: PHPStan
cannot read the SQL, so the annotation is an assertion by the author about a
string. The reader is checked at the moment the value arrives.

*Why it matters beyond the analyser.* `(int) $row['clicks']` on a `null` is `0`.
A report that silently shows zero clicks for a column the query stopped
returning is the failure mode this replaces with an exception.

*What this does not guarantee.* It validates a value, not a query: a column that
returns the wrong number correctly typed still passes. And it is a runtime
check, so a path no test covers is unproven — which is why the conversions get
their own unit test with the rejected shapes.

### 3. `tests` read decoded bodies through a typed accessor

`tests/Support/Json` decodes a response body and reads one key at a time,
built on `PHPUnit\Framework\Assert`. `Json::string($body, 'token')` fails as an
assertion naming `token` rather than returning `null` into the next line.

*Why not the same reader as `src`.* A test wants a failed assertion, not an
exception from production code: the message, the counted assertion and the
diff belong to PHPUnit.

*What this does not guarantee.* It types what a test reads; it does not make
the test check more than it checked before. Each file's conversion is a
type-level edit — if a test was asserting nothing useful, it still is.

### 4. The migration round trip is a fingerprint comparison, not `schema:validate`

`make migrations-roundtrip` runs, against a database it creates and owns:

1. resolve a scratch name — the configured database plus `_roundtrip_` and eight
   random hex characters — and refuse to continue if it somehow equals the
   configured one,
2. create it with `CREATE DATABASE`, issued over the configured connection —
   PostgreSQL's own uniqueness is the exclusivity, so a name that already
   exists fails here, before any migration and before any drop, and this run
   owns the database if and only if that statement succeeded,
3. `doctrine:migrations:migrate latest` — every `up`,
4. fingerprint the schema,
5. `doctrine:migrations:migrate first` — every `down`,
6. assert the tables left are exactly the declared set,
7. `doctrine:migrations:migrate latest` — every `up` again,
8. fingerprint again; the two listings must be identical,
9. drop the scratch database — from a `trap`, so a failure at any step still
   cleans up, and only when step 2 recorded that this run created it.

The fingerprint is one sorted text listing built by SQL over
`information_schema.columns`, `pg_indexes` and `pg_constraint`: every column
with its type, nullability and default, every index definition, every
constraint definition. Step 8 compares the two listings with `diff`, so a
failure names the line that differs.

*Why a fresh name per run rather than a fixed `<db>_roundtrip`.* A fixed name
plus a defensive initial drop destroys an unrelated database that happens to
carry that name, and two invocations against the same configured database — a
developer and a CI job, or two CI jobs — would drop each other's schema
mid-run (Gate 1 round 1, finding 2). A per-run name makes a collision unlikely;
it is not what makes one safe.

*What makes a collision safe: the create is exclusive, and ownership follows
from it.* `CREATE DATABASE` is atomic and fails on a name that exists —
measured on 2026-09-16: the second `bin/console dbal:run-sql 'CREATE DATABASE
…'` exits `7` with `already exists`. So the script does not check whether the
name is free and then take it; it takes it, and a failure means the name was
somebody else's. Nothing is migrated and nothing is dropped on that path.
`doctrine:database:create` is deliberately not used for this: it reports an
existing database as a notice and exits `0`, which would turn a collision into
a silent adoption — exactly the case this has to refuse. The equality guard
against the configured name and the printed name stay, but they are hints for a
human, not the isolation.

*What a crash leaves behind.* A `trap` covers every exit including a failed
step, and it drops exactly one database: the one whose `CREATE DATABASE`
returned success in this process. Only a signal the shell cannot handle
(SIGKILL, a pulled plug) leaves a database, and because that leftover's name is
never proposed again and would fail the exclusive create if it were, it is
inert rather than something the next run silently deletes. The script prints
the name before creating it, so the leftover is identifiable, and removing it
is a deliberate human command — this design would rather leak a scratch
database than drop one it does not own.

*Two runs that overlap in time.* Each owns a different database and each
`trap` names only its own, so the one that finishes first drops only its own
and the other keeps running against a database the first never names. That is a
claim with a demonstrated failing input rather than an argument: the stub suite
holds one run live inside its migration step while a second completes, then
asserts which databases were dropped and by which run.

*Why not `doctrine:schema:validate`.* It answers a different question — does the
mapping match the database — and it fails today for two reasons that have
nothing to do with reversibility (Context). Wiring it in would either import
that unrelated failure or require fixing it here, and `proposal.md` puts that
out of scope.

*Why a declared set of surviving tables rather than "none".* Step 6 would fail
today on `messenger_messages`, whose `down` deliberately keeps it. Asserting
emptiness would force that argued decision to be undone to make a check green.
The check instead names the survivors and why, so a future migration that keeps
a table has to say so in the same place.

*What this does not guarantee.* It proves the schema round-trips on an empty
database. It does not prove a `down` preserves data, and no migration here
rewrites rows; it does not prove the mapping agrees with the migrations; and a
migration that is wrong in both directions symmetrically passes it.

### 4a. The fingerprint SQL is one file, and it is tested against real PostgreSQL

The query lives in `scripts/schema-fingerprint.sql` and is read by both the
shell script and `tests/Integration/Db/SchemaFingerprintTest.php`, so there is
one authority for what "the schema" means here.

The test is the part the stub suite cannot do (Gate 1 round 1, finding 3). A
stub that returns a different listing proves the shell compares two strings; it
proves nothing about whether the SQL would have noticed. So the test creates a
throwaway schema in the test database, points `search_path` at it — the query is
written against `current_schema()`, which is what makes that possible — and
takes a fingerprint before and after each of the three categories the listing
promises to cover:

- an index added and dropped,
- a column's nullability, default and type changed,
- a constraint added and dropped.

Each case asserts the two fingerprints differ **and** that the differing line
names the object. Together they are the reason removing any one of the three
extractions from the SQL turns a test red rather than leaving a check that
passes on everything. DDL in PostgreSQL is transactional and
`dama/doctrine-test-bundle` wraps each test in a transaction, so the throwaway
schema disappears with the rollback.

*What this does not guarantee.* The listing covers columns, indexes and
constraints; it does not cover sequences, triggers, functions, comments or
grants, and a migration that changes only one of those round-trips silently.
That is a stated limit of the check, not an oversight, and the test names the
categories it covers so the limit is visible where the promise is.

### 5. Its own CI job, its own database

A `migrations` job in `.github/workflows/ci.yml` with its own `postgres`
service, running the same `make` target with `EXEC=`.

*Why not a step in the `php` job.* That job's database is load-bearing: the
prod-kernel health tests read `DATABASE_URL` as it is and need `api_keys` there,
and `make test-db` derives the test database from the same server. A job that
drops and recreates schemas has no business sharing it, and a separate job also
says which of the two failed without reading the log.

### 6. The demonstrated failing inputs are a permanent suite, not a one-off plant

`scripts/gate_run_test.sh` and `scripts/workflow_verify_test.sh` already
establish the pattern this repository uses for shell checks: a throwaway
repository, stubbed binaries on `PATH`, one demonstrated failing input per rule,
and CI runs every `scripts/*_test.sh` in the `workflow` job with no database in
sight. The round trip gets the same treatment —
`scripts/migrations_roundtrip_test.sh` drives the real script against a fixture
`bin/console` that can be told to fail a `down`, to return a different
fingerprint the second time, or to leave a table behind, and asserts the script
fails with a message naming the difference.

*Why a stub rather than a planted migration.* A planted migration proves the
rule once, in a session, and is recorded in prose; the stub proves it on every
push, and it can produce failures a real migration cannot easily be made to
produce on demand — a `down` that exits non-zero, a database that already
exists, a second listing that differs.

*What the stub suite is not allowed to be asked.* It exercises orchestration:
ordering, exit codes, cleanup, ownership. Whether the fingerprint SQL actually
sees an index is a question only real PostgreSQL can answer, and decision 4a is
where that is answered. Three layers, three jobs: the stub suite proves the
script's control flow, `SchemaFingerprintTest` proves the query's coverage, and
the CI job of decision 5 proves this repository's own migrations round-trip.

### 7. The documents are corrected in the same change

`NFR-QA-1` and the two other places in `docs/explanation/requirements.md` that
say "level 8", `docs/how-to/local-development.md`'s floor table,
`openspec/config.yaml` (the analysis level, and the `Stage: scaffold` line the
user folded in on 2026-09-16), and `openspec/ROADMAP.md`. One authority per
statement: the level is a fact about `phpstan.dist.neon`, and the documents
point at what it is rather than each carrying a number of its own where that is
possible.

## Applicability

| Question | Answer |
|---|---|
| Crash before/after an external effect | The target creates a scratch database and drops it from a `trap`, so every exit path including a failed step cleans up. A signal the shell cannot handle leaves the database behind; because the name carries eight random characters and is never reused, the next run is unaffected and nothing deletes a database this run did not create. The name is always the configured one plus a suffix, and the target refuses if the two are somehow equal. |
| Empty/zero/null inputs | The heart of decision 2. `Row::int()` on `null` must throw rather than return `0`; on an empty result set the query returns no rows and the reader is never called; a numeric string is accepted because that is what PDO returns for `bigint` and `numeric`. Each of these is a unit test. |
| Deletion/expiry | The round trip drops a database and every table its migrations created. It drops only the database whose `CREATE DATABASE` this run issued successfully; an existing database of that name — empty or not — fails the create and aborts the run untouched. The name is printed before creation. |
| Idempotency of retries | `make migrations-roundtrip` must converge when run twice in a row, including after a failed run that left the scratch database behind. Verified by running it twice and by running it after an interrupted one. |
| Authorization boundary | n/a for a decision, but named because the diff touches it: `DoctrineApiKeyRepository` and `DoctrineUserRepository` return `mixed` today and get typed returns. No lookup, no voter and no firewall rule changes; the tests that cover those boundaries are the evidence. |
| Concurrent writers | Two invocations against the same configured database — a developer and CI, or two CI jobs — used to mean two runs dropping and recreating one `<db>_roundtrip` under each other (Gate 1 round 1, finding 2). Isolation now comes from an atomic `CREATE DATABASE`: a run owns exactly the database its own create returned success for, and drops exactly that. The stub suite holds one run live while another completes and asserts each dropped only its own, and a separate case asserts a create collision aborts with no migration and no drop. |
| Money rounding | n/a — no money in this project. |

## Risks / Trade-offs

- **A large mechanical diff in `tests/` hides a real change inside it** →
  one commit per test group (`Api`, `Integration`, `Unit`, `Web`, `Support`)
  with `make check` green at each, so the reviewer reads five uniform commits
  rather than one of 42 files.
- **A level-9 fix can mask a bug instead of exposing it** — casting `mixed` to
  the expected type silences the analyser and changes nothing → in `src` every
  conversion goes through the reader, which fails on what it cannot convert; in
  `tests` every conversion goes through an assertion. No `@phpstan-ignore` and
  no `assert()` without a message.
- **The round trip adds a CI job and roughly a minute of wall clock** → accepted:
  it runs in parallel with `php`, and it is the only evidence that the
  specification's reversibility claim is true.
- **The scratch database could collide with one that already exists, or with
  another run** → the name carries eight random characters, so a collision is
  unlikely; `CREATE DATABASE` is what makes one safe, because it fails on an
  existing name and nothing is migrated or dropped on that path; and the script
  refuses if the resolved name equals the configured one (Gate 1 round 1,
  finding 2, and its confirmation).
- **A killed run leaks a scratch database** → accepted deliberately: the
  alternative is a script that deletes a database it does not own. The name is
  printed before creation, so a leaked one is identifiable and removing it is a
  human decision.
- **A check that passes on everything is worse than no check** — a fingerprint
  SQL missing a category would leave both the stub suite and this repository's
  own round trip green (Gate 1 round 1, finding 3) → `SchemaFingerprintTest`
  runs the real query against real PostgreSQL for each promised category, and
  removing an extraction from the query is executed and recorded as a failing
  input.
- **Raising the level makes every later change pay for it** → that is the point,
  and it is why this is `high` tier and a change of its own rather than a line
  inside row 13.
