# Handoff — add-web-admin-and-stats

**Updated:** 2026-09-14 · claude
**State:** proposing
**Branch:** change/add-web-admin-and-stats

## Done this session
- Branch `change/add-web-admin-and-stats` created from `main` (`2d3fdeb`, after the archive of `add-web-ui`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 11a and §7 of `docs/explanation/requirements.md`: the rest of FR-WEB-1 — `/links/{id}/stats` with every report of section 2.6 as charts and tables (period, granularity, bots toggle) and the admin pages `/admin/users`, `/admin/links`, `/admin/stats`. Tier `high`, set by the user when row 11 was split (2026-09-13): the admin pages are an authorization boundary, and the stats page reads another capability's reports on behalf of an owner.
- What it builds on, already merged: the `web-ui` capability (shell, hardening headers and the per-request CSP nonce, forms, `LinkPages::findGranted()` answering 404 on a voter denial), the `analytics` read model with its cached reports and `generatedAt`, and `user-administration` for the admin API.

- Artifacts written and `openspec validate --strict` passes: proposal (tier `high` with the three triggers that apply, non-goals), the `web-ui` delta (two MODIFIED requirements — the page enumeration gains `/links/{id}/stats`, and the no-JavaScript rule now says every charted series is also a table — and six ADDED ones: the statistics page's content, its ownership boundary, its refusal of a parameter on the control that carried it, the administrator-only boundary of `/admin/*`, the accounts page with block and unblock, the administrative links page and the global statistics page), design (one authority per report with the API providers as adapters, parsing split from API-shaped wrapping, the two authorization boundaries asked of the mechanisms that own them, block/unblock as a use case, shared filter parsing and a batch owner lookup, charts as an enhancement over tables, plus the applicability table), tasks (Gate 1, the two refactors, the statistics page, the four administrative tasks, docs, wrap-up).

**Design choices worth the reviewer's attention:** (1) the report services return the very objects the API answers, through the same cache key and tag, so one entry serves both presenters — the alternatives (a second code path, a reshaped cache payload, or moving nine classes) are recorded in decision 1 with why each was rejected, including the honest cost that the web layer names classes in the `Analytics\Api` namespace. (2) A refused report parameter re-renders the page with 422 and no figures rather than redirecting to the defaults, so the address keeps describing what is shown. (3) `^/admin(/|$)` carries the segment boundary row 11's Gate 1 found to be load-bearing — `admin` is a reserved slug but `admin-sale` is a valid one.

**Security-relevant parts:** the two authorization boundaries (the link voter's `LINK_VIEW` for a link's statistics with 404 on denial; `ROLE_ADMIN` for `/admin/*`, guarded both in `access_control` and on each controller), the move of the self-block guard and the audit line out of an API Platform processor into a use case, and the CSRF-protected confirmation flow for blocking an account.

## Next step
Gate 1: `scripts/gate-run.sh add-web-admin-and-stats 1 full` (task 0.1). Implementation starts only after it reads `confirmed`/`approved` with no finding left `open`.

## Blockers
None.
