# Proposal — add-web-ui

**Risk-Tier:** high

Tier rationale: the change opens a second authenticated surface — session cookies, CSRF-protected forms and the ownership boundary re-exercised on every page — and it is where the unimplemented NFR-SEC-5 lands: security headers and a content security policy that other people's browsers enforce. It also moves the link write path into services two callers share, so a mistake there reaches the API as well. AGENTS.md puts authentication, authorization and security-sensitive input handling at `high`: Gate 1 on the artifacts, Gate 2 on the code, and a demonstrated failing input for every new guard. The minimum in the roadmap was `medium`; the user raised it on 2026-09-13 together with the split of row 11.

## Why

Everything the service does is reachable only through `/api/v1` and the bare redirect: there is no page a person can use, and the project is a portfolio piece whose first impression is a screenshot. FR-WEB-1 to FR-WEB-4 describe that UI, and NFR-SEC-5 (security headers, CSP, hardened session cookies) has no implementation at all — today not one response carries `X-Frame-Options`, `X-Content-Type-Options` or a CSP. This change delivers the owner-facing half of the UI and the response hardening that every page depends on; the stats page and the admin pages follow in `add-web-admin-and-stats` (row 11a), split out by user arbitration on 2026-09-13 so that a high-tier review reads a diff it can actually hold.

## What Changes

