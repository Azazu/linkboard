# Handoff — harden-quality-and-docs

**Updated:** 2026-09-15 · claude
**State:** implementing
**Branch:** change/harden-quality-and-docs

## Done this session
- Branch `change/harden-quality-and-docs` created from `main` (`f6e9241`, after the archive of `polish-api-and-openapi`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 13 and §7 of `docs/explanation/requirements.md`: README with screenshots, an architecture diagram and benchmarks; a PHPStan strictness sweep; migration down/up in CI; architecture tests (NFR-QA-2); an ADR index. Exit criterion the roadmap records: the README is complete and the CI matrix green.
- Tier `low`, argued in the proposal against the `high` triggers rather than inherited from the roadmap — and the two parts that would have raised it were split out first (below).

- **Measured before proposing, not assumed:** `README.md` is 29 lines and still calls the project a scaffold; `docs/adr/` holds two records; PHPStan level 9 reports **361 errors — 67 in `src`, 294 in `tests`**, the latter almost all `mixed` from decoded JSON; CI runs no migration down/up; the three rules of NFR-QA-2 are enforced by nothing; no benchmarking tool is installed in the `php` image, and `docker run --rm --network linkboard_default williamyeh/wrk …` was verified to drive the running stack from a throwaway container.
- **Row split by the user (2026-09-15).** Both the PHPStan sweep and the CI migration job change the gate floor, which AGENTS.md makes a `high` trigger. Offered one `high` change, a split, or dropping the floor work, the user chose the split: row 13 stays `low` for the documentation and the architecture tests, and the new row 13a `harden-gate-floor` takes PHPStan level 9 over `src` and the migration down/up job with its own Gate 1. `openspec/ROADMAP.md` and the brief's §7 record it.
- Artifacts written and `openspec validate --strict` passes: proposal (tier `low` argued against the triggers, with the rule that a task needing the floor stops and raises it), design (benchmarks from a throwaway `wrk` container, the three rules as source-scanning tests in the shape `ProductionDependenciesTest` already uses, the diagram as Mermaid in the repository, screenshots from the acceptance browser, four ADRs for decisions actually argued), tasks (rules, benchmarks, diagram, screenshots, README, ADRs, wrap-up). **`.openspec.yaml` declares `skip_specs: true`**: this change alters no requirement — NFR-DOC-1 and NFR-QA-2 are the statements it satisfies, unchanged.

**What the design says it cannot guarantee**, so nobody reads more into it: the architecture rules are text scans, so a violation written through a variable class name or built in a collaborator passes — each test's docblock names its own blind spot; the benchmarks are single-machine numbers published with their machine, and a missed target is published as missed; the diagram and the screenshots can drift silently, and their mitigation is that one command regenerates each.

- Implemented while the tier was `low` (sections 1–6), touching only documents and tests:
  - **The three rules of NFR-QA-2** as source-scanning tests, each shown failing on a planted violation and each naming its own blind spot. The controller rule permits `EntityManagerInterface::flush()` — a unit-of-work commit is not a query — which `RegistrationController` uses; that it flushes directly rather than through a use case is worth a later change and is recorded here rather than refactored.
  - **Benchmarks**, all three with their commands, machine and distributions in `docs/how-to/benchmarks.md`. Redirect p50 20.4 ms / p95 ≈ 22 ms — met. Eight of nine reports 43–169 ms uncached on 1 000 005 clicks — met; **the global top-links report p95 303 ms against a 300 ms target — missed, published as missed**, and 309 ms when re-measured prod-like. Worker 10 000 messages in 15.55 s → 643/s — met. A first worker figure of 488/s came from polling the queue every two seconds and was replaced with an exact count, not kept because it was close enough.
  - **The diagram** as Mermaid, rendered by Mermaid itself in headless Chromium (`tests/Acceptance/mermaid-check.mjs`): 13 nodes, 136 KB of SVG. The npm build needs a DOM and cannot parse headlessly, which is why the check is a browser run.
  - **Screenshots** from the acceptance browser against a seeded stack; each was opened and looked at, not assumed.
  - **README** rewritten, and the `scaffold` claim swept: the only remaining hits name the historical change `scaffold-symfony-app`.
  - **ADR-002 to ADR-005**, each stating what it does not guarantee; the index lists every record.

- **Then the defect that raised the tier.** Recreating the `php` container for the benchmarks made ten redirect tests fail locally while CI stayed green. Cause, verified on a clean tree: compose passes `.env` into the process environment and Symfony's `Dotenv` never overrides a real variable with a file's, so `.env.test` loses on the four variables `.env` also sets — `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT`, `COUNTRY_RESOLVERS`. The suite has been running locally with the development salt and passphrase. With `-e COUNTRY_RESOLVERS=fixed` the whole suite is green (844 tests, 11 060 assertions). The user raised the tier to `high` and chose to fix it here; artifacts were corrected and Gate 1 requested before any code.

- Gate 1 round 1 (`746efe4`, Reviewed-Commit `e5a7964`): changes-requested — one major, and a real process defect. A task that said "run Gate 2 and disposition its findings" can never be both truthful and satisfied, because `gate-run.sh` runs the floor first and the floor rejects an unchecked task. Fixed: the checkbox now covers only the pre-review CI evidence, and the gate invocation is an un-checkboxed lifecycle section at the end of `tasks.md`. **Worth noting for the process:** the three previous changes worked around this by ticking the gate task as it was run, which is the same false claim in a quieter form — evidence that `AGENTS.md`'s task rules and the floor disagree, and a candidate for a rule change once a fourth change hits it.

- Gate 1 Confirmation 1 (`8d23281`, Reviewed-Commit `cb1e666`): confirmed — **Gate 1 passed**. The reviewer checked the task lifecycle against `gate-run.sh` and `pregate-verify.sh` and states no such circular dependency remains in the change.

- Section 8 implemented after the gate, not before. `tests/bootstrap.php` re-applies the variables `.env.test` and `.env.test.local` declare and nothing else; `tests/Integration/TestEnvironmentTest.php` asserts both halves — every declared variable in force, and the four connection variables CI owns untouched. Demonstrated failing input executed and recorded in task 8.3: with the re-application removed, seven tests fail (both environment assertions and the five routing tests needing the fixed country map); restored, `OK (16 tests, 154 assertions)`, and the whole suite is green **with no `-e` override**: 848 tests, 11 090 assertions.

**One consequence of the fix that is not fixed, and needs a decision.** The same divergence exists one layer down, in key generation. `make jwt-keys` runs `lexik:jwt:generate-keypair --env=test` *inside the container*, where `.env`'s empty `JWT_PASSPHRASE` wins — so it writes a test private key with no passphrase, while `.env.test` (and now the suite) declares `test-only-jwt-passphrase-not-a-secret`. My own `config/jwt/test/` keypair was in exactly that state and had to be regenerated with the passphrase passed explicitly before the suite would pass; the keys are gitignored local artifacts, so nothing in the repository changed. CI is unaffected: it runs `make jwt-keys` natively, where `.env.test` applies.

The consequence is that a fresh `make init` on this branch still produces a test keypair the suite cannot use. Fixing it is one line in the `Makefile` — which this change's proposal names as untouched and row 13a owns — so it is a scope question for the user rather than something to absorb quietly.

## Next step
Ask the user where the `make jwt-keys` line belongs (this change, with a Gate 1 re-request for the scope change, or row 13a). Then task 9.2: the user pushes, the executor records the Actions run on the exact head, and Gate 2 is requested per the lifecycle section at the end of `tasks.md`.

## Blockers
None.
