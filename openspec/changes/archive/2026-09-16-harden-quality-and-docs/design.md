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

- Touching the gate floor's composition — the PHPStan level, `make check`'s steps, the CI jobs. That is row 13a. The one floor-adjacent change here is decision 6, which the user's tier decision brought in deliberately.
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

### 6. The test environment is resolved from `.env.test`, in the test bootstrap

`tests/bootstrap.php` re-applies, after Symfony's `bootEnv`, exactly the variables `.env.test` (and `.env.test.local`, per the same convention) defines — and nothing else. A variable that file does not name is left as the process found it. Each file is published to `$_ENV`, `$_SERVER` and `putenv()` before the next is parsed, so a value in `.env.test.local` that refers to one in `.env.test` resolves against it rather than against the process environment.

The resolution itself lives in `App\Tests\TestEnvironment::apply()` rather than in the bootstrap file, because the key generator of decision 6a has to produce the same values (Gate 2 confirmation 1, finding 1): two implementations of one precedence drifted twice, so there is one implementation and both entry points call it.

*Why here and not elsewhere.* The defect is that a real environment variable beats a dotenv file, by design, and compose puts `.env` into the process. Fixing it in `docker-compose.yml` would mean listing test variables on a service that also serves the application; fixing it in `.env` would mean deleting values the application needs. The test bootstrap is the one place that knows a test process is running and is allowed to be opinionated about it.

*Why only the variables that file defines.* CI sets `DATABASE_URL`, `REDIS_URL`, `LOCK_DSN` and `MESSENGER_TRANSPORT_DSN` deliberately, and `.env.test` names none of them — so they stay CI's. Overriding everything would take CI's own configuration away from it, which is the one way this fix could break the thing it is meant to make consistent.

*What this does not guarantee.* It makes a test process resolve what `.env.test` declares; it does not make the application do so, and it does not stop a future variable from being added to `.env` alone. The guard against that is a test: the four variables are asserted to hold their `.env.test` values, so a divergence fails rather than being discovered by ten confusing failures a month later.

### 6a. The test keypair is generated with the effective test passphrase, and a key that does not match it is replaced

`make jwt-keys` keeps generating the development keypair from `.env`. For the test one it resolves the passphrase by running the code the test bootstrap runs — `App\Tests\TestEnvironment::apply()`, through `scripts/test-jwt-passphrase.php` — so the two entry points cannot disagree about which value is in force (Gate 1 round 2, finding 2; Gate 2 confirmation 1, finding 1). The value still lives only in those files; the recipe reads it, it is not copied anywhere.

**What the legacy state actually is, measured.** The old target's key is not unencrypted, as this design first claimed: it is *encrypted with an empty passphrase*. Three states, each generated and probed with both passphrases while correcting this decision:

| key | header | opens with an empty passphrase | opens with the declared one |
|---|---|---|---|
| written by the old target inside the container | `ENCRYPTED PRIVATE KEY` | opens | **refuses** |
| unencrypted (`openssl genpkey` with no cipher) | `PRIVATE KEY` | opens | opens |
| written with the declared passphrase | `ENCRYPTED PRIVATE KEY` | **refuses** | opens |

So "does it open with the declared passphrase?" is not the detection: it keeps an unencrypted key, which opens with anything — the case Gate 1 round 2 raised and reproduced. The detection is the pair of answers: **when a non-empty passphrase is in force, the private key must refuse an empty passphrase and accept the declared one.** That replaces both wrong states and keeps the right one.

The two wrong states are wrong in different ways, and the distinction is worth keeping straight (Gate 1 confirmation of round 2): the empty-passphrase key **cannot be used at all** with the passphrase in force, while the unencrypted key *can* still sign — it simply does not carry the encryption this environment declares. Both are replaced, one because it is broken and one because it is not what was asked for.

A private key alone is not a usable keypair, so the target also checks that the stored public key is the one that belongs to it (the public key derived from the private one must equal the stored file). A replacement writes two files, and the two are not written atomically: if the target dies between them the pair is mismatched, so the check runs on every invocation rather than only after a write, and the fix for a half-written pair is to run the target again.

