# Handoff — stretch-public-hosting

**Updated:** 2026-09-17 · claude
**State:** awaiting-gate-1
**Branch:** change/stretch-public-hosting

## Done this session
- Branch `change/stretch-public-hosting` created from `main` (`ce281e7`, the archive of `harden-gate-floor`, which closed stage 4); change scaffolded with `openspec new change`.
- Scope, from roadmap row 16 and §7 of `docs/explanation/requirements.md`: a public demo instance with HTTPS, seeded data and a reset job, linked from the README. This is the first stretch row, and the first change in the series whose product is **outside this repository**.

## User decisions
- **2026-09-16 — deployment-ready, not deployed.** The change ships a complete, reviewed deployment configuration with its documentation and a CI path; provisioning the host, the domain and TLS stays the user's single manual step. Nothing in this change performs an externally visible action.
- **2026-09-16 — order.** The user restored the roadmap's order: this row is parked until 14 (`stretch-partition-clicks`) and 15 (`stretch-graphql`) are done. The branch and this handoff stay as they are; work resumes with `/opsx:propose stretch-public-hosting`.
- **2026-09-17 — where: a VPS, `docker compose` plus Caddy.** Offered a VPS with a production compose profile and Caddy, a PaaS manifest, or Kubernetes/Helm; the user chose the VPS. The reason to state in the proposal is not fashion: the whole contour stays readable in the repository, it mirrors the local stack rather than replacing it, TLS is Caddy's own automatic Let's Encrypt, Postgres and Redis stay the components the application already uses, and there is no vendor-specific manifest to explain. Kubernetes is refused on the anti-overengineering rule — one demo instance names no need an existing component cannot cover — and the refusal is recorded rather than left implicit.
- **2026-09-17 — exposure: one shared demo account, registration closed on the public instance.** Links can be created and every report can be read; the seed is reloaded on a schedule. Registration through the web and through the API is off on that instance, which is a real configuration switch both surfaces must honour, not a note in the README. This is the smallest public surface that still shows the product: no strangers' accounts, no mail, no accumulating spam. The demo credentials are not a secret by intent, but they still do not enter the repository — the README names the seed command that prints them.

## Artifacts written
- `proposal.md` (tier `high`, argued against the roadmap's `medium` from three AGENTS.md triggers: a switch in front of an authorization surface with two entry points, how secrets reach a running instance, and relaxing the guard on a destructive command).
- Three capability deltas: `deployment` **new** (six requirements — the self-contained image, the three processes, HTTPS only, the client's address through the proxy, a boot that refuses on a missing or default secret, and what the public demo exposes — plus the CI build), `demo-data` **modified** (the `prod` refusal becomes conditional on an instance setting), `user-accounts` **modified** (registration governed by one switch both surfaces honour).
- `design.md` — six decisions plus the applicability table.
- `tasks.md` — 43 tasks in ten sections, each with its verification, and a demonstrated failing input for every new guard.

## Measured before proposing, not assumed
- **`app:demo:seed` refuses `prod` by a tested requirement**, and the public instance is `prod`. The contradiction is resolved deliberately rather than by a flag: the opt-in is a property of the instance, because a `--force` flag would travel in a shell history onto a real host.
- **`StartupChecks` already exists** (`app.startup_check`, run from `Kernel::boot()`, two existing users), so "a missing secret fails the boot in every process" reuses a mechanism rather than adding one — the anti-overengineering rule is satisfied by reference, not by argument.
- **The rate limits are already specified as configurable with defaults**, so the public instance's values are configuration and need no delta. What does need one is the **trusted proxy**: two capabilities require the per-IP limits to count the client, and the shipped `TRUSTED_PROXIES=127.0.0.1` behind Caddy would collapse every visitor into one bucket. Task 7.3 measures that failure deliberately.
- **Registration has two entry points** — a Twig controller and an API Platform `Post`. Both a routing `condition` (Symfony resolves `routing.condition_service` functions; API Platform's `HttpOperation` carries `condition`) and a per-surface check were considered and rejected for one listener, because "one rule, two implementations" is the defect class this repository has already paid for twice.

## Next step
Gate 1: `scripts/gate-run.sh stretch-public-hosting 1 full` — tier `high`, so the artifacts are reviewed before any implementation.

## Blockers
None. `main` merged into this branch on 2026-09-17 (rows 14 and 15 landed), so the branch contains current `main`.
