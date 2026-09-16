# Review — harden-gate-floor

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** d30af22939912558645ce18f4525841af033f511
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | tasks.md:62, task 8.4 | Task 8.4 can only be completed and verified after the archive commit, but the final lifecycle section requires every checkbox complete before Gate 2. `scripts/pregate-verify.sh` rejects any unchecked task at Gate 2, and `scripts/workflow-verify.sh` also requires all tasks complete before merge and archive. This creates a lifecycle deadlock. Separate the pre-merge requirements-document reconciliation from the post-merge roadmap removal, and describe the latter as an un-checkboxed archive lifecycle step rather than a Gate 2 prerequisite. | fixed |
| 2 | major | design.md:185-190, 207-209; tasks.md:39, 55 | The deterministic scratch name plus an unconditional initial drop does not establish database ownership. With configured database `app`, an existing unrelated `app_roundtrip` is deleted even though the equality guard passes; two local invocations against `app` also target and drop the same database. Printing the name does not prevent either failure, so concurrent writers cannot be marked n/a. Specify exclusive scratch ownership before any destructive command (for example, a fresh per-run name with creation that refuses an existing database, and cleanup limited to that successfully created database), including how crash leftovers are handled without deleting an unowned database. Add negative tests for a pre-existing target and overlapping invocations. | fixed |
| 3 | major | design.md:152-169; tasks.md:40, 51-55 | The negative fingerprint test substitutes a different listing from a fake console, so it proves the shell comparison but not the SQL fingerprint check. The only real PostgreSQL run uses migrations whose schema already round-trips correctly. If the fingerprint SQL omits indexes or column attributes, that real run and all proposed stub tests still pass; task 7.2 therefore does not demonstrate detection of an actual leftover index. Add a repeatable real-PostgreSQL negative fixture that changes a covered schema object and exercises the real fingerprint SQL/comparison, with a named difference and nonzero result. Cover the promised column, index and constraint categories so removing their extraction causes a test to fail; keep the stub suite for orchestration failures. | fixed |
| 4 | minor | tasks.md:31, task 3.6; design.md:92-99 | The required unchanged assertion count contradicts the chosen JSON accessor, which intentionally adds counted PHPUnit assertions to existing tests. Its successful use can increase assertion counts even with no new test methods. Record and explain the expected accessor-related assertion delta separately from newly added tests, and verify preservation of the original assertions directly; equal counts alone do not establish equal meaning. | fixed |

### Validation

- Verified the current branch is `change/harden-gate-floor`, HEAD matches the reviewed commit, and the worktree was clean before this record.
- Read the proposal, design, tasks, handoff, `.openspec.yaml`, `AGENTS.md` and `openspec/config.yaml`; checked the existing migration, CI, Makefile and verifier mechanisms against the plan.
- `openspec validate harden-gate-floor --strict` passed.
- `scripts/pregate-verify.sh gate1 harden-gate-floor` passed with zero warnings. The mechanical floor does not detect the lifecycle and isolation issues above.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** b9cf01349a9a7294e457cf856802a408e45433ba
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Task 8.4 now covers only pre-merge requirements reconciliation; roadmap removal is an un-checkboxed post-merge archive step. The named task no longer depends on passing Gate 2 or archiving first. |
| 2 | changes-requested — Design decision 4 and task 5.2 now require successful creation before cleanup and preserve crash leftovers, resolving the destructive ownership design. The requested negative verification remains incomplete: task 7.5 covers a non-empty response, but not failed exclusive creation of an already existing empty database; task 7.6 only runs twice and compares names, without requiring overlap or proving that one run's cleanup cannot drop the other's database. Specify a create-collision failure case (including an empty existing target) with no migration or drop, and a synchronized overlapping-run case that holds one owned database live while the other finishes or fails and verifies cleanup affects only its owner. Random names reduce collisions; the exclusive-create failure path is what makes a collision safe and needs its own demonstrated failing input. |
| 3 | confirmed — Decision 4a and tasks 5.3/7.7 share the production SQL with repeatable real-PostgreSQL fixtures for indexes, column nullability/default/type and constraints, require named differences, and require executed extraction-removal mutations to fail. Task 7.2 separately verifies the shell comparison's nonzero exit and printed diff; the former claim that stub output proves SQL coverage is removed. |
| 4 | confirmed — Task 3.6 replaces equal assertion counts with an explained accessor-related delta, names added tests separately, and requires direct inspection of original assertions in each converted file. |

### Validation

- Reviewed only the diff from `d30af22939912558645ce18f4525841af033f511` to the Reviewed-Commit and context reachable from the source findings; no unrelated findings introduced.
- Verified branch `change/harden-gate-floor`, HEAD equal to the Reviewed-Commit, and an initially clean worktree.
- `openspec validate harden-gate-floor --strict` passed.
- `scripts/pregate-verify.sh gate1 harden-gate-floor` passed with zero warnings; it does not establish the missing ownership-test coverage.
- This is confirmation of planning artifacts at Gate 1; implementation and the planned database tests remain for Gate 2.

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** 6cf163d685152ec22f3829df9a27963604d59725
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Task 8.4 requires only pre-merge requirements reconciliation. Roadmap removal remains an un-checkboxed post-merge archive step, so it no longer conflicts with the verifiers' requirement that all tasks be complete before Gate 2 and merge. |
| 2 | confirmed — Design decision 4 and task 5.2 establish ownership only after a successful exclusive `CREATE DATABASE`; failed creation permits neither migration nor cleanup, and crash leftovers are not adopted or deleted by later runs. Task 7.5 explicitly tests collisions with both empty and non-empty existing databases and requires a nonzero exit with no migration or drop. Task 7.6 holds one run inside a migration while a second completes, then checks each run drops only its own database. Task 7.7 separately verifies failed creation cannot trigger a drop. These close the verification gaps identified in Confirmation 1. |
| 3 | confirmed — Decision 4a and tasks 5.3/7.8 require the production fingerprint SQL to be shared with real-PostgreSQL fixtures covering indexes, column nullability/default/type and constraints, with named differences and executed extraction-removal mutations that must fail. Task 7.2 separately requires a nonzero exit and printed diff from the shell comparison; stub output is no longer treated as proof of SQL coverage. |

