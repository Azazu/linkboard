## Why

The web UI that shipped with row 11 gave an owner their own pages. Two parts of FR-WEB-1 are still missing, and both hide work that is already built: the six per-link reports of §2.6 exist only as JSON, so the analytics read model — the most interesting SQL in the project — is invisible to anyone who opens the site; and `user-administration` and the global statistics of FR-ANL-4 have no pages at all, so an administrator can block an account or read the service's totals only with a JSON client. This is row 11a of the roadmap, split out of row 11 by user arbitration on 2026-09-13 so that the admin boundary and the stats page get a review of their own.

**Risk-Tier:** high

Three of the four `high` triggers in AGENTS.md apply. `/admin/*` is an authorization boundary that does not exist yet in the web firewall — a new path prefix, a new access-control pattern, and pages that must be unreachable for a signed-in non-admin. Blocking an account is an irreversible-feeling, security-sensitive write whose one implementation currently lives inside an API Platform processor and has to become reachable from a form without being duplicated. And the stats page reads another capability's cached reports on behalf of a user, so its ownership check is the same boundary the API defends, expressed again in a different layer. The user set this tier when the row was split.

## What Changes

- **`/links/{id}/stats`** — every report of §2.6 for one link: summary figures, the timeseries as a chart *and* a table, countries, device types, operating systems, referrers and A/B variants as tables with shares. Selectors for the period (`from`/`to`), the granularity (`hour`/`day`) and the bots toggle, submitted as a plain GET form; a parameter the report layer refuses is shown on the field that carries it instead of replacing the page with an error document. The page states its staleness from the report's own `generatedAt` ("updated up to 5 minutes ago"), and a link with no clicks yet reads as empty, not as broken. Reached from the link's own page.
- **`/admin/users`** — every account, newest first, paginated: email, roles, blocked state, created. Block and unblock go through a confirmation page that names the consequence, then a CSRF-protected POST; an admin blocking their own account is refused with the same message the API gives.
- **`/admin/links`** — every user's links with the filters and ordering the owner's list already uses, each row leading to the link's own page (the voter already grants an admin the view).
- **`/admin/stats`** — the global figures of FR-ANL-4: the totals, the clicks-per-bucket timeseries as a chart and a table, and the top links by clicks in the period with their owner.
- **One authority for a report, reached from two places.** The computation of the six link reports and the three global ones is extracted from the two API Platform providers into services under `src/Analytics/Report/`, keyed and tagged exactly as today; the providers become adapters that translate an `Operation` into a report request, and the pages call the same services. Nothing about the API's behaviour changes and its `tests/Api/Analytics` suite is the net: it is not edited.
- **One authority for an account action.** The self-block guard, the state change and the audit line move from `BlockUserProcessor`/`UnblockUserProcessor` into `src/Auth/UseCase/`, with the processors as adapters — the same shape row 11 used for link writes, for the same reason: a form has no JSON body to hand a processor.
- **A refused report parameter becomes a value the caller can render.** `ReportRequestFactory` keeps producing API Platform's 422 for the API; the parsing itself is separated from that wrapping so the web layer can put the violation on the form field it belongs to.
- **Access control and navigation.** `^/admin(/|$)` requires `ROLE_ADMIN` — with the segment boundary that row 11's Gate 1 found to be load-bearing, since `admin` is a reserved slug but `admin-sale` is a valid one. The header shows an Admin entry only to an administrator.
- No new dependencies, no migration, no change to any API route, payload or status code.

## Capabilities

### New Capabilities

None. The pages belong to the `web-ui` capability that row 11 introduced.

### Modified Capabilities

- `web-ui`: the enumeration of pages gains `/links/{id}/stats` and the three admin pages; new requirements state the statistics page's content and its ownership boundary, the administrator-only boundary of `/admin/*` for a signed-in non-admin, what each admin page shows, and that blocking an account from a page obeys the same rules as the API.

## Impact

- **New code**: `src/Web/Stats/` (the per-link statistics page), `src/Web/Admin/` (users, links, stats, and the block/unblock confirmations), `src/Analytics/Report/` gains the report services, `src/Auth/UseCase/` gains the account actions, `templates/stats/` and `templates/admin/`.
- **Changed code**: `src/Analytics/Api/LinkReportProvider.php` and `AdminStatsProvider.php` reduced to adapters; `src/Auth/Api/Admin/{Block,Unblock}UserProcessor.php` reduced to adapters; `src/Analytics/Api/ReportRequestFactory.php` split into parsing and API-shaped wrapping; `config/packages/security.yaml` (one access-control line); `templates/base.html.twig` (the Admin entry); `templates/link/show.html.twig` (a link to the statistics page).
- **Untouched on purpose**: every analytics query and its SQL, the report cache and its keys, tags and time-to-live, the click write path, the API's routes and payloads, and the existing `tests/Api/**` suites — they are the regression net for both refactors.
- **Dependencies**: none added. Charts use `symfony/ux-chartjs`, already vendored in row 11; pages, forms and CSRF use what the framework provides.

## Non-goals

- Editing or deleting another user's link from `/admin/links`: the page lists and links onward. An admin who opens a link's own page already has the voter's permissions there — that is row 11's behaviour and is not extended here.
- Deleting an account, changing roles, or any account operation the `user-administration` capability does not already define.
- New reports, new report parameters, export to CSV, or any change to how a report is computed or cached.
- Live-updating figures: a page shows the numbers as of its request, with the staleness the cache's time-to-live implies, stated on the page.
- README screenshots and the architecture diagram (row 13, `harden-quality-and-docs`).
- `/admin/stats` is not a second dashboard: it shows the three global reports the API defines and nothing invented beyond them.
