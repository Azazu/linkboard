# Roadmap

Ordered plan of upcoming OpenSpec changes — the single repository-
visible home of the cross-change plan. Updated WITHIN changes: at the
session-end protocol when the plan shifts, and at archive time when a
change completes (its row is removed; history lives in git).

Derived from section 7 of the technical specification
(`docs/explanation/requirements.md`), which also carries each change's
exit criterion. Each row is a summary; the change's `proposal.md`
carries the full scope and the declared tier (the tier here is the
minimum). Ids are stable between the specification and this file.

## Stage 3 — analytics, QR, API keys

| # | Change id | Scope (summary) | Tier |
|---|---|---|---|
| 8 | `add-analytics-read-model` | six reports (summary, timeseries, countries, devices, referrers, variants) via window functions; read-only query services and DTOs; Redis tag cache with invalidation; admin global stats; `app:demo:seed` | medium |
| 9 | `add-qr-codes` | `endroid/qr-code`, owner-only `GET /api/v1/links/{id}/qr` as SVG/PNG | low |
| 10 | `add-api-keys-and-rate-limiting` | hashed API keys (plaintext once), access-token authenticator, per-key/per-user API limit with rate-limit headers, trusted-proxy handling; the prod deep health probe becomes available to a valid admin API key | high |

## Stage 4 — web UI, API polish, quality

| # | Change id | Scope (summary) | Tier |
|---|---|---|---|
| 11 | `add-web-ui` | Twig + Symfony UX + AssetMapper: login/register, dashboard, links list/create/edit/details/stats with charts, API keys, admin pages; security headers and CSP | medium |
| 12 | `polish-api-and-openapi` | OpenAPI descriptions and examples for every operation, filters and ordering, `ApiTestCase` contract tests, error catalogue in `docs/reference/` | medium |
| 13 | `harden-quality-and-docs` | README with screenshots, architecture diagram and benchmarks; PHPStan strictness sweep; migration down/up in CI; architecture tests; ADR index | low |

## Stretch — only after stages 1–4 are archived

| # | Change id | Scope (summary) | Tier |
|---|---|---|---|
| 14 | `stretch-partition-clicks` | monthly range partitioning of `clicks`, retention policy and job (ADR) | high |
| 15 | `stretch-graphql` | API Platform GraphQL endpoint with the same voters and rate limits | medium |
| 16 | `stretch-public-hosting` | public demo instance with HTTPS, seeded data and reset job; link in the README | medium |
