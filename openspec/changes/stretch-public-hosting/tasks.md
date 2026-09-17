# Tasks — stretch-public-hosting

Tier `high`: every new or changed guard carries a demonstrated failing input —
a test that fails when the guard is removed — not only a test that passes.

## 1. The production image

- [ ] 1.1 Add a production stage to `.docker/php/Dockerfile` (design decision 1): `composer install --no-dev --classmap-authoritative`, `bin/console asset-map:compile`, cache warm, the source copied in, a fixed non-root user, production PHP settings with `opcache.validate_timestamps=0`. Verify, executed: `docker build --target prod -t linkboard-prod .docker/php` (with the build context the stage needs) succeeds and the printed image id is recorded.
- [ ] 1.2 The image is self-contained. Verify, executed: `docker run --rm --entrypoint sh linkboard-prod -c 'ls public/assets | head'` lists compiled assets, and running the image with **no** bind mount answers a request — recorded as the exact commands and their output.
- [ ] 1.3 Development dependencies and settings are absent. Verify, executed: `docker run --rm --entrypoint sh linkboard-prod -c 'ls vendor/bin'` shows no `phpunit`/`phpstan`/`php-cs-fixer`, and `php -i | grep opcache.validate_timestamps` reports `0`.
- [ ] 1.4 The build needs no secret. Verify, executed: the build above is run in a shell with none of `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT` set, and succeeds.

## 2. The production stack

- [ ] 2.1 Write `docker-compose.prod.yml` — caddy, php, worker, scheduler, postgres, redis — with the worker and the scheduler under `restart: unless-stopped`, reading their configuration from `.env.local` rather than from the committed `.env`. Verify: the file is re-read whole after the last edit and `docker compose -f docker-compose.yml -f docker-compose.prod.yml config` renders without error.
- [ ] 2.2 Write the Caddy configuration: HTTPS with automatic certificates, HTTP permanently redirected, HSTS, `/health` as the upstream check. Verify, executed against a locally started prod-like stack with an internal host name: `curl -sI http://<host>/x` is a 301 to `https://`, and the response over HTTPS carries `Strict-Transport-Security`.
- [ ] 2.3 The development stack is untouched. Verify, executed: `git diff main -- docker-compose.yml` is empty, and `make up && make check` is green with no edit.
- [ ] 2.4 The worker is a service, not a profile, in the production stack, and a killed worker returns. Verify, executed: kill the worker container, then show it running again and the messages queued meanwhile consumed — the counts before and after recorded.

## 3. Secrets

- [ ] 3.1 Implement the startup check (design decision 4) as a service tagged `app.startup_check`: required settings unset, empty, or equal to the value committed in `.env` fail the boot with a message naming the setting and never the value.
- [ ] 3.2 Verify each required setting individually: a test boots the kernel with that setting unset and asserts the failure names it, for `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT` and the store credentials.
- [ ] 3.3 **The committed-default case, which is the one that actually happens.** Verify: a test boots with `VISITOR_HASH_SALT` set to the exact value committed in `.env` and asserts the boot fails naming it.
- [ ] 3.4 The failure discloses nothing. Verify: a test asserts the message contains the setting's name and not the value that was found.
- [ ] 3.5 Demonstrated failing input: remove the committed-default clause and show test 3.3 failing; remove the check's registration and show 3.2 failing.
- [ ] 3.6 Every process refuses, not only the web one. Verify, executed against the prod-like stack with one secret unset: the web container, the worker and the scheduler each exit with the named failure — the three outputs recorded.
- [ ] 3.7 `.env` gains the new settings with development defaults and `.env.local` stays gitignored. Verify, executed: `git check-ignore -v .env.local` names the rule, and `rg -n '<each new setting>' .env` shows a development value and no secret.

## 4. Registration closed

- [ ] 4.1 Implement the switch as one `kernel.request` listener (design decision 3) over both registration paths, taking its paths from a parameter in `config/services.yaml`; default on.
- [ ] 4.2 Both entry points refuse. Verify: a test asserts `GET /register`, the `/register` form POST with a valid CSRF token, and `POST /api/v1/auth/register` each answer 404 with the switch off — the API one as problem details — and that no account was created by any of them.
- [ ] 4.3 The sign-in page stops advertising it. Verify: a test asserts the login page carries no link to `/register` with the switch off, and does carry one with it on.
- [ ] 4.4 Existing accounts are untouched. Verify: a test signs in through the web and obtains a token through the API with the switch off.
- [ ] 4.5 Default-on means nothing changed. Verify, executed: the whole existing suite passes with no edit to any existing test.
- [ ] 4.6 Demonstrated failing input: restrict the listener to the web path alone and show the API half of test 4.2 failing; restrict it to the API path and show the web half failing. This is the two-surface bug the decision exists to prevent, so it is shown in both directions.

