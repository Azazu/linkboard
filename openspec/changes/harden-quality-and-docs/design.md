## Context

See `proposal.md` — Why. What shapes the approach is what exists, measured before proposing.

- `README.md` is 29 lines and still calls the project a scaffold. `docs/` holds how-to and reference pages that are current, and one explanation page (`requirements.md`, the brief). `docs/adr/` holds ADR-000 (the agent workflow) and ADR-001 (Symfony 8 on PHP 8.4) plus a template and an index.
- The three rules of NFR-QA-2 are enforced by nothing. The repository already has the shape a source-scanning rule takes: `tests/Unit/ProductionDependenciesTest.php` reads the lock file and the runtime sources and reports offences as a list.
- No benchmarking tool is installed in the `php` image (`wrk`, `ab` and `siege` are all absent), and NFR-PERF-1 asks for a documented `wrk`/`ab` command. Verified while writing this: `docker run --rm --network linkboard_default williamyeh/wrk …` drives the running stack from a throwaway container, so the repository needs no new tooling.
- `app:demo:seed --clicks=…` already produces a click dataset of a stated size, which is what NFR-PERF-2's "1 M fixture clicks" needs.

## Goals / Non-Goals

**Goals**

- A README that answers "what is this and what does it show" before it answers "how do I run it".
- Numbers a reader can reproduce with the command printed next to them.
- Three architecture rules that fail when violated, shown failing before they are believed.

**Non-Goals** (beyond the proposal's)

- Touching the gate floor in any way — that is row 13a, and this change's tier depends on it staying true.
- Making a missed performance target pass, or tuning anything to make a number look better.
- A generated architecture diagram. It is drawn by hand because it says what the *design* is, which no generator knows.

## Decisions

### 1. The benchmarks run from a throwaway container, and publish what they measured

Each of the three targets gets one documented command and one recorded result: the redirect through `wrk` over a warm slug, a report through `wrk` against a cleared report cache on a million seeded clicks, and the worker timed over a drained batch. The commands run `wrk` from `williamyeh/wrk` on the compose network; nothing is added to the `php` image and nothing is added to `composer.json`.

*Why:* NFR-PERF-1 names `wrk`/`ab` and a documented command, and the cheapest way to honour that without carrying a tool in the production image is a container that exists for the length of the run. It also means the command in the README is the command that was run.

*What this does not guarantee:* these are single-machine numbers on Docker Desktop-class hardware, stated with the machine they came from. They are evidence that the design holds its shape, not a claim about production. A target that is missed is published as missed — the alternative, quietly omitting it, is the one thing a benchmark section must never do.

### 2. The architecture rules are source-scanning tests, not a tool

Three tests under `tests/Unit/Architecture/`, each reading the source files it governs and reporting every offence as a list, in the shape `ProductionDependenciesTest` already uses:

- `src/Analytics/` names no `Click` entity — the CQRS-lite boundary, which is the project's central arrangement.
- An entity's file references `Doctrine\ORM` only as mapping attributes (`#[ORM\…]`), never as a service or query type.
- A controller's file contains no query: no `EntityManager`, no `createQueryBuilder`, no DQL or SQL string.

*Why:* NFR-QA-2 says these are enforced "where cheap", and adds that `deptrac` is taken only if a violation actually occurs twice. None has occurred once. A test that reads the files is cheap, has no dependency, and fails with the offending path.

*What this does not guarantee:* a text scan sees text. A violation written through a variable class name, or a query built in a service the controller calls, passes. The rules catch the shape people actually drift into — an import and a call — and each one is demonstrated by planting exactly that shape and watching the test fail.

### 3. The diagram is Mermaid in the repository, not an image

`docs/explanation/architecture.md` carries the diagram as a Mermaid block: the bounded contexts, the redirect's write path from request through the matcher and Messenger to the `clicks` table, and the analytics read path from query services through the cached DTOs, with the boundary between the two drawn.

*Why:* GitHub renders Mermaid, so the diagram stays in the text that explains it, reviewable as a diff and correctable by anyone editing the prose. A PNG would be neither.

*What this does not guarantee:* the diagram is prose, and prose can fall behind. It is placed in the explanation section, which `AGENTS.md` allows to describe decided design citing its ADR, and every element it names exists in `src/` today.

### 4. Screenshots come from seeded data through the browser already used for acceptance

The dashboard, a link's statistics page and Swagger UI are captured with the headless Chromium and `puppeteer-core` the acceptance runs use, against a stack seeded by `app:demo:seed`, and committed as PNGs under `docs/images/`. The capture script lives beside the acceptance scripts so it can be re-run when a page changes.

*Why:* the same tooling, no new dependency, and a screenshot that can be regenerated rather than redrawn.

*What this does not guarantee:* an image goes stale silently — no test can see that a screenshot no longer matches the page. The script is the mitigation: regenerating is one command, and the README says which one.

### 5. The ADRs record decisions that were argued, not decisions invented for the log

Four records, each for a decision this project actually turned on and weighed alternatives for inside a change design: CQRS-lite between the click write path and the analytics read model; the deterministic per-visitor A/B hash; the report cache's accepted staleness with invalidation on link change; and the pages answering 404 where the API answers 403. Each states the alternatives that were rejected and why, with a pointer to the archived change that carries the working.

*Why:* NFR-QA-3 asks for ADRs on the trade-offs of section 7 as they are faced; four were faced and their reasoning currently lives only inside archived change directories, which is not where someone looks for "why".

*What this does not guarantee:* an ADR written after the fact is a record, not a diary. Each says which change decided it and on what date, so the reader can find the original argument rather than trusting the summary.

## Risks / Trade-offs

- **A benchmark that flatters** → every number is published with the command, the dataset size and the machine, and a missed target is stated as missed. The section is worthless if it is a sales page.
- **The architecture rules pass vacuously** → each is demonstrated failing on a planted violation before it is believed, and each test asserts it actually scanned files (an empty file list fails).
- **Screenshots and diagram drift** → both are regenerable from one documented command, and the diagram's elements are checked against `src/` while writing it.
- **Scope creeping into the floor** → the one thing that would change this change's tier. `phpstan.dist.neon`, the `Makefile` and `.github/workflows/` are named in the proposal as untouched, and a task that finds itself needing them stops and raises the tier.

## Migration Plan

Nothing to migrate: no schema, no data, no behaviour, no configuration. The change adds documents and tests.
