## Why

Twelve of the thirteen rows have shipped and the repository still introduces itself as a scaffold: `README.md` is twenty-nine lines that say "application code arrives through reviewed OpenSpec changes". Someone opening this project — the reason it exists — sees no picture of what it does, no diagram of how it is built, and no evidence for any of the performance numbers the specification sets. The ADR log has two records for a series of decisions that includes a rules engine, an async click path, a cache with a stated staleness, and an authorization model with two firewalls.

The architecture rules of NFR-QA-2 are also written down and enforced by nothing.

This is row 13, whose exit criterion is a complete README and rules that actually fail on a violation.

**Risk-Tier:** low

Measured against the `high` triggers rather than assumed: nothing here touches authentication, authorization, money, concurrency, deletion, migrations or input handling. What it adds is documentation and three tests in the existing suite — a test is not the verifier, it is what the verifier runs.

The two parts of the original row that *would* have made it `high` are gone from it: a PHPStan strictness sweep and a migration down/up job in CI both change the gate floor, which AGENTS.md lists among the `high` triggers. The user split them into row 13a `harden-gate-floor` on 2026-09-15 (see "User decisions"). If any task here turns out to touch the floor, a security boundary or a migration, it stops, the tier is raised in this file and Gate 1 is requested before the work continues — which is what row 12 failed to do and paid for with two Gate 2 rounds.

## What Changes

- **A README that shows the project.** What it is and what it demonstrates; screenshots of the dashboard, a link's statistics page and Swagger UI; the quick start that already exists; the security notes worth reading before the code (the two firewalls, the hashed keys, the policy, the ownership boundary answering 404); and links to the ADRs and the specification. The "scaffold" framing goes.
- **An architecture diagram** of the contexts and the two paths the design is built around: the redirect's write path (request → matcher → Messenger → handler → `clicks`) and the analytics read path (query services → cached report DTOs), with the boundary between them drawn, since CQRS-lite is the decision the project is arranged by.
- **Benchmarks with their commands and their numbers**, for the three targets the specification sets: the redirect's p95 (NFR-PERF-1), a report's p95 uncached on a million fixture clicks (NFR-PERF-2) and the worker's throughput (NFR-PERF-3). Each is a command a reader can run, run in its exact form, with what it produced on a stated machine — including any target that is not met, stated as not met rather than quietly omitted.
- **The architecture rules of NFR-QA-2 become three tests**: `src/Analytics/` references no `Click` entity; entities depend on `Doctrine\ORM` only through mapping attributes; controllers contain no queries. Each is checked against the source, and each is shown failing on a planted violation before it is believed.
- **The ADR log gains the records the series owes**: the decisions this project actually turned on and argued in change designs — CQRS-lite for clicks and analytics, the deterministic A/B hash, the cache's accepted staleness, the 404-versus-403 asymmetry between the pages and the API — each as a record with its alternatives, so the reasoning does not live only inside archived changes. The index gains their rows.
- No new dependency, no change to any behaviour, no migration, no change to `make check`'s composition.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

None — this change alters no requirement. `.openspec.yaml` declares `skip_specs: true`, because what it produces is documentation and tests over behaviour that is already specified: NFR-DOC-1 and NFR-QA-2 in `docs/explanation/requirements.md` are the statements it satisfies, and they are unchanged.

## User decisions

- **2026-09-15 — row 13 split, the floor work moved to 13a**: measured before proposing, PHPStan level 9 reports 361 errors (67 in `src`, 294 in `tests`, the latter almost all `mixed` from decoded JSON), and neither a strictness sweep nor a CI migration job can be done without changing the gate floor — a `high` trigger. Offered one `high` change, this split, or dropping the floor work from the plan, the user chose the split: row 13 stays `low` for the documentation and the architecture tests, and the new row 13a `harden-gate-floor` takes PHPStan level 9 over `src` and the migration down/up job, with its own Gate 1.

## Impact

- **New files**: `docs/explanation/architecture.md` (the diagram and its reading), `docs/adr/ADR-002..ADR-005` (the records named above), `tests/Unit/Architecture/` (the three rules), screenshots under `docs/images/`.
- **Changed files**: `README.md` (rewritten), `docs/adr/README.md` (index rows), `docs/README.md` (the new explanation page), `openspec/ROADMAP.md` and `docs/explanation/requirements.md` §7 (the split, already recorded).
- **Untouched on purpose**: every source file outside the new tests, `phpstan.dist.neon`, the `Makefile`, `.github/workflows/ci.yml` — the floor is row 13a's subject, not this change's.
- **Dependencies**: none added. The diagram is Mermaid in Markdown, which GitHub renders without tooling; the benchmarks use `wrk` or `ab` from the container and the existing `app:demo:seed`.

## Non-goals

- Changing the gate floor in any way: no PHPStan level, no `make check` composition, no CI job (row 13a).
- Making a performance target pass. The benchmarks measure and publish; a target that is missed is written down as missed, and what to do about it is a separate decision with its own evidence.
- `deptrac` or any other architecture tool: NFR-QA-2 says it is added only if a violation actually occurs twice, and none has occurred once.
- Rewriting the how-to and reference documentation, which is current.
- A hosted demo, a public URL or anything in the stretch rows.
