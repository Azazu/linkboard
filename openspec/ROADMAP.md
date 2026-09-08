# Roadmap

Ordered plan of upcoming OpenSpec changes — the single repository-
visible home of the cross-change plan. Updated WITHIN changes: at the
session-end protocol when the plan shifts, and at archive time when a
change completes (its row is removed; history lives in git).

Derived from the Linkboard brief (`docs/explanation/requirements.md`),
stages 1–4. Each row is a summary; the change's `proposal.md` carries
the full scope, exit criteria and the declared tier.

## Stage 1 — Symfony skeleton, links, synchronous redirect

| # | Change id | Scope (summary) | Tier |
|---|---|---|---|
| 1 | `bootstrap-dev-environment` | Docker Compose (php, nginx, postgres, redis), `.docker/`, Makefile targets, CI green on the empty app | low |
| 2 | `scaffold-symfony-app` | `symfony/skeleton`, Doctrine + migrations, PHPUnit/PHPStan/php-cs-fixer wired into `make check`, health route, problem-details errors | medium |
| 3 | `add-users-and-security` | `users`, registration/login, security firewall, roles user/admin | high |
| 4 | `add-link-crud` | `links` entity (slug, target_url, expires_at, max_clicks, is_active, UTM), owner-only voters, target URL validation | high |
| 5 | `add-redirect-with-sync-logging` | public `GET /{slug}`, expiry/limit enforcement, `clicks` entity written synchronously (baseline before async) | medium |

## Stage 2 — smart routing and async click logging

| # | Change id | Scope (summary) | Tier |
|---|---|---|---|
| 6 | `add-routing-rules` | JSONB `rules` with schema validation; device/OS, country, language matching; deterministic A/B variants | high |
| 7 | `add-async-click-logging` | Messenger `ClickRecorded` on the Redis transport, worker, failure transport, redirect never waits for the write | high |

## Stage 3 — analytics, QR, API keys, rate limiting

| # | Change id | Scope (summary) | Tier |
|---|---|---|---|
| 8 | `add-analytics-read-model` | clicks by time/country/device/referer via window functions; read-only query services; Redis cache with invalidation | medium |
| 9 | `add-qr-codes` | QR image per link (endpoint + cached render) | low |
| 10 | `add-api-keys-and-rate-limiting` | hashed API keys, per-key and per-IP limiters (Symfony RateLimiter on Redis) | high |

## Stage 4 — API Platform, quality, docs

| # | Change id | Scope (summary) | Tier |
|---|---|---|---|
| 11 | `expose-api-platform-resources` | `links` CRUD resource, `stats` read-only resource, `/api/v1` versioning, generated OpenAPI | medium |
| 12 | `harden-quality-and-docs` | PHPStan level raise, integration suite in CI, README with screenshots and architecture notes (Doctrine vs Eloquent, CQRS-lite) | low |
| 13 | `stretch-partition-clicks` | monthly partitioning of `clicks`, retention policy (ADR) | high |
