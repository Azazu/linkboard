# Tasks — harden-quality-and-docs

Tier `high`, raised by the user on 2026-09-15 when implementation found that a
test run inside the container does not resolve the configuration `.env.test`
declares (proposal, "User decisions"). Gate 1 on the artifacts before the fix
is written, Gate 2 on the diff, and a demonstrated failing input for the
defect. Sections 1–6 were implemented while the tier was `low` and touched only
documents and tests; section 8 is the fix the raised tier is about, and it is
not started until Gate 1 passes.

`phpstan.dist.neon`, the `Makefile`, the CI workflow, `docker-compose.yml` and
`.env` stay untouched — the floor's composition is row 13a's.

## 1. The architecture rules of NFR-QA-2

- [x] 1.1 `tests/Unit/Architecture/AnalyticsKeepsItsDistanceTest.php`: no file under `src/Analytics/` names a `Click` entity (design decision 2). Verify: the test lists every offending path; it asserts it scanned a non-empty set of files, so an empty scan fails rather than passes; and a demonstrated failing input, executed: importing `App\Click\Entity\Click` into `BreakdownQuery` fails it with `src/Analytics/Query/BreakdownQuery.php names App\Click\Entity\`, and it passes again once removed.
- [x] 1.2 `tests/Unit/Architecture/EntitiesStayMappedTest.php`: a file under a `Entity/` directory references `Doctrine\ORM` only through mapping attributes. Verify: as above, with a demonstrated failing input, executed: `use Doctrine\ORM\EntityManagerInterface;` in `Link` fails it with `src/Link/Entity/Link.php imports Doctrine\ORM\EntityManagerInterface`.
- [x] 1.3 `tests/Unit/Architecture/ControllersDoNotQueryTest.php`: no controller file contains `EntityManager`, `createQueryBuilder`, `createQuery` or an SQL string. Verify: as above, with a demonstrated failing input, executed: a `createQueryBuilder()` call planted in `HomeController` fails it with `src/Web/Home/HomeController.php contains createQueryBuilder`. The rule deliberately permits `EntityManagerInterface::flush()`, which `RegistrationController` uses: a unit-of-work commit is not a query. That controller flushing directly rather than through a use case is worth a later change; it is recorded in `handoff.md` rather than refactored here, since this change touches no production code.
- [x] 1.4 The three rules state what they cannot see. Verify: each test's docblock names the evasion it does not catch (a variable class name, a query built in a collaborator), so nobody reads a passing suite as a proof it is not.

## 2. Benchmarks

- [x] 2.1 The redirect's p95 (NFR-PERF-1). Verify: measured and recorded in `docs/how-to/benchmarks.md` with the machine and the command — prod-like, one connection: p50 20.4 ms, p90 22.0 ms, p99 25.6 ms, **target met**; the four-connection distribution (p90 98 ms) is published beside it as queueing on a five-child pool, not server time.
- [x] 2.2 A report's p95 uncached on a million clicks (NFR-PERF-2). Verify: `app:demo:seed --clicks=1000000 --days=60 --reset` run in its exact form (1 000 005 rows in 13 s), each of the nine reports requested 15 times with `cache:pool:clear cache.reports` before every request. Eight meet the target; **the global top-links report does not** — p95 303 ms against 300 ms, and 309 ms re-measured prod-like — published as a miss with the number and with what the specification already names as the answer.
- [x] 2.3 The worker's throughput (NFR-PERF-3). Verify: 10 583 messages queued by real redirects with the worker stopped, then exactly 10 000 drained by `messenger:consume async --limit=10000` under `time`: **15.55 s → 643 messages/s, target met**. An earlier attempt that polled the queue length every two seconds reported 488/s; it was replaced because the polling granularity, not the worker, produced that number.
- [x] 2.4 Every benchmark command in the README was run in the exact form the README prints. Verify: every command in `docs/how-to/benchmarks.md` is the one that ran, including the temporary `docker-compose.bench.yml` the redirect run needs and the line that deletes it afterwards.

## 3. The architecture diagram

- [x] 3.1 `docs/explanation/architecture.md`: the bounded contexts, the redirect write path (request → rule matcher → Messenger → handler → `clicks`) and the analytics read path (query services → cached report DTOs), drawn as Mermaid with the boundary between the two paths visible, and prose explaining what the boundary buys (design decision 3). Verify: every element named exists in `src/` — checked by `rg` for each while writing — and the page is listed in `docs/README.md`.
- [x] 3.2 The diagram renders. Verify: `tests/Acceptance/mermaid-check.mjs` renders the block with Mermaid itself in the headless Chromium the other acceptance scripts use — the npm build needs a DOM and cannot parse headlessly — and reports `{"ok": true, "nodes": 13, "bytes": 135954}`; the README links to the page.

## 4. Screenshots

- [x] 4.1 `tests/Acceptance/screenshots.mjs`: captures the dashboard, a link's statistics page and Swagger UI from a seeded stack with the headless Chromium the acceptance runs already use (design decision 4), writing PNGs to `docs/images/`. Verify: the script run in its exact form against a stack seeded with `app:demo:seed --clicks=50000 --days=60 --reset`; three files committed — dashboard 169 KB, link-stats 213 KB, api-docs 243 KB, 632 KB together — and each opened and looked at rather than assumed.
- [x] 4.2 The README shows them with alt text that says what each one demonstrates. Verify: the README re-read whole after the last edit; each image referenced by a path that exists.

## 5. The README

- [x] 5.1 Rewrite `README.md` (NFR-DOC-1): what the project is and what it demonstrates; the screenshots; the architecture diagram's link; the quick start that exists; the security notes (two firewalls, hashed API keys, the content security policy, the ownership boundary answering 404, the rate limits); the benchmarks with their commands; links to the ADRs, the specification and the roadmap. The "scaffold" framing goes. Verify: every command in it run in its exact form; every link resolves (`scripts/pregate-verify.sh` checks changed Markdown, and the file is re-read whole after the last edit).
- [x] 5.2 The claim sweep: no document still calls the project a scaffold or says application code is yet to arrive. Verify: `rg -n 'scaffold' README.md docs/ openspec/` reviewed and each hit either corrected or justified in place.

## 6. The ADRs the series owes

- [x] 6.1 `ADR-002`: CQRS-lite — the click write path and the analytics read model share no entity. Verify: states the alternatives rejected and names the change that decided it.
- [x] 6.2 `ADR-003`: the deterministic per-visitor A/B hash. Verify: as above.
- [x] 6.3 `ADR-004`: the report cache's accepted staleness and its invalidation on link change. Verify: as above.
- [x] 6.4 `ADR-005`: the pages answer 404 where the API answers 403. Verify: as above.
- [x] 6.5 `docs/adr/README.md` gains a row per record, in order. Verify: every row's link resolves; the index lists every file in `docs/adr/` except the template.

## 7. Gate 1

- [ ] 7.1 Request Gate 1 on the corrected artifacts (`scripts/gate-run.sh harden-quality-and-docs 1 full`) and disposition every finding before section 8 starts. Verify: the last Gate 1 record reads `confirmed` or `approved` with no finding row left `open`.

## 8. The test environment resolves what it declares

- [ ] 8.1 `tests/bootstrap.php` re-applies the variables `.env.test` and `.env.test.local` define, and only those (design decision 6). Verify: a test asserts that `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT` and `COUNTRY_RESOLVERS` hold their `.env.test` values inside a test process, and that a variable those files do not define — `DATABASE_URL` — is left exactly as the process received it, so CI keeps its own connection settings.
- [ ] 8.2 A missing file or an undefined variable sets nothing. Verify: the test asserts that no variable is set to an empty string by the mechanism, because an empty `APP_SECRET` or an empty salt would be worse than the defect being fixed.
- [ ] 8.3 The demonstrated failing input, executed and recorded with its command and output: with the re-application removed, the container's `.env` values win and the redirect suite fails exactly as it did when the defect was found (ten failures, `COUNTRY_RESOLVERS=header,geolite2` in force); restored, the whole suite passes without `-e COUNTRY_RESOLVERS=fixed`.
- [ ] 8.4 The claim sweep: `docs/how-to/local-development.md` says nothing that implies a local run needs an environment override, and no document tells a reader to pass one. Verify: `rg -n 'COUNTRY_RESOLVERS' docs/ README.md` reviewed.

## 9. Wrap-up

- [ ] 9.1 `make check` green **inside the container without any `-e` override**; `openspec validate harden-quality-and-docs --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated with the benchmark numbers and the demonstrated failing inputs.
- [ ] 9.2 Green Actions run on the exact branch head: the user pushes the change branch; the executor queries `https://api.github.com/repos/Azazu/linkboard/actions/runs?branch=change/harden-quality-and-docs` until the run for `git rev-parse HEAD` is `completed` / `success`; URL and SHA recorded in `handoff.md`. Verify: the run's `head_sha` equals the branch head.

## After every task above is complete — the gate, not a task

Gate 2 is requested once section 9 is done, and it is deliberately not a
checkbox: `scripts/gate-run.sh` runs `scripts/pregate-verify.sh gate2` first,
and that floor rejects any unchecked task — so a task that included "run Gate 2
and disposition its findings" could never be both truthful and satisfied
(Gate 1 round 1, finding 1). The lifecycle step is:

1. `scripts/gate-run.sh harden-quality-and-docs 2 full`.
2. Fix every finding, update its Status in `review.md`, and re-review with
   `scripts/gate-run.sh harden-quality-and-docs 2 confirm <round>`.
3. The gate has passed when the last Gate 2 record reads `approved` or
   `confirmed` with no finding row left `open`; `scripts/workflow-verify.sh
   merge harden-quality-and-docs` is what checks that before the merge.
