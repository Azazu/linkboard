# Review — stretch-public-hosting

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-17
**Reviewed-Commit:** e516e99d35e294612f2fb6b986a5362f4c218503
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | `specs/deployment/spec.md` “The public demo instance exposes one account and no registration”; `design.md` decision 5; `tasks.md` 6.1, 6.2, 9.3 | The planned scheduled `app:demo:seed --reset` cannot preserve usable published demo credentials. The current command generates new random passwords on every run and prints them only to the operator's console; task 9.3 explicitly keeps credentials out of the README and merely names that command. Thus the first scheduled reload invalidates whatever credentials a visitor was given, with no planned public channel for obtaining the replacements. Specify a safe credential/publication mechanism and add verification that a visitor can still sign in after a scheduled reload. | fixed |
| 2 | blocker | `specs/deployment/spec.md` “The production image is self-contained” and “A missing or default secret stops the boot”; `tasks.md` 1.1, 1.4, 3.1 | Two required guarantees currently contradict at the exact build step: task 1.1 warms the production cache by booting the kernel, while task 1.4 requires that build to run with `APP_SECRET`, `JWT_PASSPHRASE`, and `VISITOR_HASH_SALT` absent; task 3.1 makes any such boot fail. Define a build-time mechanism that can compile a production cache without secrets yet cannot bypass the runtime check or bake placeholders into the image, and add verification for both halves. | fixed |
| 3 | blocker | `proposal.md` “A production compose profile”; `design.md` decisions 1–2; `tasks.md` 1.2, 2.1, 2.2 | The artifact flow between the self-contained PHP-FPM image and Caddy is missing. Compiled AssetMapper files exist only under `/app/public` in the PHP image, while the separate Caddy container has neither that filesystem nor a bind mount and PHP-FPM cannot serve static files. No task supplies the public tree to Caddy or verifies an asset through the proxy, so the required web UI cannot be served by the proposed stack. Choose and specify the image/volume topology and verify a compiled asset over HTTPS. | fixed |
| 4 | blocker | `proposal.md` secret contract and production image; `tasks.md` sections 1, 2, 3, and 9 | A clean production host has no JWT signing keypair. `config/jwt/` is gitignored, the image therefore cannot copy the existing local `config/jwt/prod/*.pem`, and the tasks neither generate/persist/mount a production keypair nor verify token issuance in the clean stack. `JWT_PASSPHRASE` alone is insufficient. Add an explicit key-provisioning lifecycle that keeps the private key out of image layers and the repository, plus a clean-host verification of `POST /api/v1/auth/token` and authenticated API use. | fixed |
| 5 | major | `specs/deployment/spec.md` “A missing or default secret stops the boot”; `design.md` decision 4; `tasks.md` 3.1–3.4, 3.7 | “Database and Redis credentials” are not defined in terms of the effective settings the application consumes. Today PostgreSQL uses both `DB_PASSWORD` and `DATABASE_URL`, while Redis is independently referenced by `REDIS_URL`, `LOCK_DSN`, and `MESSENGER_TRANSPORT_DSN`; checking a parallel password variable would still allow one of those DSNs to retain the committed credential or omit Redis authentication. Name the authoritative variables/derivation mechanism and test every effective consumer, including mismatch/default cases. | fixed |
| 6 | major | `design.md` Applicability, “Concurrent writers”; `tasks.md` section 6 | The applicability table's reason for marking concurrency n/a is factually false: `app:demo:seed` does not take a lock. A single host does not prevent two scheduled executions from overlapping after a slow run or scheduler restart, and the reset deletes and recreates the same accounts. Specify either enforced non-overlap or command-level locking and add a concurrent-run verification before treating this high-tier question as closed. | fixed |
| 7 | major | `proposal.md` Why and Non-goals; `specs/deployment/spec.md` public-demo requirement; `tasks.md` 9.3–9.4 | The declared scope says no host/domain is provisioned and nothing becomes externally visible, but task 9.3 requires the README to contain “the demo link” and task 9.4 records roadmap row 16—whose stated outcome is a deployed public host—as delivered. No real URL can be produced or verified within the declared lifecycle. Reconcile the requirement/roadmap with the user's deployment-ready decision, or make the user's deployment and resulting URL an explicit feasible prerequisite with verification. | fixed |
| 8 | major | `tasks.md` 1.1 | The exact image-build command uses `.docker/php` as its build context, but the production stage is required to copy the repository source; Docker cannot copy files outside that context. The parenthetical “with the build context the stage needs” does not make the documented command runnable. Specify one exact command (and matching Compose build configuration) whose context includes the application while still using `.docker/php/Dockerfile`. | fixed |
| 9 | major | `tasks.md` 8.1–8.2 | Gate 2 evidence is not feasible as assigned: “the job appears in the run” and a deliberately red scratch-commit run require publishing commits to GitHub, but agents may not push and no user/manual prerequisite is named. Replace this with an in-scope reproducible verification or explicitly assign and order the external user action so the task can be completed before Gate 2. | fixed |

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-17
**Reviewed-Commit:** ccd45f8b775f08db4be403dc619c4bfbd72bc89a
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed |
| 2 | confirmed |
| 3 | confirmed |
| 4 | changes-requested — The volume and clean-host token checks cover sequential replacement, but the lifecycle is still unsafe on the clean start that matters: design decision 2 says every application container runs the entrypoint, while tasks 2.1 and 4.1 start PHP, worker, and scheduler against the same key volume. `lexik:jwt:generate-keypair --skip-if-exists` generates a candidate pair before checking whether either file exists and writes the two files separately, so concurrent first starts can both observe an empty volume and leave a private key from one run with a public key from another. Specify single-writer ordering or locking around first-time provisioning, and verify a concurrent clean-stack start produces one matching pair before dependent processes serve work. |
| 5 | changes-requested — The application-side set is now enumerated, but the PostgreSQL credential contract remains incomplete. The shipped stack supplies the server through `DB_PASSWORD`/`POSTGRES_PASSWORD` while Doctrine consumes `DATABASE_URL`; the revised artifacts neither name an authoritative derivation between them nor test their mismatch/default cases. A changed `DATABASE_URL` can therefore pass the startup check while PostgreSQL still receives the committed `DB_PASSWORD`, or the two can disagree and make the stack fail later. Define the production Compose inputs/derivation and verify both effective consumers and mismatch handling. |
| 6 | confirmed |
| 7 | confirmed |
| 8 | confirmed |
| 9 | confirmed |

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-17
**Reviewed-Commit:** c3f631ab77dd9664427e71d4ef21301695edce8f
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed |
| 2 | confirmed |
| 3 | confirmed |
| 4 | changes-requested — The one-shot init service resolves the concurrent-writer race, but the specified lifecycle still cannot recover from a crash between the key generator's two file writes. The installed `lexik:jwt:generate-keypair --skip-if-exists` treats either file existing as success, so a restart after only `private.pem` was written leaves the pair incomplete while dependents are allowed to start. This contradicts the new requirement that provisioning not leave half-written assets, and `design.md`'s applicability claim that the next start repairs this case; no task interrupts generation between the writes and verifies recovery. The design also still says in decision 2 and the opening of decision 3 that each entrypoint/application container generates the keypair, before later saying provisioning is not in those entrypoints. Specify crash-safe publication or cleanup/regeneration of a partial pair, add the corresponding interrupted-run verification, and reconcile the stale entrypoint statements. |
| 5 | changes-requested — The authoritative inputs are now named, but the planned derivation does not receive them through the documented commands. Compose interpolation reads the shell, its project `.env`, or `--env-file`; a service-level `env_file: .env.local` only populates the already-created container. Tasks 2.1, 3.8, 4.1 and the surrounding design use `docker compose -f docker-compose.yml -f docker-compose.prod.yml ...` without `--env-file`, while the credentials are generated into `.env.local`. The derived DSNs and the Postgres/Redis server settings can therefore still resolve from committed defaults instead of the claimed authoritative production credentials. Define one exact interpolation source/invocation used by deploy and verification, and verify the rendered server settings and every derived consumer come from it. |
| 6 | confirmed |
| 7 | confirmed |
| 8 | confirmed |
| 9 | confirmed |

