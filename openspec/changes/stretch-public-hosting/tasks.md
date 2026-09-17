# Tasks — stretch-public-hosting

Tier `high`: every new or changed guard carries a demonstrated failing input — a
test that fails when the guard is removed — not only a test that passes.

Every command below is runnable as written from the repository root. Where a step
needs a public host name, it is marked as the user's manual step and produces no
checked task.

## 1. The production image

- [ ] 1.1 Add a `.dockerignore` excluding `var/`, `vendor/`, `.git/`, `config/jwt/` and every env file, so the repository-root build context carries neither local state nor a stray `.env.local` into an image. Verify, executed: `docker build -f .docker/php/Dockerfile --target prod -t linkboard-prod .` and the reported context size recorded before and after the file exists.
- [ ] 1.2 Add the `prod` stage to `.docker/php/Dockerfile` (design decision 1): `composer install --no-dev --classmap-authoritative`, the source copied in, a fixed non-root user, production PHP settings with `opcache.validate_timestamps=0`. **No step boots the kernel** (design decision 2). Verify, executed: the command in 1.1 succeeds and `docker run --rm --entrypoint sh linkboard-prod -c 'id && php -i | grep opcache.validate_timestamps'` prints the fixed user and `0`.
- [ ] 1.3 The build needs no secret and bakes none. Verify, executed: the build runs in a shell with `env -u APP_SECRET -u JWT_PASSPHRASE -u VISITOR_HASH_SALT` and succeeds; then `docker history --no-trunc linkboard-prod` and a layer search show no value for any required setting.
- [ ] 1.4 Development dependencies are absent. Verify, executed: `docker run --rm --entrypoint sh linkboard-prod -c 'ls vendor/bin'` lists no `phpunit`, `phpstan` or `php-cs-fixer`.
- [ ] 1.5 Demonstrated failing input for decision 2: add `bin/console cache:warmup` to the production stage and show the build failing on the setting check once section 3 exists — the output recorded — then remove it. This is the collision the decision is about, so it is shown rather than asserted.

## 2. Start-time provisioning

- [ ] 2.1 Write the **one-shot init service** that provisions (design decision 3, Gate 1 confirmation 1, finding 4): it compiles the asset map into the assets volume and runs `bin/console lexik:jwt:generate-keypair --skip-if-exists` into the keys volume, and it is the only writer of either. The web, worker and scheduler services declare `depends_on: { init: { condition: service_completed_successfully } }` and their own entrypoint warms only their private cache. Verify, executed: `docker compose --env-file .env.local -f docker-compose.yml -f docker-compose.prod.yml up -d` on empty volumes, with the init service's output and the three services' start order recorded.
- [ ] 2.2 **A partial pair is never served, and the next run repairs it** (Gate 1 confirmations 2 and 3, finding 4). Two file moves are two operations, so nothing here claims an atomic publication; the guarantee is the one the stack actually provides: provisioning generates into a temporary directory inside the same volume and moves both files into place, and **if it dies at any point the init service exits non-zero, so `service_completed_successfully` is false and no dependant starts**. Before deciding anything, the next run discards what it finds if the location holds one file alone, or a pair whose public key is not the one derived from its private key, and generates afresh. Implement that, and make the requirement's wording match it rather than promising atomicity.
- [ ] 2.3 Verify the interruption the finding names, executed: interrupt provisioning **between the two final moves**, and record that (a) the init service exited non-zero, (b) the web, worker and scheduler services did not start, and (c) the next `up` removed the partial pair, generated a complete matching one, and only then let the dependants start — the four states recorded from `docker compose --env-file .env.local -f docker-compose.yml -f docker-compose.prod.yml ps` and the key diff.
- [ ] 2.4 Demonstrated failing input for the repair: keep `--skip-if-exists` as the only test, leave only `private.pem` in the volume, and record the stack coming up on half a pair — then restore the repair step.
- [ ] 2.5 **A concurrent clean start yields one matching pair.** Verify, executed: remove the keys volume, bring the **whole stack up at once**, then `docker compose --env-file .env.local -f docker-compose.yml -f docker-compose.prod.yml exec php sh -c 'openssl rsa -in config/jwt/prod/private.pem -passin env:JWT_PASSPHRASE -pubout | diff - config/jwt/prod/public.pem'` prints nothing — the pair matches — and exactly one keypair exists. Repeat it five times and record each result, because a race that only sometimes loses is still a race.
- [ ] 2.6 Demonstrated failing input for the single writer: move provisioning back into the three services' own entrypoints, run 2.5's loop, and record the mismatched pair when it appears — then restore the init service.
- [ ] 2.7 The keypair survives replacement. Verify, executed: obtain a token, `docker compose --env-file .env.local -f docker-compose.yml -f docker-compose.prod.yml up -d --force-recreate php`, use the same token and get 200 — the two responses recorded.
- [ ] 2.8 A clean host issues tokens at all. Verify, executed: `docker volume rm` the keys volume, bring the stack up, seed, and `POST /api/v1/auth/token` returns a token that an authenticated request accepts.
- [ ] 2.9 Provisioning is idempotent. Verify, executed: restart the container twice and show the keypair's fingerprint unchanged and the assets present after each.
- [ ] 2.10 The private key is nowhere it should not be. Verify, executed: `docker history`/layer search, `git check-ignore -v config/jwt`, and a grep of the start-up output all come back clean — the three commands and their output recorded.