*Why in the target and not in the console command.* A console process has the same problem a test process had, and for the same reason — compose's environment beats the file — but unlike `tests/bootstrap.php` there is no place in the application that knows "this is a test-environment invocation" and may override. The recipe that already says `--env=test` is that place.

*What this does not guarantee.* It fixes the documented entry points, `make jwt-keys` and the `make init` that calls it. Someone running the console command by hand still gets the container's environment, and nothing here can prevent that. Nor does the check prove the application can sign with the key — that is what the authentication suite proves, which is why the verification ends with the suite rather than with OpenSSL.

## Applicability

| Question | Answer |
|---|---|
| Authorization boundary | None is added or moved. The fixes change which values a *test* process reads, and which passphrase a *test* keypair is generated with for `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT` and `COUNTRY_RESOLVERS` — from the development values it has been silently using to the test values `.env.test` declares. The application's own resolution is untouched, and the evidence is a test that asserts each of the four. |
| Empty / zero / null inputs | A missing `.env.test`, or a variable it does not define, must leave the process environment alone rather than setting an empty value — asserted, because an empty `APP_SECRET` or salt would be worse than the defect being fixed. |
| Crash before/after an external effect | The key replacement writes `private.pem` and `public.pem` separately, so a crash between them leaves a mismatched pair. The check therefore runs on every invocation, not only after a write, and compares the stored public key with the one derived from the private key — so a half-written pair is detected and repaired by running the target again. Nothing else here has an external effect: the bootstrap reads files and sets variables. |
| Concurrent writers | Two `make jwt-keys` runs at once could interleave two key writes. It is a developer setup command run by hand, the loser's pair is detected as mismatched by the next invocation, and the repair is to run it again — accepted rather than locked. |
| Deletion / expiry | n/a. |
| Idempotency of retries | The bootstrap runs once per test process and is idempotent by construction: it assigns values, it does not accumulate. `make jwt-keys` is idempotent in a stronger sense than before — it now leaves a matching key alone and replaces a mismatched one, so running it twice converges instead of preserving a broken key forever. |
| Money rounding | n/a — no monetary value exists in this project. |

## Risks / Trade-offs

- **A benchmark that flatters** → every number is published with the command, the dataset size and the machine, and a missed target is stated as missed. The section is worthless if it is a sales page.
- **The architecture rules pass vacuously** → each is demonstrated failing on a planted violation before it is believed, and each test asserts it actually scanned files (an empty file list fails).
- **Screenshots and diagram drift** → both are regenerable from one documented command, and the diagram's elements are checked against `src/` while writing it.
- **The fix hides a real difference rather than fixing it** → the opposite risk: if a test genuinely needs a production-shaped value it now gets the test one. The four variables are named in the design and asserted in a test, so the set is visible rather than implicit.
- **Taking CI's own configuration away from it** → only the variables `.env.test` defines are re-applied, and CI's four connection variables are not among them; the CI run on the branch head is what proves it.
- **A detection that keeps a broken key** → the shape of Gate 1 round 2's finding, and the reason the rule is now "refuses empty *and* accepts declared" rather than "opens". All three key states were generated and probed, and the table in decision 6a is what the rule was written from.
- **Replacing a key a developer wanted** → the target only replaces a test key that cannot be used with the passphrase in force; the development keypair is untouched, and the test keys are gitignored artifacts a regeneration costs nothing.
- **Scope creeping further into the floor** → the PHPStan level, `make check`'s steps and the CI jobs remain row 13a's. The `Makefile`'s `jwt-keys` target is in scope here by the user's decision of 2026-09-15; nothing else in that file is, and the proposal's untouched list says the same. `phpstan.dist.neon`, the `Makefile` and `.github/workflows/` are named in the proposal as untouched, and a task that finds itself needing them stops and raises the tier.

## Migration Plan

Nothing to migrate: no schema, no data, no behaviour, no configuration. The change adds documents and tests.
