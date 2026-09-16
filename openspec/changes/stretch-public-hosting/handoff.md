# Handoff — stretch-public-hosting

**Updated:** 2026-09-16 · claude
**State:** blocked
**Branch:** change/stretch-public-hosting

## Done this session
- Branch `change/stretch-public-hosting` created from `main` (`ce281e7`, the archive of `harden-gate-floor`, which closed stage 4); change scaffolded with `openspec new change`.
- Scope, from roadmap row 16 and §7 of `docs/explanation/requirements.md`: a public demo instance with HTTPS, seeded data and a reset job, linked from the README. This is the first stretch row, and the first change in the series whose product is **outside this repository**.

## User decisions
- **2026-09-16 — deployment-ready, not deployed.** The change ships a complete, reviewed deployment configuration with its documentation and a CI path; provisioning the host, the domain and TLS stays the user's single manual step. Nothing in this change performs an externally visible action.
- **2026-09-16 — order.** The user restored the roadmap's order: this row is parked until 14 (`stretch-partition-clicks`) and 15 (`stretch-graphql`) are done. The branch and this handoff stay as they are; work resumes with `/opsx:propose stretch-public-hosting`.

## Next step
Parked. When row 15 is archived, resume with `/opsx:propose stretch-public-hosting`. Three things the proposal must still settle, because they change what is built rather than how (the fourth, deploy-or-prepare, is decided above):
1. **Where.** The target decides almost everything else: a small VPS with compose and a reverse proxy, a container platform, or a PaaS. The anti-overengineering rule applies — the reason has to be stated, not the fashion.
2. **What a public instance changes about the application's own risk.** The demo is a URL shortener open to the internet with accounts, a redirect that follows user-supplied targets, and rate limits sized for a laptop. Open-redirect and SSRF validation exist and are tested; the limits, the demo accounts, the reset cadence and what an anonymous visitor may create are decisions this change has to make deliberately, and they are what argues the tier up from the roadmap's `medium`.
3. **Secrets.** A real deployment needs `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT` and database and Redis credentials that are not the committed local defaults. Nothing of the kind enters code, config, docs, commits or chat: they are named, generated on the host or in the platform's secret store, and referenced. The proposal says how they are set without saying what they are.

## Blockers
Parked behind roadmap rows 14 and 15, by the user's decision of 2026-09-16 — not a technical blocker.