## 3. The required settings

- [ ] 3.1 Implement the startup check (design decisions 2 and 6) as a service tagged `app.startup_check` over exactly `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT`, `DATABASE_URL`, `REDIS_URL`, `LOCK_DSN`, `MESSENGER_TRANSPORT_DSN`, reading the committed defaults from `.env` itself rather than from a copy in PHP. Unset, empty and still-committed each fail the boot naming the setting and never the value.
- [ ] 3.2 Every setting is covered one at a time. Verify: a data-provider test boots the kernel once per setting with that one unset and asserts the failure names it — seven cases, so a setting added to the list without a case fails the count assertion.
- [ ] 3.3 **The committed-default case, which is the one that actually happens.** Verify: the same provider boots with each setting set to the exact value committed in `.env` and asserts the boot fails naming it — including `DATABASE_URL`, `REDIS_URL`, `LOCK_DSN` and `MESSENGER_TRANSPORT_DSN` separately (Gate 1 round 1, finding 5).
- [ ] 3.4 The failure discloses nothing. Verify: a test asserts the message contains the setting's name and not the value found.
- [ ] 3.5 Demonstrated failing inputs: drop the committed-default clause and show 3.3 failing; drop any one Redis setting from the list and show 3.2's count assertion failing; drop the check's tag and show 3.2 failing.
- [ ] 3.6 Every process refuses, not only the web one. Verify, executed against the prod-like stack with one setting unset: the web container, the worker and the scheduler each exit with the named failure — the three outputs recorded.
- [ ] 3.7 `.env` gains the new settings with development defaults and `.env.local` stays ignored. Verify, executed: `git check-ignore -v .env.local` names the rule, and `rg -n 'DEMO_|REGISTRATION_' .env` shows development values and no secret.
- [ ] 3.8 **One authoritative credential per store, derived into every connection string** (design decision 6, Gate 1 confirmation 1, finding 5). Implement the production compose so `DATABASE_URL` is interpolated from `DB_USER`/`DB_PASSWORD`/`DB_NAME` and `REDIS_URL`, `LOCK_DSN` and `MESSENGER_TRANSPORT_DSN` from the Redis credential, with the servers taking the same two inputs — so no second literal exists to disagree.
- [ ] 3.9 **The interpolation source is named on every invocation** (Gate 1 confirmations 2 and 3, finding 5). Compose interpolation reads the shell, the project `.env` or `--env-file` — never a service's `env_file:` — so the credentials generated into `.env.local` reach the derivation only if every invocation says `--env-file .env.local`. Make that one form the only form, in `docs/how-to/deploy.md`, in every task here and in anything CI runs. Verify, executed: `rg -n 'docker compose' openspec/changes/stretch-public-hosting/tasks.md docs/how-to/deploy.md .github/workflows/` shows **every** production invocation carrying it — including the ones that only `exec` into a running container, which is where confirmation 3 caught one that did not.
- [ ] 3.10 The rendered configuration really comes from that file. Verify, executed: `docker compose --env-file .env.local -f docker-compose.yml -f docker-compose.prod.yml config` shows the authoritative credentials in the Postgres and Redis servers' own settings **and** in each of `DATABASE_URL`, `REDIS_URL`, `LOCK_DSN` and `MESSENGER_TRANSPORT_DSN`, and no committed default anywhere in the output.
- [ ] 3.11 Demonstrated failing input: run the same `config` **without** `--env-file` and record the committed defaults appearing in the rendered output — which is the deployment this finding describes.
- [ ] 3.12 Both effective consumers really do agree. Verify, executed on empty storage: change only `DB_PASSWORD`, bring the stack up, and show the application querying the database; then do the same for the Redis credential and show the deep probe reporting both stores reachable — the outputs recorded.
- [ ] 3.13 A hand-overridden connection string is reported, not hidden. Verify, executed: override `DATABASE_URL` so it disagrees with the server, and record the deep dependency probe reporting the database unreachable rather than a later request failing arbitrarily.

