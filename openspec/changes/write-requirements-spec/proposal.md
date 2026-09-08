# Proposal — write-requirements-spec

**Risk-Tier:** low

## Why

`docs/explanation/requirements.md` is a ten-line-per-section brief extracted from the portfolio plan: it names features but fixes no behavior (status codes, validation rules, rule schema, report set, limits), so every future change would have to re-interview the user before it could write a spec. A full technical specification settles those decisions once and becomes the single source the per-capability specs in `openspec/specs/` are derived from.

## What Changes

- Rewrite `docs/explanation/requirements.md` from a brief into a full technical specification in English: goal and positioning, roles and permission matrix, functional requirements per capability, data model, API, technology stack with justification, non-functional requirements, stages with exit criteria, learning map, stretch goals and non-goals.
- Record every decision taken in the requirements interview of 2026-09-08 (20 questions, all answered by the user) as normative statements in the specification. The complete, authoritative record is the "Decision record" section below; the specification must not contradict it, and any later change of a decision goes through a new change that edits both the specification and the roadmap.
- Reconcile `openspec/ROADMAP.md` against the specification: add the web UI/dashboard changes, adjust rows whose scope the decisions changed (security, routing rules, click logging, analytics), keep change ids stable where scope did not move.

## Decision record (interview of 2026-09-08)

Authoritative list of the interview outcomes. "Default" means the user accepted the executor's proposed default verbatim. Each row becomes at least one normative statement in `docs/explanation/requirements.md`; task 1.1 verifies row-by-row traceability.

| # | Question | Decision |
|---|---|---|
| D1 | Language of the specification | English, like every other repository artifact (code, comments, docs, commits). Russian is used only in conversation with the user. The current Russian brief is superseded. |
| D2 | Web interface | Yes: a server-rendered web UI (Twig + Symfony UX: Turbo, Stimulus, Chart.js) with login, link management and an analytics dashboard, plus Swagger UI for the API. Screenshots for the README come from both. |
| D3 | Authentication to the management API | Default: email/password login issuing a JWT (`lexik/jwt-authentication-bundle`) for interactive API clients, plus hashed API keys as machine credentials for integrations. The web UI uses a session form login on a separate firewall. |
| D4 | Link ownership | Default: links belong to individual users only. Teams and organizations are a non-goal. |
| D5 | Admin role | Granted by a console command. Admin functions are exposed through the API and the web UI: deactivate any link, block/unblock users, view global statistics. |
| D6 | Slug rules | Generated slugs: base62, 7 characters. Custom aliases: 3–32 characters from `[A-Za-z0-9_-]`. Case-sensitive uniqueness. A reserved-word list (`api`, `admin`, `login`, `health`, `qr`, `docs`, …) is rejected. The slug is immutable after creation. |
| D7 | Redirect response codes | Unknown or inactive slug → 404. Expired (`expires_at` passed) or exhausted (`max_clicks` reached) → 410 Gone. Successful redirect → 302 only; 301 is never used because analytics needs every hit. |
| D8 | Target URL policy | Only `http` and `https` schemes. Hosts resolving to loopback, link-local or private ranges by literal IP, plus `localhost`, are rejected at validation time without DNS resolution. Custom schemes (`market://`, `itms-apps://`) are not accepted; store links use their `https` forms. |
| D9 | UTM handling | UTM parameters are stored on the link and appended to the destination URL at redirect time, preserving the destination's existing query string. |
| D10 | Device detection | `matomo/device-detector`, which also provides bot detection. Simple UA regexes were rejected. |
| D11 | Country detection | A `CountryResolver` interface with a GeoLite2 implementation (`geoip2/geoip2`, database file not committed, path from env) and a fallback resolver reading a proxy-provided country header. |
| D12 | A/B determinism | Variant chosen by hashing link id + client IP + user agent; no cookie is set. The chosen variant is stored on the click for per-variant analytics. |
| D13 | Visitor identity | A salted SHA-256 `visitor_hash` of IP + user agent is stored on the click; the raw IP is never persisted. Unique visitors are counted by this hash. |
| D14 | Bots | Clicks carry an `is_bot` flag from device detection; reports exclude bots by default with an opt-in to include them. |
| D15 | Report set | Clicks over time (hour and day granularity), top countries, top devices/OS, top referrers, breakdown by A/B variant, unique visitors. No public statistics page. |
| D16 | Click retention | Default: no retention limit in the core stages; retention and monthly partitioning of `clicks` are a stretch change with its own ADR. |
| D17 | Rate limits | Defaults: anonymous redirect 60/min per IP, API 600/min per API key, auth endpoints 10/min per IP. All values configurable via env. |
| D18 | QR codes | SVG and PNG via `endroid/qr-code`; owner-only endpoint `/api/v1/links/{id}/qr` and a download in the web UI. No public QR endpoint. |
| D19 | GraphQL | Stretch. API Platform can expose `/api/graphql` from the same resources, but it adds an authorization, rate-limiting and test surface not needed for the portfolio goal. |
| D20 | Deployment | Local Docker Compose plus GitHub Actions CI are in scope; public hosting is a stretch goal. |

## Capabilities

### New Capabilities

None — this change writes documentation. Behavioral specs are created by the implementing changes, each deriving its delta from the specification written here. `.openspec.yaml` sets `skip_specs: true`.

### Modified Capabilities

None.

## Non-goals

- No application code, configuration, dependency or migration is added; `composer.json` does not exist yet and is not created.
- No ADRs are written: the specification records requirements and the interview decisions; architectural decisions get their ADRs inside the implementing changes when the trade-off is actually faced.
- No per-capability spec in `openspec/specs/` is written here.
- The Russian portfolio source document (`Pet_Projects_Portfolio_Sopilko.md`, outside the repository) is not modified.

## Impact

- `docs/explanation/requirements.md` — replaced whole (the current brief is superseded; provenance line kept).
- `openspec/ROADMAP.md` — rows added/adjusted; `openspec/config.yaml` `context` gains one sentence pointing at the web UI decision if the stack list there turns out to be incomplete after the roadmap pass.
- New dependencies: none in this change. The specification *names* future dependencies (`lexik/jwt-authentication-bundle`, `matomo/device-detector`, `geoip2/geoip2`, `endroid/qr-code`, Symfony UX packages) with a one-line justification each against the anti-overengineering rule; the implementing change still owns the final justification when it adds the package.
