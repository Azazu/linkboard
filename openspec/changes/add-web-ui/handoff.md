# Handoff — add-web-ui

**Updated:** 2026-09-13 · claude
**State:** awaiting-gate-1
**Branch:** change/add-web-ui

## Done this session
- Branch `change/add-web-ui` created from `main` (`62abc49`, after the archive of `authorize-deep-probe-by-api-key`); change scaffolded with `openspec new change`.
- **Scope split and tier raised by the user** (2026-09-13): twelve pages, the rules editor, charts and CSP in one high-tier change would make a Gate 2 diff nobody can review well. Row 11 keeps the shell and the owner-facing pages; the new row 11a `add-web-admin-and-stats` takes `/links/{id}/stats` and the three admin pages. Both are `high`. `openspec/ROADMAP.md` and the brief's §7 table record it (`1bfa959`).
- Artifacts written: proposal (tier `high` with its rationale, four justified dependencies, non-goals), the new `web-ui` capability delta (pages and who may see them, the 404 for another owner's link, no-JavaScript operation, CSRF and the API's violations, link writes under the API's rules, the rules editor, the once-shown key plaintext, the hardening headers and cookie flags, the docs link), design (controllers per page; one authority for link writes in `src/Link/UseCase/` with the API processors as adapters; authorization asked on the voter's own subject with 404 on denial and SQL scoped by owner; an uncached owner-scoped dashboard query with the trade-off stated; AssetMapper with a vendored import map and no polyfill; a per-request CSP nonce with the `/api/docs` exclusion stated; hardened session cookies; form DTOs instead of the API's rules-reading input DTOs; Turbo's 303/422 contract; the applicability table), tasks (Gate 1, the pipeline, the shell and hardening, the write-path refactor, the pages, docs, wrap-up). `openspec validate --strict` and `scripts/pregate-verify.sh gate1` pass.

**Design choices worth the reviewer's attention:** (1) the link write path moves out of the API Platform processors into `src/Link/UseCase/` because the processors read the raw JSON body and a form post has none — the alternative was a second copy of ownership, slug and cache logic; the existing `tests/Api/Link` suite is the net, and task 3.2 forbids editing it. (2) The dashboard query is deliberately uncached: a report cache keyed by owner would go stale for five minutes after any API write, and the invalidation would have to live in two layers. (3) The content security policy is not sent on `^/api` because API Platform's Swagger UI bootstraps inline; the gap is stated rather than papered over with `unsafe-inline`.

**Security-relevant parts for review:** the ownership boundary repeated on every page (voter on the converted `LinkResource`, 404 on the pages while the API keeps its 403, SQL scoped by `owner_id`), CSRF on every state-changing form, the policy and its per-request nonce, and the session cookie flags.

- Gate 1 round 1 (`807c1fc`, Reviewed-Commit `600f852`): changes-requested — three majors and one minor, all real. (1) The access-control pattern `^/(dashboard|links|api-keys)` is an unanchored regular expression, so it also captured the valid short links `/dashboard-sale`, `/links-promo` and `/api-keys-promo` and would have sent public redirects to the login page. (2) Excluding `^/api` from the policy also excluded `/api-keys` — the one page that renders a secret. (3) Turbo Drive snapshots the DOM before navigating and restores it from cache without a server request, so a consumed server-side flash does not keep a new key's plaintext off the screen after a back navigation. (4) The artifacts claimed the API answers a stranger with 404; the `links` and `qr-codes` capabilities require 403. Fixed in the following commit: the segment boundary `(/|$)` on both patterns with a prefix-slug regression test; positive header assertions on `/api-keys` and on the page that shows a new key; `data-turbo-temporary` plus `<meta name="turbo-cache-control" content="no-cache">` plus `Cache-Control: no-store`, each asserted in markup, with the manual back-navigation check recorded in the how-to instead of claimed as a test; and the 404-versus-403 asymmetry stated as a rendering decision that leaves the API contract alone. Statuses → fixed.

## Next step
`scripts/gate-run.sh add-web-ui 1 confirm 1` — the confirmation on findings 1–4. Then `/opsx:apply add-web-ui`.

## Blockers
None.