## Confirmation 3 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-17
**Reviewed-Commit:** 7ca512838966380e437d4eac758fcc33d85a2555
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed |
| 2 | confirmed |
| 3 | confirmed |
| 4 | changes-requested — The partial-pair repair path is now specified, but the artifacts also require atomic publication without defining an operation that can provide it. `design.md` and task 2.2 say the two generated files are moved into their final location once both exist; two file moves are still separate operations, so a crash between them exposes exactly the partial final state the new requirement says SHALL never become visible. The task's verification starts with a pre-existing lone `private.pem`, which exercises restart repair but does not interrupt the proposed publication step. Either specify a genuinely atomic directory/symlink switch and verify interruption at that switch, or state the actual safety mechanism consistently — dependants remain stopped after a partial move and the next init removes and regenerates the pair — and verify an interruption between the two final moves followed by a successful repair before dependants start. |
| 5 | changes-requested — The authoritative interpolation source is now specified, but task 2.4 still runs the production-stack check as `docker compose exec php ...` without `--env-file .env.local` or the production compose files. That contradicts design decision 6 and task 3.9's claim that every production invocation uses the single documented form; indeed, the exact `rg` verification in 3.9 will report this line. Use the documented production invocation for this check as well (and keep the planned repository-wide assertion), so the command targets the same rendered stack and credentials it is meant to verify. |
| 6 | confirmed |
| 7 | confirmed |
| 8 | confirmed |
| 9 | confirmed |