## 4. The production stack

- [ ] 4.1 Write `docker-compose.prod.yml` — caddy, init, php, worker, scheduler, postgres, redis — with the worker and the scheduler under `restart: unless-stopped`, the assets and keys volumes of decision 3, and configuration from `.env.local` rather than the committed `.env`. Verify, executed: `docker compose --env-file .env.local -f docker-compose.yml -f docker-compose.prod.yml config` renders, and the file is re-read whole after the last edit.
- [ ] 4.2 Write the Caddy configuration: HTTPS with automatic certificates, HTTP permanently redirected, HSTS, `/health` as the upstream check, and the assets volume served directly. Verify, executed against the stack on an internal host name: `curl -sI http://<host>/x` is a 301 to `https://`, and the HTTPS response carries `Strict-Transport-Security`.
- [ ] 4.3 **An asset is served through the proxy** (Gate 1 round 1, finding 3). Verify, executed: fetch a page of the web UI over the stack, take an asset URL it references, request it, and record 200 with its content type; then assert every asset the dashboard references answers 200.
- [ ] 4.4 The development stack is untouched. Verify, executed: `git diff main -- docker-compose.yml` is empty and `make up && make check` is green with no edit.
- [ ] 4.5 A killed worker returns and loses nothing. Verify, executed: kill the worker container, show it running again, and show the messages queued meanwhile consumed — the counts before and after recorded.
- [ ] 4.6 The publicly trusted certificate is the user's manual step and is **not** claimed here: the deploy document states what is unverified and why (a public host name is required). No checked task asserts a real certificate.

## 5. Registration closed

- [ ] 5.1 Implement the switch as one `kernel.request` listener (design decision 5) over both registration paths, taking them from a parameter in `config/services.yaml`; default on.
- [ ] 5.2 Both entry points refuse. Verify: a test asserts `GET /register`, the `/register` form POST with a valid CSRF token, and `POST /api/v1/auth/register` each answer 404 with the switch off — the API one as problem details — and that no account was created by any of them.
- [ ] 5.3 The sign-in page stops advertising it. Verify: a test asserts the login page carries no link to `/register` with the switch off, and does carry one with it on.
- [ ] 5.4 Existing accounts are untouched. Verify: a test signs in through the web and obtains a token through the API with the switch off.
- [ ] 5.5 Default-on means nothing changed. Verify, executed: the whole existing suite passes with no edit to any existing test.
- [ ] 5.6 Demonstrated failing input, in both directions: restrict the listener to the web path and show the API half of 5.2 failing; restrict it to the API path and show the web half failing.

## 6. Seeding a declared demo instance

- [ ] 6.1 Change the `prod` guard of `app:demo:seed` to an instance setting (design decision 7), off by default; no option of the command lifts it.
- [ ] 6.2 Verify the refusal is unchanged where it must be: a test runs the command in `prod` with the setting unset and asserts exit 1 and nothing written.
- [ ] 6.3 Verify no option lifts it: a test runs it in `prod`, setting unset, with `--reset` and every other option, asserting exit 1 and nothing written.
- [ ] 6.4 Verify the opt-in works: a test runs it in `prod` with the setting on and asserts the dataset exists and the output names the setting that allowed it.
- [ ] 6.5 **The command takes a non-blocking lock** (Gate 1 round 1, finding 6). Verify: a test starts a run that is held inside its transaction (the existing `InterruptingStatement` fixture drives this) and runs a second one, asserting the second exits non-zero at once without writing, says a run is in progress, and that the first completes as if alone.
- [ ] 6.6 **The demo password comes from the instance** (Gate 1 round 1, finding 1). Verify: a test seeds with the password setting in force, resets, and asserts the same credential obtains a token after the reset — the case a generated-per-run password fails.
- [ ] 6.7 Demonstrated failing inputs: make the guard readable from an option and show 6.3 failing; remove the lock and show 6.5 failing; restore the per-run generated password and show 6.6 failing.
- [ ] 6.8 The existing all-or-nothing guarantees still hold. Verify, executed: the existing `demo-data` suite passes with no edit.

