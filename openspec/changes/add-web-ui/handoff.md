# Handoff — add-web-ui

**Updated:** 2026-09-13 · claude
**State:** proposing
**Branch:** change/add-web-ui

## Done this session
- Branch `change/add-web-ui` created from `main` (`62abc49`, after the archive of `authorize-deep-probe-by-api-key`); change scaffolded with `openspec new change`.
- Roadmap row 11 (stage 4): the server-rendered UI — Twig + Symfony UX + AssetMapper, login/register, dashboard, links list/create/edit/details/stats with charts, API keys, admin pages, security headers and CSP. Minimum tier `medium`; the proposal declares the tier and says why (the pages reuse the API's services and voters, so the authorization boundary is the existing one, but forms, CSP and session handling are new attack surface).

## Next step
`/opsx:propose add-web-ui` — proposal, the spec deltas, design and tasks; then Gate 1 if the declared tier is `high`, otherwise implementation and Gate 2.

## Blockers
None.
