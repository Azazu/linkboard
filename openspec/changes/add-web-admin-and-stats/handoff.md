# Handoff — add-web-admin-and-stats

**Updated:** 2026-09-14 · claude
**State:** proposing
**Branch:** change/add-web-admin-and-stats

## Done this session
- Branch `change/add-web-admin-and-stats` created from `main` (`2d3fdeb`, after the archive of `add-web-ui`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 11a and §7 of `docs/explanation/requirements.md`: the rest of FR-WEB-1 — `/links/{id}/stats` with every report of section 2.6 as charts and tables (period, granularity, bots toggle) and the admin pages `/admin/users`, `/admin/links`, `/admin/stats`. Tier `high`, set by the user when row 11 was split (2026-09-13): the admin pages are an authorization boundary, and the stats page reads another capability's reports on behalf of an owner.
- What it builds on, already merged: the `web-ui` capability (shell, hardening headers and the per-request CSP nonce, forms, `LinkPages::findGranted()` answering 404 on a voter denial), the `analytics` read model with its cached reports and `generatedAt`, and `user-administration` for the admin API.

## Next step
`/opsx:propose add-web-admin-and-stats` — proposal, the `web-ui` spec delta, design (the `high`-tier applicability table) and tasks; then Gate 1.

## Blockers
None.