1. **An application shell** (`templates/base.html.twig`, rebuilt): a responsive layout with a navigation bar, a flash region, and a footer linking the Swagger UI at `/api/docs` (FR-WEB-4). Styling is Pico.css served from the application, behaviour is Turbo plus a few Stimulus controllers, all delivered by AssetMapper with no Node build step and **no CDN at runtime** (FR-WEB-3). The skeleton's two `cdn.jsdelivr.net` script tags go: they are FrankenPHP hot-reload helpers this deployment never uses, and they would be the only thing the policy has to allow.
2. **The owner-facing pages** (FR-WEB-1): `/dashboard` (own totals, a clicks-per-day chart, the last ten links), `/links` (paginated, filtered by state and slug, ordered), `/links/new`, `/links/{id}` (details, the QR code, a copy button), `/links/{id}/edit` (target, expiry, click limit, UTM, and the routing rules as a structured editor with a raw-JSON fallback that shows the document's own violations), `/api-keys` (create — the plaintext sent in exactly one response, with the page marked so the navigation layer keeps no copy and a restored document is cleared — list, revoke). `/login` and `/register` keep their behaviour and move into the shell.
3. **One authority for link writes**: the create, update and delete orchestration — slug generation with collision recovery, the rules document, report-cache invalidation, the admin audit line — moves out of the API Platform processors into application services in `src/Link/UseCase/`. The processors become thin adapters that translate the HTTP body; the web controllers call the same services. Without this the UI would have to re-implement logic that is security-relevant (ownership, the target-URL policy, the rules limits) — the defect class AGENTS.md calls out as the most expensive in this workflow.
4. **Dashboard figures the analytics layer cannot answer yet**: every existing query is per link or instance-wide, so the owner's totals and the clicks-per-day series for *their* links get one new owner-scoped query, with the index it needs verified by `EXPLAIN`.
5. **Response hardening (NFR-SEC-5)**: one `kernel.response` subscriber adds `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, and a content security policy that admits only the application's own origin, with a per-request nonce for the import map that AssetMapper needs; session cookies become `HttpOnly`, `SameSite=Lax` and `Secure` outside development. The only HTML response without the policy is the API documentation, whose third-party Swagger UI bootstraps inline — it keeps the other three headers. The redirect's existing `Referrer-Policy` and the API's problem details are left as they are.
6. **A `WebTestCase` suite** that is the exit criterion: every page for its owner, for a stranger (a 404 that tells them nothing), for an administrator and for a guest (a redirect to the login page); a short link whose slug begins with a page's name still redirecting for a guest; the forms' violations; the rules editor's error paths; the once-sent key plaintext, the markings that keep a copy of it out of the navigation layer, and a headless-browser acceptance run that observes a real back navigation with and without those markings; the headers and the policy on a rendered page, `/api-keys` included; and a demonstrated failing input for every guard.
7. **Docs**: a how-to section on running and using the UI, and the brief's FR-WEB rows brought in line with what shipped.

## Capabilities

### New Capabilities

- `web-ui`: the server-rendered pages a signed-in owner uses — what each page shows, who may see it, how its forms behave, and the headers, policy and cookie flags every web response carries.

### Modified Capabilities

_None._ The link, API-key and analytics rules are unchanged: the pages state that they enforce the same ones and the specs of those capabilities stay the single authority for what the rules are.

## Non-goals

- `/links/{id}/stats` and the admin pages `/admin/users`, `/admin/links`, `/admin/stats` — they are change `add-web-admin-and-stats` (row 11a), together with the period, granularity and bots selectors of the full report pages.
- Any change to the API's observable behaviour. Extracting the write path is a refactor the existing `tests/Api/Link` suite holds in place; a behaviour change there would be a defect of this change, not its scope.
- A Node build step, a JavaScript framework, a component library, dark-mode theming beyond what Pico.css ships, or translations — the UI is English, like the rest of the repository.
- Password reset, e-mail of any kind, remember-me cookies, account deletion, or a profile page.
- A rate limiter for the UI's authenticated POSTs. The login and registration pages keep the existing per-IP limiter; an authenticated page is bounded by the session, and inventing a second limiter here would be a component without a role (the anti-overengineering rule).
- Server-side pagination beyond what the links list needs, search over click rows, or export.

## Impact

- **New dependencies** (each named by the brief §2.9 and justified against the anti-overengineering rule): `symfony/asset-mapper` — serves, versions and import-maps the assets with no Node toolchain, which is the requirement FR-WEB-3 states; nothing installed does this. `symfony/stimulus-bundle` — the behaviour attributes Turbo and the chart bundle both build on, and the copy button and rules editor need. `symfony/ux-turbo` — form submission and navigation without full reloads and without hand-written fetch code. `symfony/ux-chartjs` — a Twig builder for the dashboard chart over a self-hosted Chart.js; the alternative is hand-rolling the same wiring. A dry run of the resolution on 2026-09-13 fixes the versions: asset-mapper 8.1.5, stimulus-bundle 3.4.0, ux-turbo 3.4.0, ux-chartjs 3.4.0, plus `symfony/http-client` 8.1.6, which asset-mapper requires and which the import-map commands use to fetch a vendored file once. Pico.css arrives as a vendored asset through the import map, not as a package. All four are production dependencies: `tests/Unit/ProductionDependenciesTest.php` fails if one lands in `require-dev`.
- **New**: `src/Web/{Dashboard,Link,ApiKey}/` controllers and form types, `src/Link/UseCase/` (create, update, delete and their value objects), `src/Analytics/Query/OwnerDashboardQuery.php`, `src/Web/Http/SecurityHeadersSubscriber.php` and the nonce service, `templates/{layout,dashboard,link,api_key}/`, `assets/` (`app.js`, `styles/app.css`, Stimulus controllers), `importmap.php`, `config/packages/asset_mapper.yaml`, tests under `tests/Web/`.
- **Modified**: `templates/base.html.twig` (the shell), `src/Link/Api/{Create,Update,Delete}LinkProcessor.php` (thin adapters), `src/Auth/Api/ApiKeys/RevokeApiKeyProcessor.php` (an owner-explicit method beside the HTTP one, as the create processor already has), `config/packages/security.yaml` (access control for the new paths), `config/packages/framework.yaml` (session cookie flags), `config/packages/twig.yaml` (the form theme), `config/bundles.php`, `composer.json`/`composer.lock`, `docs/how-to/local-development.md`, `docs/explanation/requirements.md`, `openspec/ROADMAP.md` (row 11 removed at archive time).
- **Unchanged**: the redirect, the click write path, the analytics read model's existing queries and cache, the API's routes, payloads and status codes, the firewalls' authenticators, and `src/Link/ReservedSlugs.php` — every new page sits under a segment it already reserves (`dashboard`, `links`, `api-keys`, `assets`).

## User decisions

- **2026-09-13 — third confirmation authorised after two failed ones** (Gate 1 round 1, finding 3): the user chose to define the two negative browser cases separately — both Turbo markings removed for a Turbo restoration, only the `pageshow` clearing removed for a persisted full-document restoration, each on a fresh key, with the run recording whether a persisted restoration actually occurred — and to run a third confirmation, rather than moving the browser acceptance to a change of its own or waiving the finding on a page that renders a secret. Recorded per AGENTS.md ("after two failed confirmations … ask the user to arbitrate").
