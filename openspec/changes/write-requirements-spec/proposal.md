# Proposal — write-requirements-spec

**Risk-Tier:** low

## Why

`docs/explanation/requirements.md` is a ten-line-per-section brief extracted from the portfolio plan: it names features but fixes no behavior (status codes, validation rules, rule schema, report set, limits), so every future change would have to re-interview the user before it could write a spec. A full technical specification settles those decisions once and becomes the single source the per-capability specs in `openspec/specs/` are derived from.

## What Changes

- Rewrite `docs/explanation/requirements.md` from a brief into a full technical specification in English: goal and positioning, roles and permission matrix, functional requirements per capability, data model, API, technology stack with justification, non-functional requirements, stages with exit criteria, learning map, stretch goals and non-goals.
- Record the decisions taken in the requirements interview of 2026-09-08 (20 questions answered by the user). The material ones:
  - a server-rendered web UI (Twig + Symfony UX: Turbo, Stimulus, Chart.js) with a dashboard, alongside Swagger UI; session form login for the web, JWT (`lexik/jwt-authentication-bundle`) plus hashed API keys for `/api/v1`;
  - `matomo/device-detector` for device/OS/bot detection; a `CountryResolver` interface with a GeoLite2 (`geoip2/geoip2`) implementation and a proxy-header fallback;
  - deterministic A/B assignment by hashing link id + IP + user agent, variant stored on the click;
  - salted `visitor_hash` instead of raw IP; `is_bot` flag, bots excluded from reports by default;
  - unknown/inactive slug → 404, expired/exhausted → 410, redirect 302 only; `http`/`https` targets only, private and loopback hosts rejected;
  - default rate limits 60/min per IP on redirects, 600/min per API key, 10/min per IP on auth endpoints, all configurable via env;
  - GraphQL, click retention/partitioning and public hosting are stretch goals.
- Reconcile `openspec/ROADMAP.md` against the specification: add the web UI/dashboard changes, adjust rows whose scope the decisions changed (security, routing rules, click logging, analytics), keep change ids stable where scope did not move.

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