## 7. The demo instance's own credentials page

- [ ] 7.1 The sign-in page states the demo credentials when, and only when, the instance has declared itself a demo (design decision 7). Verify: a test asserts the e-mail and password appear with the demo setting on and that the page states no credentials with it off.
- [ ] 7.2 Nothing about it enters the repository. Verify, executed: `rg` for the demo password value across the working tree returns nothing, and the README says the page states it rather than stating it.

## 8. The schedule

- [ ] 8.1 Add the scheduler service running the demo reload (`app:demo:seed --reset`) and `app:click:partitions` on their cadences, named in one place.
- [ ] 8.2 Verify the reload converges rather than accumulates: run it twice against the stack and assert identical dataset counts after each — both outputs recorded.
- [ ] 8.3 Verify the partition run keeps the horizon: run it and show the partitions ahead of the current month, the `dbal:run-sql` output recorded.

## 9. The client's address

- [ ] 9.1 Set the trusted proxy for the production stack (design decision 8).
- [ ] 9.2 Verify two clients are two buckets: through the stack, two forwarded addresses each send up to the per-IP redirect limit and neither is refused — the commands and statuses recorded.
- [ ] 9.3 Verify the shipped default would have failed: with `TRUSTED_PROXIES` left at the development value, the same two clients share one bucket and the second is refused at the limit. This is the misconfiguration the requirement exists for, so it is measured rather than described.
- [ ] 9.4 A forwarded header the client sets itself is still not believed. Verify, executed through the stack.

## 10. Continuous integration

- [ ] 10.1 Add a CI job that runs **the same build command the deploy document gives** and pushes nothing. Verify: the job's step is byte-identical to the documented command — asserted by the script suite that already checks documented commands, so the two cannot drift.
- [ ] 10.2 Verify it fails on a broken image, reproducibly and locally (Gate 1 round 1, finding 9 — an agent cannot push, so a deliberately red CI run is not evidence it can produce): break the production stage, run the documented command, record the failure, restore it, run it again and record the success.
- [ ] 10.3 Verify it reaches no host: the job has no registry login, no deployment credential and no network step beyond the build — asserted by reading the workflow file whole after the edit.
- [ ] 10.4 The green CI run on the branch head is the user's push, as it is for every change in this repository, and it precedes Gate 2.

## 11. Documents and the plan

- [ ] 11.1 Write `docs/how-to/deploy.md`: provisioning the host, generating the required settings (commands that generate, never values), starting the stack, the first seed, the schedule, which volumes matter and why, the slower first start that decision 2 buys, and what a real operator would add that is not here. Verify: every command was run in its exact printed form, except those needing a public host, which are marked as the user's step.
- [ ] 11.2 Write the ADR for the hosting decision — a VPS with compose and Caddy, with the PaaS and Kubernetes alternatives and why they are refused. Verify: the file is `docs/adr/ADR-0NN-*.md` with the next free number and is referenced from the deploy document.
- [ ] 11.3 README gains the demo section: what the instance is, that registration is closed there, that the sign-in page states the demo credentials, and **the link left to be added when the instance exists** (design decision 9). Verify: the README is re-read whole after the edit and `rg` finds no credential and no invented URL in it.
- [ ] 11.4 **Split roadmap row 16** (Gate 1 round 1, finding 7): row 16 becomes the deployment configuration this change delivers, and a new row records publishing the instance and adding its link — the user's step. `docs/explanation/requirements.md` §7 is updated to match and §9's public-hosting item is **not** marked delivered. Verify, executed: `rg -n 'stretch-public-hosting' openspec/ROADMAP.md docs/explanation/requirements.md` shows the two rows and no claim of a live instance.
- [ ] 11.5 `docs/README.md` indexes the new how-to. Verify, executed: `scripts/pregate-verify.sh gate2 stretch-public-hosting` reports every markdown link resolving.

## 12. Wrap-up

- [ ] 12.1 `make check` green in the container with no environment override; `openspec validate stretch-public-hosting --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated with the measured numbers and every recorded failing input.
