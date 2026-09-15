# Tasks — harden-quality-and-docs

Tier `low`: no gate required; `make check` and CI are the floor. The floor
itself is row 13a's subject — if a task here needs `phpstan.dist.neon`, the
`Makefile` or `.github/workflows/`, it stops, raises the tier in
`proposal.md` and requests Gate 1 before touching them.

## 1. The architecture rules of NFR-QA-2

- [ ] 1.1 `tests/Unit/Architecture/AnalyticsKeepsItsDistanceTest.php`: no file under `src/Analytics/` names a `Click` entity (design decision 2). Verify: the test lists every offending path; it asserts it scanned a non-empty set of files, so an empty scan fails rather than passes; and a demonstrated failing input — importing `App\Click\Entity\Click` into a query service makes it fail, with the command and output recorded.
- [ ] 1.2 `tests/Unit/Architecture/EntitiesStayMappedTest.php`: a file under a `Entity/` directory references `Doctrine\ORM` only through mapping attributes. Verify: as above, with a demonstrated failing input — a `use Doctrine\ORM\EntityManagerInterface;` in an entity makes it fail.
- [ ] 1.3 `tests/Unit/Architecture/ControllersDoNotQueryTest.php`: no controller file contains `EntityManager`, `createQueryBuilder`, `createQuery` or an SQL string. Verify: as above, with a demonstrated failing input — a `createQueryBuilder` call planted in a web controller makes it fail.
- [ ] 1.4 The three rules state what they cannot see. Verify: each test's docblock names the evasion it does not catch (a variable class name, a query built in a collaborator), so nobody reads a passing suite as a proof it is not.

## 2. Benchmarks

- [ ] 2.1 The redirect's p95 (NFR-PERF-1). Verify: `wrk` run from a throwaway container against a warm slug on the running stack, its exact command and its latency distribution recorded in the README and in `handoff.md`; the run states the machine it came from; if p95 exceeds 50 ms it is published as exceeded, with the number.
- [ ] 2.2 A report's p95 uncached on a million clicks (NFR-PERF-2). Verify: `app:demo:seed --clicks=1000000` run in its exact form, the report cache cleared before the run, the `wrk` command and its distribution recorded; the same publication rule for a missed target.
- [ ] 2.3 The worker's throughput (NFR-PERF-3). Verify: a batch of 10 000 `ClickRecorded` messages dispatched and drained by `make worker`, timed, the command and the elapsed seconds recorded; the same publication rule.
- [ ] 2.4 Every benchmark command in the README was run in the exact form the README prints. Verify: the commands are copied from the shell that ran them, not retyped.

## 3. The architecture diagram

- [ ] 3.1 `docs/explanation/architecture.md`: the bounded contexts, the redirect write path (request → rule matcher → Messenger → handler → `clicks`) and the analytics read path (query services → cached report DTOs), drawn as Mermaid with the boundary between the two paths visible, and prose explaining what the boundary buys (design decision 3). Verify: every element named exists in `src/` — checked by `rg` for each while writing — and the page is listed in `docs/README.md`.
- [ ] 3.2 The diagram renders. Verify: the Mermaid block parses in the repository's own renderer (the page is opened after pushing, or the block is checked with a Mermaid parser), and the README links to the page.

## 4. Screenshots

- [ ] 4.1 `tests/Acceptance/screenshots.mjs`: captures the dashboard, a link's statistics page and Swagger UI from a seeded stack with the headless Chromium the acceptance runs already use (design decision 4), writing PNGs to `docs/images/`. Verify: the script run in its exact form, the three files committed, and their sizes stated in the commit body.
- [ ] 4.2 The README shows them with alt text that says what each one demonstrates. Verify: the README re-read whole after the last edit; each image referenced by a path that exists.

## 5. The README

- [ ] 5.1 Rewrite `README.md` (NFR-DOC-1): what the project is and what it demonstrates; the screenshots; the architecture diagram's link; the quick start that exists; the security notes (two firewalls, hashed API keys, the content security policy, the ownership boundary answering 404, the rate limits); the benchmarks with their commands; links to the ADRs, the specification and the roadmap. The "scaffold" framing goes. Verify: every command in it run in its exact form; every link resolves (`scripts/pregate-verify.sh` checks changed Markdown, and the file is re-read whole after the last edit).
- [ ] 5.2 The claim sweep: no document still calls the project a scaffold or says application code is yet to arrive. Verify: `rg -n 'scaffold' README.md docs/ openspec/` reviewed and each hit either corrected or justified in place.

## 6. The ADRs the series owes

- [ ] 6.1 `ADR-002`: CQRS-lite — the click write path and the analytics read model share no entity. Verify: states the alternatives rejected and names the change that decided it.
- [ ] 6.2 `ADR-003`: the deterministic per-visitor A/B hash. Verify: as above.
- [ ] 6.3 `ADR-004`: the report cache's accepted staleness and its invalidation on link change. Verify: as above.
- [ ] 6.4 `ADR-005`: the pages answer 404 where the API answers 403. Verify: as above.
- [ ] 6.5 `docs/adr/README.md` gains a row per record, in order. Verify: every row's link resolves; the index lists every file in `docs/adr/` except the template.

## 7. Wrap-up

- [ ] 7.1 `make check` green; `openspec validate harden-quality-and-docs --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated with the benchmark numbers and the demonstrated failing inputs.
- [ ] 7.2 Green Actions run on the exact branch head: the user pushes the change branch; the executor queries `https://api.github.com/repos/Azazu/linkboard/actions/runs?branch=change/harden-quality-and-docs` until the run for `git rev-parse HEAD` is `completed` / `success`; URL and SHA recorded in `handoff.md`. Verify: the run's `head_sha` equals the branch head. Tier `low` needs no gate, so this run and `make check` are the whole floor before merge.
