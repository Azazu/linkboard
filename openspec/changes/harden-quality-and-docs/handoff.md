# Handoff — harden-quality-and-docs

**Updated:** 2026-09-15 · claude
**State:** proposing
**Branch:** change/harden-quality-and-docs

## Done this session
- Branch `change/harden-quality-and-docs` created from `main` (`f6e9241`, after the archive of `polish-api-and-openapi`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 13 and §7 of `docs/explanation/requirements.md`: README with screenshots, an architecture diagram and benchmarks; a PHPStan strictness sweep; migration down/up in CI; architecture tests (NFR-QA-2); an ADR index. Exit criterion the roadmap records: the README is complete and the CI matrix green.
- Tier `low`, argued in the proposal against the `high` triggers rather than inherited from the roadmap — and the two parts that would have raised it were split out first (below).

- **Measured before proposing, not assumed:** `README.md` is 29 lines and still calls the project a scaffold; `docs/adr/` holds two records; PHPStan level 9 reports **361 errors — 67 in `src`, 294 in `tests`**, the latter almost all `mixed` from decoded JSON; CI runs no migration down/up; the three rules of NFR-QA-2 are enforced by nothing; no benchmarking tool is installed in the `php` image, and `docker run --rm --network linkboard_default williamyeh/wrk …` was verified to drive the running stack from a throwaway container.
- **Row split by the user (2026-09-15).** Both the PHPStan sweep and the CI migration job change the gate floor, which AGENTS.md makes a `high` trigger. Offered one `high` change, a split, or dropping the floor work, the user chose the split: row 13 stays `low` for the documentation and the architecture tests, and the new row 13a `harden-gate-floor` takes PHPStan level 9 over `src` and the migration down/up job with its own Gate 1. `openspec/ROADMAP.md` and the brief's §7 record it.
- Artifacts written and `openspec validate --strict` passes: proposal (tier `low` argued against the triggers, with the rule that a task needing the floor stops and raises it), design (benchmarks from a throwaway `wrk` container, the three rules as source-scanning tests in the shape `ProductionDependenciesTest` already uses, the diagram as Mermaid in the repository, screenshots from the acceptance browser, four ADRs for decisions actually argued), tasks (rules, benchmarks, diagram, screenshots, README, ADRs, wrap-up). **`.openspec.yaml` declares `skip_specs: true`**: this change alters no requirement — NFR-DOC-1 and NFR-QA-2 are the statements it satisfies, unchanged.

**What the design says it cannot guarantee**, so nobody reads more into it: the architecture rules are text scans, so a violation written through a variable class name or built in a collaborator passes — each test's docblock names its own blind spot; the benchmarks are single-machine numbers published with their machine, and a missed target is published as missed; the diagram and the screenshots can drift silently, and their mitigation is that one command regenerates each.

## Next step
`/opsx:apply harden-quality-and-docs` — implement in the task order. Tier `low`: no gate; `make check` and a green Actions run on the branch head are the floor before merge.

## Blockers
None.
