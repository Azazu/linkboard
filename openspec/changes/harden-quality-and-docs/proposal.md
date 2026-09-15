## Why

Twelve of the thirteen rows have shipped and the repository still introduces itself as a scaffold: `README.md` is twenty-nine lines that say "application code arrives through reviewed OpenSpec changes". Someone opening this project — the reason it exists — sees no picture of what it does, no diagram of how it is built, and no evidence for any of the performance numbers the specification sets. The ADR log has two records for a series of decisions that includes a rules engine, an async click path, a cache with a stated staleness, and an authorization model with two firewalls.

The architecture rules of NFR-QA-2 are also written down and enforced by nothing.

This is row 13, whose exit criterion is a complete README and rules that actually fail on a violation.

**Risk-Tier:** high

Raised from `low` on 2026-09-15, by the user, after implementation uncovered a defect in the test environment itself (see "User decisions"). The documentation and the architecture tests remain what they were — documents and tests. What raises the tier is the fix that has to go with them: the test suite running inside the compose container does not get the configuration `.env.test` declares, and correcting that touches how every test run resolves its environment, including `APP_SECRET`, `JWT_PASSPHRASE` and `VISITOR_HASH_SALT`. That is the verifier's own floor, and AGENTS.md makes it `high` regardless of how small the diff is.

Gate 1 on these artifacts before the fix is written, then Gate 2 on the diff, with a demonstrated failing input for the defect.

## What Changes

- **A README that shows the project.** What it is and what it demonstrates; screenshots of the dashboard, a link's statistics page and Swagger UI; the quick start that already exists; the security notes worth reading before the code (the two firewalls, the hashed keys, the policy, the ownership boundary answering 404); and links to the ADRs and the specification. The "scaffold" framing goes.
- **An architecture diagram** of the contexts and the two paths the design is built around: the redirect's write path (request → matcher → Messenger → handler → `clicks`) and the analytics read path (query services → cached report DTOs), with the boundary between them drawn, since CQRS-lite is the decision the project is arranged by.
- **Benchmarks with their commands and their numbers**, for the three targets the specification sets: the redirect's p95 (NFR-PERF-1), a report's p95 uncached on a million fixture clicks (NFR-PERF-2) and the worker's throughput (NFR-PERF-3). Each is a command a reader can run, run in its exact form, with what it produced on a stated machine — including any target that is not met, stated as not met rather than quietly omitted.
- **The architecture rules of NFR-QA-2 become three tests**: `src/Analytics/` references no `Click` entity; entities depend on `Doctrine\ORM` only through mapping attributes; controllers contain no queries. Each is checked against the source, and each is shown failing on a planted violation before it is believed.
- **The ADR log gains the records the series owes**: the decisions this project actually turned on and argued in change designs — CQRS-lite for clicks and analytics, the deterministic A/B hash, the cache's accepted staleness, the 404-versus-403 asymmetry between the pages and the API — each as a record with its alternatives, so the reasoning does not live only inside archived changes. The index gains their rows.
- **The test environment gets the configuration it declares.** Measured while implementing: `docker-compose.yml` passes `.env` into the container's process environment, and Symfony's `Dotenv` never overrides a real environment variable with a file's — so inside the container `.env.test` loses on every variable `.env` also sets. Four do: `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT` and `COUNTRY_RESOLVERS`. The suite has therefore been running locally with the development salt, the development passphrase and the production-shaped country resolver chain, while CI — which sets none of them — runs with what `.env.test` says. Ten redirect tests fail locally and pass in CI for exactly this reason. The fix makes a test run resolve `.env.test` as the authority for the variables that file defines, so a local run and a CI run exercise the same configuration.
- **And the same divergence one layer down, in key generation.** `make jwt-keys` runs `lexik:jwt:generate-keypair --env=test` inside the container, where `.env`'s empty `JWT_PASSPHRASE` wins, so it writes a test private key with no passphrase while `.env.test` declares one. A fresh `make init` therefore produces a test keypair the suite cannot use — CI is unaffected, because it runs the same target natively. The target learns to generate the test key with the passphrase `.env.test` declares, and to notice a key that does not match it rather than skipping over one.
- No new dependency, no change to any application behaviour, no migration, no change to `make check`'s composition.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

