# Tasks — harden-quality-and-docs

Tier `high`, raised by the user on 2026-09-15 when implementation found that a
test run inside the container does not resolve the configuration `.env.test`
declares (proposal, "User decisions"). Gate 1 on the artifacts before the fix
is written, Gate 2 on the diff, and a demonstrated failing input for the
defect. Sections 1–6 were implemented while the tier was `low` and touched only
documents and tests; section 8 is the fix the raised tier is about, and it is
not started until Gate 1 passes.

`phpstan.dist.neon`, the CI workflow, `docker-compose.yml` and `.env` stay
untouched — the floor's composition is row 13a's. The `Makefile` is in scope
for its `jwt-keys` target only, by the user's decision of 2026-09-15, and for
nothing else in that file.

## 1. The architecture rules of NFR-QA-2

- [x] 1.1 `tests/Unit/Architecture/AnalyticsKeepsItsDistanceTest.php`: no file under `src/Analytics/` names a `Click` entity (design decision 2). Verify: the test lists every offending path; it asserts it scanned a non-empty set of files, so an empty scan fails rather than passes; and a demonstrated failing input, executed: importing `App\Click\Entity\Click` into `BreakdownQuery` fails it with `src/Analytics/Query/BreakdownQuery.php names App\Click\Entity\`, and it passes again once removed.
- [x] 1.2 `tests/Unit/Architecture/EntitiesStayMappedTest.php`: a file under a `Entity/` directory references `Doctrine\ORM` only through mapping attributes. Verify: as above, with a demonstrated failing input, executed: `use Doctrine\ORM\EntityManagerInterface;` in `Link` fails it with `src/Link/Entity/Link.php imports Doctrine\ORM\EntityManagerInterface`.
- [x] 1.3 `tests/Unit/Architecture/ControllersDoNotQueryTest.php`: no controller file contains `EntityManager`, `createQueryBuilder`, `createQuery` or an SQL string. Verify: as above, with a demonstrated failing input, executed: a `createQueryBuilder()` call planted in `HomeController` fails it with `src/Web/Home/HomeController.php contains createQueryBuilder`. The rule deliberately permits `EntityManagerInterface::flush()`, which `RegistrationController` uses: a unit-of-work commit is not a query. That controller flushing directly rather than through a use case is worth a later change; it is recorded in `handoff.md` rather than refactored here, since this change touches no production code.
- [x] 1.4 The three rules state what they cannot see. Verify: each test's docblock names the evasion it does not catch (a variable class name, a query built in a collaborator), so nobody reads a passing suite as a proof it is not.

## 2. Benchmarks

- [x] 2.1 The redirect's p95 (NFR-PERF-1). Verify: measured and recorded in `docs/how-to/benchmarks.md` with the machine and the command — prod-like, one connection: p50 20.46 ms, p90 22.11 ms, p99 25.26 ms, **target met**. Re-run after Gate 2 round 1 made the recipe self-contained (the slug query and the concrete slug), the published distribution is the one the published commands produce. Beside it, each with its own printed command: `/health` at the same concurrency, p50 1.82 ms, so the redirect's own work is ~19 ms; and four connections against a five-child pool, p50 17.4 / p90 97.3 / p99 134.7 ms — published as queueing, not server time.
- [x] 2.2 A report's p95 uncached on a million clicks (NFR-PERF-2). Verify: the recipe is `scripts/report-benchmark.sh`, written for this purpose in Gate 2 round 1 because the prose recipe it replaced could not be executed as printed (round 1, finding 2). Run in its exact published form after `app:demo:seed --clicks=1000000 --days=60 --reset`, on 1 020 279 rows: it authenticates both accounts — writing it caught that the demo owner gets 403 on the three global reports, which need `ROLE_ADMIN` — picks the most-clicked link, derives a 30-day period, and issues 20 requests per report with `cache:pool:clear cache.reports` before every single one, failing the run on any non-200. Twenty rather than fifteen because with nearest-rank percentiles over 15 samples the 95th *is* the maximum. Eight of the nine meet the target; **the global top-links report does not** — p95 321.9 ms against 300 ms, consistent with the 303 ms and prod-like 309 ms measured earlier — published as a miss with the number and with what the specification already names as the answer.
- [x] 2.3 The worker's throughput (NFR-PERF-3). Verify: the load run of `docs/how-to/benchmarks.md` section 3, run exactly as published, queued 11 925 messages by real redirects with no worker running, then exactly 10 000 were drained by `messenger:consume async --limit=10000` under `time`: **11.09 s → 902 messages/s, target met**. An earlier attempt that polled the queue length every two seconds reported 488/s; it was replaced because the polling granularity, not the worker, produced that number. A run before the recipe was made self-contained measured 15.55 s → 643/s; the published figure is the one the published commands produce.
- [x] 2.4 Every benchmark command published is a command that was run in that exact form. Verify: after Gate 2 round 1 finding 2, all three sections were re-executed as printed — section 1 including the slug query, the `docker-compose.bench.yml` override, `cache:warmup`, and the three `wrk` runs; section 2 through `scripts/report-benchmark.sh`; section 3 including `redis-cli del messages`, the 90-second load run and the timed drain — and every figure in the document and in the README replaced by the output of that run. Section 3 also gained the fact that made it non-reproducible: its load run needs section 1's raised limiter still in place, so section 1 no longer tells the reader to delete the override before section 3 is done.

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
- [x] 6.6 The records describe the code as it is (Gate 2 round 1, finding 3). Verify, read against `src/Click/Handler/ClickRecordedHandler.php` and `src/Click/Recorder/MessengerClickRecorder.php`: ADR-002 and `docs/explanation/architecture.md` no longer say the handler persists an entity — neither path hydrates a click, the handler runs one DBAL transaction that inserts the row and increments the counter — and they now distinguish the two failure modes instead of implying every failure queues: a dispatched message whose handling fails is retried and ends in the failure transport, while a **dispatch** that fails is caught, logged at error, and **that click is lost**.

## 7. Gate 1

- [x] 7.1 Request Gate 1 on the corrected artifacts (`scripts/gate-run.sh harden-quality-and-docs 1 full`) and disposition every finding before section 8 starts. Verify: the last Gate 1 record reads `confirmed` or `approved` with no finding row left `open`.
- [x] 7.2 Request Gate 1 again for the scope change of section 9 — the `Makefile` leaves the untouched list (proposal, "User decisions", 2026-09-15). Verify: a second Gate 1 record bound to the commit that carries the corrected artifacts reads `approved`/`confirmed` before section 9 is written; AGENTS.md reopens Gate 1 for a change of scope, and this is one.

## 8. The test environment resolves what it declares

- [x] 8.1 `tests/bootstrap.php` re-applies the variables `.env.test` and `.env.test.local` define, and only those (design decision 6). Verify: `tests/Integration/TestEnvironmentTest.php` asserts every declared variable is in force in both `$_ENV` and the process environment, names the four that used to lose so dropping one from `.env.test` fails rather than shrinking the set quietly, and asserts the four connection variables are **not** declared there and that `DATABASE_URL` still reaches the suite from wherever it was set.
- [x] 8.2 A missing file or an undefined variable sets nothing. Verify: the same test asserts no declared variable carries an empty value, and the bootstrap skips a file that does not exist, so a repository without `.env.test.local` is unaffected.
- [x] 8.3 The demonstrated failing input, executed and recorded:

  ```
  # with the re-application removed from tests/bootstrap.php
  docker compose exec -T php sh -c 'rm -rf var/cache/test'
  docker compose exec -T php vendor/bin/phpunit tests/Integration/TestEnvironmentTest.php tests/Web/Redirect/RoutingRulesTest.php
  ```

  fails seven: both environment assertions and the five routing tests that need the fixed country map — the container's `.env` values win again. Restored and re-run: `OK (16 tests, 154 assertions)`. The whole suite then passes **with no `-e` override**: `OK (848 tests, 11090 assertions)`.
- [x] 8.4 The claim sweep: `docs/how-to/local-development.md` says nothing that implies a local run needs an environment override, and no document tells a reader to pass one. Verify: `rg -n 'COUNTRY_RESOLVERS' docs/ README.md` reviewed — the how-to's note now says the test map holds inside the container as well, and why; nothing anywhere tells a reader to pass an override.

## 9. Key generation declares the same authority

- [x] 9.1 `make jwt-keys` resolves the test passphrase the way `tests/bootstrap.php` resolves every test variable — `.env.test`, then `.env.test.local` when it exists — and generates the test keypair with it; the development keypair keeps coming from `.env` (design decision 6a). Verify, executed: with `config/jwt/test/` removed, `make jwt-keys` reports `keypair generated with the declared passphrase`, and the result refuses an empty passphrase, accepts the declared one and matches its public key. The work lives in `scripts/test-jwt-keys.sh`, which the target calls; it is POSIX `sh` and runs natively too, which is how CI invokes it (`make jwt-keys EXEC=`).
- [x] 9.2 A test key that cannot be used with the passphrase in force is replaced, not skipped — for **both** non-compliant states, which were measured while correcting the design (Gate 1 round 2, finding 1) — the empty-passphrase key cannot be used with the passphrase in force, the unencrypted one can still sign but carries none of the encryption this environment declares. Verify, each executed and recorded: **(a)** the state the old target writes — `bin/console lexik:jwt:generate-keypair --env=test` inside the container — is replaced (`b5433b69…` → `092a1ae7…`, and the new key refuses an empty passphrase); **(b)** an unencrypted key written with `openssl genpkey` is replaced (`-----BEGIN PRIVATE KEY-----`, `647f5d05…` → `-----BEGIN ENCRYPTED PRIVATE KEY-----`, `75f3bfc9…`) — the case an "opens with the declared passphrase" check would have kept; **(c)** a compliant keypair is left byte for byte, both files' digests unchanged across a run that reports `the keypair matches the passphrase the test environment declares`.
- [x] 9.3 A mismatched pair is repaired by running the target again. Verify, executed: with `public.pem` overwritten by another key's public half, the target reports the mismatch, regenerates both, and the pair matches again — the check runs on every invocation, so a crash between the two writes is repaired by running the target again.
- [x] 9.4 The local override is honoured by both entry points. Verify, executed: with a `.env.test.local` declaring `JWT_PASSPHRASE=local-override-passphrase-for-this-check`, the generated key accepts that value and refuses the committed one, and `tests/Api/Auth` passes (36 tests, 323 assertions) — the suite resolves the same precedence. The file was removed afterwards and the default keypair regenerated.
- [x] 9.5 `make init`'s path end to end produces a working test setup. Verify, executed: `config/jwt/test/` and `var/cache/test` removed, then `make jwt-keys` and `tests/Api/Auth tests/Web/Security` with no environment override — `OK (43 tests, 359 assertions)`. The suite is what proves the key is usable; OpenSSL only proves it opens.
- [x] 9.6 The passphrase is resolved by the same parser, not a lookalike (Gate 2 round 1, finding 1). Verify, executed on the reviewer's own input: `JWT_PASSPHRASE=review-fixture # local comment` in `.env.test.local` — the old in-script `sed` read `review-fixture # local comment`, Symfony's Dotenv reads `review-fixture`; the key generated with the resolved value accepts `review-fixture` and refuses the comment-tainted string, and `tests/Api/Auth` passes (36 tests). Same with expansion: `JWT_PASSPHRASE="${PASS_BASE}-tail"` resolves to `expanded-base-tail`. The resolution lives in `scripts/test-jwt-passphrase.php`, which uses `Symfony\Component\Dotenv\Dotenv` with the precedence `tests/bootstrap.php` applies, and `make jwt-keys` passes its output to `scripts/test-jwt-keys.sh` as an argument — so the shell script parses no dotenv file at all.
- [x] 9.7 The claim sweep: `docs/how-to/local-development.md` and `docs/reference/commands.md` describe what the target now does, and no document repeats the corrected claim that the legacy key is unencrypted. Verify: both re-read whole after the last edit; `rg -n 'jwt-keys|JWT_PASSPHRASE' docs/ README.md Makefile` reviewed — the how-to's troubleshooting entry no longer tells a reader to delete the test keypair by hand, because the target repairs it.

## 10. Wrap-up

- [x] 10.1 `make check` green **inside the container without any `-e` override** (848 tests, 11 090 assertions, re-run after section 9); `openspec validate harden-quality-and-docs --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated with the benchmark numbers and the demonstrated failing inputs.
- [x] 10.2 Green Actions run on the exact branch head: the user pushes the change branch; the executor queries `https://api.github.com/repos/Azazu/linkboard/actions/runs?branch=change/harden-quality-and-docs` until the run for `git rev-parse HEAD` is `completed` / `success`; URL and SHA recorded in `handoff.md`. Verify: the run's `head_sha` equals the branch head — run 35064944178 on `b33d46a`, `completed`/`success`, which is also what proves `make jwt-keys EXEC=` works natively.

## After every task above is complete — the gate, not a task

Gate 2 is requested once section 10 is done, and it is deliberately not a
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
