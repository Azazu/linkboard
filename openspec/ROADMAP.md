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

## Stage 1 — skeleton, accounts, links, synchronous redirect

| # | Change id | Scope (summary) | Tier |
|---|---|---|---|
| 1 | `bootstrap-dev-environment` | Docker Compose (php-fpm, nginx, postgres, redis, worker), Makefile targets, CI fix (`detect` job) and CI green on the empty app | high |
| 2 | `scaffold-symfony-app` | `symfony/skeleton`, Doctrine + migrations, API Platform under `/api/v1` with docs, problem-details errors, `/health`, PHPUnit/PHPStan/php-cs-fixer wired into `make check`, bounded-context `src/` layout | medium |
| 3 | `add-users-and-security` | `users`, registration (web + API), form-login firewall for the web, JWT firewall for the API, `app:user:promote/demote`, user blocking, voters skeleton, auth rate limit | high |
| 4 | `add-link-crud` | `links` entity (slug rules, target URL policy, expires_at, max_clicks, is_active, UTM), API Platform resource with owner voters, `shortUrl`, hard delete | high |
| 5 | `add-redirect-with-sync-logging` | public `GET /{slug}`, 404/410/302 matrix, UTM append, `clicks` table written synchronously (baseline before async), per-IP redirect limit | medium |

## Stage 2 — smart routing and async click logging

| # | Change id | Scope (summary) | Tier |
|---|---|---|---|
| 6 | `add-routing-rules` | JSONB `rules` with schema validation; device/OS (`matomo/device-detector`), country (`CountryResolver`: GeoLite2 + header + test map), language matching in fixed order; deterministic A/B; `resolved_by`/`variant` on clicks; hostile-input degradation | high |
| 7 | `add-async-click-logging` | Messenger `ClickRecorded` on the Redis transport, handler (visitor_hash, detection, is_bot, click_count), idempotency, retries + failure transport, Redis click counter as the limit authority (503 for limited links while Redis is down), discard of messages for deleted links, redirect never waits or fails on logging | high |

## Stage 3 — analytics, QR, API keys

| # | Change id | Scope (summary) | Tier |
|---|---|---|---|
| 8 | `add-analytics-read-model` | six reports (summary, timeseries, countries, devices, referrers, variants) via window functions; read-only query services and DTOs; Redis tag cache with invalidation; admin global stats; `app:demo:seed` | medium |
| 9 | `add-qr-codes` | `endroid/qr-code`, owner-only `GET /api/v1/links/{id}/qr` as SVG/PNG | low |
| 10 | `add-api-keys-and-rate-limiting` | hashed API keys (plaintext once), access-token authenticator, per-key/per-user API limit with rate-limit headers, trusted-proxy handling | high |

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