None — this change alters no requirement. `.openspec.yaml` declares `skip_specs: true`, because what it produces is documentation and tests over behaviour that is already specified: NFR-DOC-1 and NFR-QA-2 in `docs/explanation/requirements.md` are the statements it satisfies, and they are unchanged.

## User decisions

- **2026-09-15 — the key-generation half fixed here too, with Gate 1 requested again**: after the bootstrap fix landed, the suite exposed the same divergence in `make jwt-keys`, which generates the test keypair inside the container with `.env`'s empty passphrase. Offered the choice between fixing it here (the `Makefile` leaves this change's untouched list, so the scope changes and Gate 1 is requested again), handing it to row 13a, or removing the overlapping keys from `.env` instead, the user chose to fix it here and re-request Gate 1 — so a fresh `make init` produces a working test setup rather than one the next reader has to debug.
- **2026-09-15 — tier raised to `high` and the environment defect fixed here**: implementation of the benchmarks recreated the `php` container, and ten redirect tests began failing locally while CI stayed green. The cause is not the tests: compose injects `.env` into the process environment and `.env.test` cannot override it, so four variables — three of them secrets or salts — differ between a local run and CI. Offered the choice between moving the fix into row 13a, raising this row's tier and fixing it now, or documenting a workaround, the user chose to raise the tier and fix it now. Gate 1 is requested on these corrected artifacts before the fix is written.
- **2026-09-15 — row 13 split, the floor work moved to 13a**: measured before proposing, PHPStan level 9 reports 361 errors (67 in `src`, 294 in `tests`, the latter almost all `mixed` from decoded JSON), and neither a strictness sweep nor a CI migration job can be done without changing the gate floor — a `high` trigger. Offered one `high` change, this split, or dropping the floor work from the plan, the user chose the split: row 13 stays `low` for the documentation and the architecture tests, and the new row 13a `harden-gate-floor` takes PHPStan level 9 over `src` and the migration down/up job, with its own Gate 1.

## Impact

- **New files**: `docs/explanation/architecture.md` (the diagram and its reading), `docs/adr/ADR-002..ADR-005` (the records named above), `tests/Unit/Architecture/` (the three rules), screenshots under `docs/images/`.
- **Changed files**: `README.md` (rewritten), `docs/adr/README.md` (index rows), `docs/README.md` (the new pages), `openspec/ROADMAP.md` and `docs/explanation/requirements.md` §7 (the split, already recorded).
- **Verifier-floor changes**: `tests/bootstrap.php`, so that a test run resolves the variables `.env.test` declares regardless of what the container's environment carries; and the `jwt-keys` target in the `Makefile`, so the test keypair is generated with the passphrase that file declares. Both are the same defect — a real environment variable beating a committed file — at the two places it bites.
- **Untouched on purpose**: every source file under `src/`, `phpstan.dist.neon`, `.github/workflows/ci.yml`, `docker-compose.yml` and `.env` — the floor's composition is row 13a's subject, and the environment fix is deliberately made in the test bootstrap rather than in the container or the committed environment files, so nothing about running the application changes.
- **Dependencies**: none added. The diagram is Mermaid in Markdown, which GitHub renders without tooling; the benchmarks run `wrk` from a throwaway container on the compose network and use the existing `app:demo:seed`.

## Non-goals

- Changing the PHPStan level, `make check`'s composition or any CI job — row 13a still owns all three. The `jwt-keys` target is not part of `make check`; it is a setup step `make init` calls. The environment fix touches neither: it changes how a test process resolves its own configuration, not what the floor consists of.
- Making a performance target pass. The benchmarks measure and publish; a target that is missed is written down as missed, and what to do about it is a separate decision with its own evidence.
- `deptrac` or any other architecture tool: NFR-QA-2 says it is added only if a violation actually occurs twice, and none has occurred once.
- Rewriting the how-to and reference documentation, which is current.
- A hosted demo, a public URL or anything in the stretch rows.
