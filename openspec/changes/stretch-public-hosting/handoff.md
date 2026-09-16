# Handoff — stretch-public-hosting

**Updated:** 2026-09-16 · claude
**State:** proposing
**Branch:** change/stretch-public-hosting

## Done this session
- Branch `change/stretch-public-hosting` created from `main` (`ce281e7`, the archive of `harden-gate-floor`, which closed stage 4); change scaffolded with `openspec new change`.
- Scope, from roadmap row 16 and §7 of `docs/explanation/requirements.md`: a public demo instance with HTTPS, seeded data and a reset job, linked from the README. This is the first stretch row, and the first change in the series whose product is **outside this repository**.

## Next step
`/opsx:propose stretch-public-hosting`. Four things the proposal must settle before anything is written, because they change what is built rather than how:

1. **Deploy, or ship deployment-ready?** A change that provisions a host, a domain and TLS needs an account, DNS and money, and none of that is mine to create. The alternative — a complete, reviewed deployment configuration with its documentation and a CI path, which the user runs once — is a different change with a different definition of done. This is the user's call and the proposal cannot assume it.
2. **Where.** The target decides almost everything else: a small VPS with compose and a reverse proxy, a container platform, or a PaaS. The anti-overengineering rule applies — the reason has to be stated, not the fashion.
3. **What a public instance changes about the application's own risk.** The demo is a URL shortener open to the internet with accounts, a redirect that follows user-supplied targets, and rate limits sized for a laptop. Open-redirect and SSRF validation exist and are tested; the limits, the demo accounts, the reset cadence and what an anonymous visitor may create are decisions this change has to make deliberately, and they are what argues the tier up from the roadmap's `medium`.
4. **Secrets.** A real deployment needs `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT` and database and Redis credentials that are not the committed local defaults. Nothing of the kind enters code, config, docs, commits or chat: they are named, generated on the host or in the platform's secret store, and referenced. The proposal says how they are set without saying what they are.

## Blockers
None yet — but item 1 is a decision, not a task, and the proposal stops on it.