### Validation

- Reviewed only the diff from `d30af22939912558645ce18f4525841af033f511` to `6cf163d685152ec22f3829df9a27963604d59725` and collateral context reachable from the named findings; no unrelated findings introduced.
- Verified branch `change/harden-gate-floor`, HEAD equal to the Reviewed-Commit, and an initially clean worktree. All source-round findings have been dispositioned.
- Checked the revised design and tasks against the proposal, handoff, project context and existing lifecycle verifiers; searched the repository for the affected round-trip and fingerprint claims.
- `openspec validate harden-gate-floor --strict` passed.
- `scripts/pregate-verify.sh gate1 harden-gate-floor` passed with zero warnings.
- This confirms the resolution of every major finding in the Gate 1 plan; round 1 contained no blockers. Implementation and execution of the planned database tests remain for Gate 2. Finding 4 was already confirmed in Confirmation 1 and is outside this requested blocker/major confirmation.

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** 58b13bd75faa1c7148f2162ea422a3a20887fadf
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | scripts/migrations-roundtrip.sh:32-45, 70-71 | Replacing only the URL path does not isolate the migration connection. With `DATABASE_URL=postgresql://u:p@h/app?dbname=app&serverVersion=16`, creation owns a new `app_roundtrip_<suffix>`, but every migration still connects to `app`: the retained query parameter overrides the path in the installed DBAL `DsnParser::parseDatabaseUrlQuery()` (merged after `parseDatabaseUrlPath()`), which DoctrineBundle uses. Consequently `migrate first` drops the configured database's application tables and their data while cleanup merely drops the unused scratch database. A stub run confirmed all three migration invocations retain `?dbname=app`. Resolve and construct the connection using DBAL-compatible semantics, or reject database-selection overrides before any operation; verify the effective migration database is the one exclusively created. Add a regression case for a query-string database override proving no migration reaches the configured database. | fixed |
| 2 | major | scripts/migrations-roundtrip.sh:61-68, 90-103 | SQL failures in the listing commands are masked by the pipelines, and `for table in $(tables)` does not propagate a failed substitution either. Reproduced with the script evaluated in memory and only `bin/console` replaced by a stub: the table-list query returns exit 7 and writes an error to stderr, the fingerprints remain stable, and the script exits 0 with both `down: only the declared survivors remain` and `the round trip reproduced the schema exactly`. Thus CI can pass without checking the post-down tables. Capture and check each console invocation's status before formatting its output, and check the table-list assignment before iterating; apply the same error propagation to fingerprints. Add failure fixtures for each listing stage, asserting nonzero exit and cleanup of the owned database. | fixed |
| 3 | major | scripts/schema-fingerprint.sql:16-22; tests/Integration/Db/SchemaFingerprintTest.php:65-83 | The column fingerprint uses only `information_schema.columns.data_type`, which omits type modifiers and concrete array/domain types. For example, changing the fixture's `label` from `varchar(32)` to `varchar(64)` leaves this listing unchanged: both produce `character varying`, and the column has no default, index or constraint that could expose the difference. Numeric precision/scale changes have the same gap. These are changes within the explicitly promised column-type coverage, not the documented exclusions for sequences or triggers. Include the complete PostgreSQL column type (the installed DBAL schema manager uses `format_type(a.atttypid, a.atttypmod)`), with any additional metadata needed for the promised coverage, and add real-PostgreSQL negative cases for length and precision/scale changes that assert a named column difference. The existing varchar-to-text case only proves detection of a different base type. | fixed |

### Validation

- Verified branch `change/harden-gate-floor`, HEAD equal to the Reviewed-Commit, and an initially clean worktree. Reviewed the branch diff against `main`, the planning artifacts, prior Gate 1 decisions, `AGENTS.md`, and `openspec/config.yaml`.
- `openspec validate harden-gate-floor --strict` and `sh -n scripts/migrations-roundtrip.sh` passed. `sh scripts/migrations_roundtrip_test.sh` reported 30 passed, 0 failed; its current cases do not cover findings 1 and 2.
- Executed the two shell reproductions described above without modifying repository sources or connecting to a database. The URL-resolution consequence in finding 1 and the missing column metadata in finding 3 were checked against current source, including the installed Doctrine DBAL/DoctrineBundle implementation; no destructive database reproduction was attempted.
- `scripts/pregate-verify.sh gate2 harden-gate-floor` passed its structural checks but failed at `make check`: this reviewer sandbox cannot access `/var/run/docker.sock`. A direct `make check` failed for the same permission reason. PHP is not available natively here, so PHPUnit, PHPStan and the real-PostgreSQL cases could not be independently rerun. This is a validation limitation, not evidence of an application test failure.
- The recorded CI evidence refers to `f8567ed`; the diff from that commit to the Reviewed-Commit contains only `handoff.md` and `tasks.md`. CI execution was not independently queried in this review.
- Only this review record was appended; no git write commands were run.
