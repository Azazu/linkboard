# Proposal — harden-gate-floor

**Risk-Tier:** high

## Why

`make check` is the floor every change in this repository is judged by, and two
of its promises are weaker than they read. PHPStan runs at level 8, which lets
`mixed` flow into casts unchecked — measured today, 361 places would fail at
level 9, and 54 of the 67 in `src` are the analytics DBAL queries casting row
values they never verified, where a NULL from a mistyped column becomes `0` in
a published report instead of an error. And nothing anywhere runs a migration
backwards: all five have a `down()`, none has ever been executed, so "reversible
migrations" is a claim in the specification with no evidence behind it.

Both belong together and apart from row 13: they change the floor and the
verifier, which `AGENTS.md` makes a `high` trigger, which is why the user split
them out of `harden-quality-and-docs` on 2026-09-15 rather than let a `low`
change edit them.

## What Changes

- **PHPStan moves to level 9 over `src` and `tests`.** Not a baseline file and
  not a per-path exemption: one level, everywhere, with the 361 findings fixed.
  The user chose the whole project over `src` alone on 2026-09-16.
- **The analytics read model stops casting `mixed`.** The five query classes in
  `src/Analytics/Query/` read DBAL rows through a typed reader that fails loudly
  on a value it cannot convert, instead of `(int) $row['clicks']` on an unknown.
- **The API tests stop indexing `mixed`.** A shared typed accessor for decoded
  response bodies replaces `json_decode(...)['token']`, so a missing or
  wrong-typed field fails as an assertion naming the field rather than as a
  silent `null`.
- **CI gains a migration round-trip job.** Every migration up, then every
  migration down, then every migration up again, on a database of its own — and
  the schema after the round trip must be identical to the schema before it,
  compared field by field, not eyeballed. `make migrations-roundtrip` runs the
  same thing locally.
- **The specification and the documents are corrected to what the floor is.**
  NFR-QA-1 and the two other places that say "level 8", and `openspec/config.yaml`,
  which still tells every agent — Codex at the gates included — that the project
  is at the `scaffold` stage where "application code arrives through OpenSpec
  changes". The user folded that one-line correction into this change on
  2026-09-16.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

None. `.openspec.yaml` declares `skip_specs: true`: no capability's behaviour
changes. The statements this change alters — NFR-QA-1's analysis level and the
reversibility claim — live in `docs/explanation/requirements.md`, the brief,
not in `openspec/specs/`, and they are corrected there as tasks of this change.

## Non-goals

- **No behaviour change.** Every edit under `src/` is a type-correctness fix
  with the same observable result for valid data; the only new behaviour is that
  invalid data now fails loudly where it used to be cast silently. Any place
  where that distinction is not obvious gets a test.
- **Not the mapping-versus-migration question.** `doctrine:schema:validate`
  reports the database out of sync with the mapping today, for two reasons that
  have nothing to do with reversibility: the partial index
  `idx_clicks_link_occurred_human` (`WHERE NOT is_bot`), which Doctrine's
  comparator wants to drop and recreate on every run, and the Messenger
  transport's own expectation of a generated name for
  `idx_messenger_messages_queue`. Measured on 2026-09-16 with
  `doctrine:schema:update --dump-sql`. The round-trip job deliberately does not
  use `schema:validate`; closing that gap is a separate change with its own
  evidence.
- **No new dependency.** Everything here is PHPStan's own level, Doctrine's own
  migration commands and SQL against `information_schema`.
- **Not deptrac.** NFR-QA-2 already allows it only after a violation occurs
  twice; it has not.
- **No CI restructuring** beyond adding one job: the existing `workflow`,
  `detect` and `php` jobs keep their shape.

## Impact

- **Floor and verifier**: `phpstan.dist.neon` (level), `.github/workflows/ci.yml`
  (one new job), `Makefile` (one new target). These are the files
  `harden-quality-and-docs` listed as untouched precisely because they are this
  change's subject.
- **New files for the round trip**: `scripts/migrations-roundtrip.sh` (the
  orchestration), `scripts/schema-fingerprint.sql` (the one authority for what
  the listing covers), `scripts/migrations_roundtrip_test.sh` (the stub suite,
  in the shape the two existing script suites established) and
  `tests/Integration/Db/SchemaFingerprintTest.php` (the query against real
  PostgreSQL).
- **`src/`**: the five `src/Analytics/Query/` classes, the two Doctrine
  repositories returning `mixed`, the API Platform providers and
  `ListQueryFactory`, plus a new typed row reader under `src/Shared/`. 67 findings.
- **`tests/`**: 42 files, 294 findings, mostly one mechanical replacement plus a
  new accessor under `tests/Support/`.
- **Documents**: `docs/explanation/requirements.md` (NFR-QA-1 and two summary
  tables), `docs/how-to/local-development.md`, `openspec/config.yaml`,
  `openspec/ROADMAP.md`.
- **Nothing in the application's runtime behaviour**, no schema change, no new
  package.

## User decisions

- **2026-09-15 — the split.** Offered one `high` change, a split, or dropping the
  floor work, the user split row 13: documentation and architecture tests stayed
  `low` in `harden-quality-and-docs`, and the PHPStan level and the migration job
  became this row with its own Gate 1.
- **2026-09-16 — level 9 over the whole project.** Offered `src` at 9 with
  `tests` left at 8, the whole project at 9, or `src` at 9 with the level-9
  identifiers ignored in `tests`, the user chose the whole project. The reason
  the other two were on the table is the size of the test diff: 294 findings
  across 42 files. The reason this one wins is that a level that applies to half
  the repository is a level nobody can state in one sentence.
- **2026-09-16 — the stale project context.** `openspec/config.yaml` still says
  `Stage: scaffold`. Offered the correction here, as a consented direct commit on
  `main`, or not at all, the user folded it into this change.
