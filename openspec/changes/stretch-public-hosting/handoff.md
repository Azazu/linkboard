# Handoff — stretch-public-hosting

**Updated:** 2026-09-17 · claude
**State:** proposing
**Branch:** change/stretch-public-hosting

## Done this session
- Branch `change/stretch-public-hosting` created from `main` (`ce281e7`, the archive of `harden-gate-floor`, which closed stage 4); change scaffolded with `openspec new change`.
- Scope, from roadmap row 16 and §7 of `docs/explanation/requirements.md`: a public demo instance with HTTPS, seeded data and a reset job, linked from the README. This is the first stretch row, and the first change in the series whose product is **outside this repository**.

## User decisions
- **2026-09-16 — deployment-ready, not deployed.** The change ships a complete, reviewed deployment configuration with its documentation and a CI path; provisioning the host, the domain and TLS stays the user's single manual step. Nothing in this change performs an externally visible action.
- **2026-09-16 — order.** The user restored the roadmap's order: this row is parked until 14 (`stretch-partition-clicks`) and 15 (`stretch-graphql`) are done. The branch and this handoff stay as they are; work resumes with `/opsx:propose stretch-public-hosting`.
- **2026-09-17 — where: a VPS, `docker compose` plus Caddy.** Offered a VPS with a production compose profile and Caddy, a PaaS manifest, or Kubernetes/Helm; the user chose the VPS. The reason to state in the proposal is not fashion: the whole contour stays readable in the repository, it mirrors the local stack rather than replacing it, TLS is Caddy's own automatic Let's Encrypt, Postgres and Redis stay the components the application already uses, and there is no vendor-specific manifest to explain. Kubernetes is refused on the anti-overengineering rule — one demo instance names no need an existing component cannot cover — and the refusal is recorded rather than left implicit.
- **2026-09-17 — exposure: one shared demo account, registration closed on the public instance.** Links can be created and every report can be read; the seed is reloaded on a schedule. Registration through the web and through the API is off on that instance, which is a real configuration switch both surfaces must honour, not a note in the README. This is the smallest public surface that still shows the product: no strangers' accounts, no mail, no accumulating spam. The demo credentials are not a secret by intent, but they still do not enter the repository — the README names the seed command that prints them.

## Next step
`/opsx:propose stretch-public-hosting`. The three questions the parked handoff left are settled above (where, exposure) or follow from them (secrets: named in `.env.local`, generated on the host, never in the repository). What the proposal has to argue rather than assume: the tier — `high`, not the roadmap's `medium`, because the change turns registration off through a switch two authenticated surfaces must respect, sets the rate limits a public instance runs at, and decides how secrets reach the host.

## Blockers
None. `main` merged into this branch on 2026-09-17 (rows 14 and 15 landed), so the branch contains current `main`.