## 5. Seeding a declared demo instance

- [ ] 5.1 Change the `prod` guard of `app:demo:seed` to an instance setting (design decision 5), off by default; no option of the command lifts it.
- [ ] 5.2 Verify the refusal is unchanged where it must be: a test runs the command in `prod` with the setting unset and asserts exit code 1 and nothing written.
- [ ] 5.3 Verify no flag lifts it: a test runs it in `prod`, setting unset, with `--reset` and the other options, asserting exit 1 and nothing written.
- [ ] 5.4 Verify the opt-in works: a test runs it in `prod` with the setting on and asserts the dataset is created and the output names the setting that allowed it.
- [ ] 5.5 Demonstrated failing input: make the guard readable from an option and show test 5.3 failing.
- [ ] 5.6 The existing all-or-nothing guarantees still hold. Verify, executed: the existing `demo-data` suite passes with no edit.

## 6. The schedule

- [ ] 6.1 Add the scheduler service running the demo reload (`app:demo:seed --reset`) and `app:click:partitions` on their cadences, with the cadences named in one place.
- [ ] 6.2 Verify the reload converges rather than accumulates: run it twice against the prod-like stack and assert the dataset counts are identical after each — the two outputs recorded.
- [ ] 6.3 Verify the partition run keeps the horizon: run it and show the partitions ahead of the current month, the `psql` output recorded.

## 7. The client's address

- [ ] 7.1 Set the trusted proxy for the production stack (design decision 6) so the forwarded address is the one counted.
- [ ] 7.2 Verify two clients are two buckets: through the prod-like stack, two forwarded addresses each send up to the per-IP redirect limit and neither is refused; recorded as the exact commands and statuses.
- [ ] 7.3 Verify the shipped default would have failed: with `TRUSTED_PROXIES` left at the development value, the same two clients share one bucket and the second is refused at the limit — measured and recorded, because this is the misconfiguration the requirement exists for.
- [ ] 7.4 A forwarded header the client sets itself is still not believed. Verify, executed through the stack.

## 8. Continuous integration

- [ ] 8.1 Add a CI job that builds the production image and does not push it. Verify: the job appears in the run and its log shows the build.
- [ ] 8.2 Verify it fails when the image is broken: break the production stage on a scratch commit, show the job red, restore it — the run ids recorded.
- [ ] 8.3 Verify it reaches no host: the job has no registry login, no deployment credential and no network step beyond the build.

## 9. Documents

- [ ] 9.1 Write `docs/how-to/deploy.md`: provisioning the host, generating the secrets (commands that generate, never values), starting the stack, the first seed, the schedule, what a real operator would add that is not here (backups, monitoring) and why. Verify: every command in it was run in its exact printed form, or is marked as the user's manual step where it needs a public host.
- [ ] 9.2 Write the ADR for the hosting decision: a VPS with compose and Caddy, with the PaaS and Kubernetes alternatives and why they were refused. Verify: the file is `docs/adr/ADR-0NN-*.md` with the next free number, and it is referenced from the deploy document.
- [ ] 9.3 README gains the demo section: the link, how to sign in, and that registration is closed there. The credentials are not in the repository — the section names the seed command that prints them. Verify: the README is re-read whole after the edit and `rg` finds no credential in it.
- [ ] 9.4 `docs/explanation/requirements.md` §7 row 16 and §9 record the row as delivered; `docs/README.md` indexes the new how-to. Verify, executed: `scripts/pregate-verify.sh gate2 stretch-public-hosting` reports every markdown link resolving.

## 10. Wrap-up

- [ ] 10.1 `make check` green in the container with no environment override; `openspec validate stretch-public-hosting --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated with the measured numbers and the recorded failing inputs.
- [ ] 10.2 The roadmap and the change lifecycle: row 16 stays until archive, and the handoff names the exact gate commands for this tier (Gate 1 before implementation, Gate 2 after).
