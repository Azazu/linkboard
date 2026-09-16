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

`make migrations-roundtrip` runs, against a database of its own:

1. create the scratch database (name = the configured one plus `_roundtrip`),
2. `doctrine:migrations:migrate latest` — every `up`,
3. fingerprint the schema,
4. `doctrine:migrations:migrate first` — every `down`,
5. assert the tables left are exactly the declared set,
6. `doctrine:migrations:migrate latest` — every `up` again,
7. fingerprint again; the two fingerprints must be identical,
8. drop the scratch database.

The fingerprint is one sorted text listing built by SQL over
`information_schema.columns`, `pg_indexes` and `pg_constraint`: every column
with its type, nullability and default, every index definition, every
constraint definition. Step 7 compares the two listings with `diff`, so a
failure names the line that differs.

*Why not `doctrine:schema:validate`.* It answers a different question — does the
mapping match the database — and it fails today for two reasons that have
nothing to do with reversibility (Context). Wiring it in would either import
that unrelated failure or require fixing it here, and `proposal.md` puts that
out of scope.

*Why a declared set of surviving tables rather than "none".* Step 5 would fail
today on `messenger_messages`, whose `down` deliberately keeps it. Asserting
emptiness would force that argued decision to be undone to make a check green.
The check instead names the survivors and why, so a future migration that keeps
a table has to say so in the same place.

*What this does not guarantee.* It proves the schema round-trips on an empty
database. It does not prove a `down` preserves data, and no migration here
rewrites rows; it does not prove the mapping agrees with the migrations; and a
migration that is wrong in both directions symmetrically passes it.

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
produce on demand. The real end-to-end run against a real PostgreSQL still
happens — that is the CI job of decision 5 — so neither replaces the other: the
job proves the migrations, the suite proves the check.

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
| Crash before/after an external effect | The round-trip target creates and drops a scratch database. A crash between them leaves `<db>_roundtrip` behind; the target drops it **before** creating it as well as after, so a rerun converges rather than failing on the leftover. It never touches the configured database: the name is always the configured one plus a suffix. |
| Empty/zero/null inputs | The heart of decision 2. `Row::int()` on `null` must throw rather than return `0`; on an empty result set the query returns no rows and the reader is never called; a numeric string is accepted because that is what PDO returns for `bigint` and `numeric`. Each of these is a unit test. |
| Deletion/expiry | The round trip drops a database and drops every table its migrations created. Scoped by construction to the `_roundtrip` database, and the target prints which database it is operating on before it does anything. |
| Idempotency of retries | `make migrations-roundtrip` must converge when run twice in a row, including after a failed run that left the scratch database behind. Verified by running it twice and by running it after an interrupted one. |
| Authorization boundary | n/a for a decision, but named because the diff touches it: `DoctrineApiKeyRepository` and `DoctrineUserRepository` return `mixed` today and get typed returns. No lookup, no voter and no firewall rule changes; the tests that cover those boundaries are the evidence. |
| Concurrent writers | n/a — the round trip owns its database and nothing else writes to it. |
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
- **The scratch database name could collide with something a developer has** →
  it is always `<configured>_roundtrip`, printed before use and dropped at both
  ends; the target refuses to run if the resolved name equals the configured one.
- **Raising the level makes every later change pay for it** → that is the point,
  and it is why this is `high` tier and a change of its own rather than a line
  inside row 13.
