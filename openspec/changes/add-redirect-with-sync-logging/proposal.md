# Proposal — add-redirect-with-sync-logging

**Risk-Tier:** high

Tier rationale: raised from the roadmap's `medium` by the user (2026-09-09). Triggers: security-sensitive input handling on the only anonymous hot path (slug, `User-Agent`, `Referer`, client IP; composition of the `Location` header from a validated target plus owner-provided UTM values), concurrency (the click limit must hold exactly under concurrent redirects), personal data (hashing of the client IP, no raw IP or UA at rest), and the per-IP rate limit. Gate 1 + Gate 2, a demonstrated failing input for every new guard, a green branch run before Gate 2 (auto review mode).

## Why

Links can be created, listed and edited, but nothing resolves them: `GET /{slug}` does not exist, so the product still does nothing for a visitor. This change makes a short link work end to end — the 404/410/302 matrix, UTM appended to the destination, the first click record and the first `clickCount` increment — with a deliberately **synchronous** write path as the stage-1 baseline (roadmap row 5). Stage 2 (`add-async-click-logging`, row 7) replaces that write path behind the same seam and is measured against this baseline.

## What Changes

1. **Public redirect endpoint** `GET /{slug}` (and `HEAD`) in `src/Redirect/`: outside `/api`, anonymous, no session started, no cookie set; the slug is matched exactly and case-sensitively against the slug alphabet; the route has the lowest priority so every application route (`/health`, `/login`, `/api/…`) wins — and the reserved-slug list already keeps those words out of `links`. **Security configuration change:** the `api` firewall pattern is narrowed from `^/api` to `^/api(/|$)` so that a valid slug such as `api-promo` is served by the public endpoint instead of meeting JWT authentication (an invalid bearer must not turn a redirect into 401); `/api/...` keeps the stateless firewall, tested in both directions.
2. **Response matrix** (FR-RED-1): unknown slug or `isActive = false` → 404; `expiresAt` in the past or the click limit reached → 410 Gone; otherwise 302 Found with `Location` = target with UTM appended. 301 is never used. 404/410 bodies are small HTML pages, or problem details when the client sends `Accept: application/json`. Every response of the endpoint carries `Cache-Control: no-store`; the 302 also carries `Referrer-Policy: no-referrer-when-downgrade` (FR-RED-5).
3. **UTM appending** (FR-LNK-6, D9): the link's UTM keys are added to the destination's query by processing the query as raw `key=value` pairs (never as a decoded map): pairs whose decoded key is a UTM key of the link are removed in every spelling, every other pair — repeated keys, dotted keys, bracket notation, encoded values — is kept byte for byte, the link's UTM pairs are appended, the fragment is kept.
4. **Exact click limit under concurrency**: the check-and-increment of `links.click_count` is one atomic conditional `UPDATE` (`… WHERE click_count < max_clicks`); a request that finds no row to update answers 410. `max_clicks` and `expires_at` are enforced at redirect time, never by a job (FR-LNK-7).
5. **`clicks` table** (§3.4, complete schema so later stages add no migration for their columns) with FK `ON DELETE CASCADE` to `links`, the `(link_id, occurred_at desc)` index and the partial `WHERE NOT is_bot` index; reviewed, reversible migration; a read-only entity mapping for schema validation and tests, while the hot path writes through the DBAL. The baseline row carries `id` (UUID v7), `link_id`, `occurred_at`, `visitor_hash` (salted SHA-256 of client IP and user agent, FR-CLK-2), `referer_host` (null when absent or same-origin), `is_bot = false`, `resolved_by = 'default'`; `variant`, `country`, `device_type`, `os`, `browser` stay null until routing rules and detection exist. The raw IP and the user agent are never persisted (NFR-SEC-6); `User-Agent` and `Referer` are length-bounded before processing, and the extracted referer host is validated against its column (≤ 255 bytes, valid UTF-8, no control characters, else null) so a hostile header can never fail or lose a click (NFR-SEC-4). A `HEAD` request gets the same status and headers from the link state alone and records nothing.
6. **Failure contract** (FR-RED-4 / NFR-REL-1 applied to the synchronous baseline, with explicit boundaries): if the link cannot be looked up (database unreachable) → 503 with `Retry-After: 5` for every slug — this baseline claims no availability during a database outage; if the link was resolved and only the click write fails, a link **without** `maxClicks` still answers 302 (the one case in which a 302 leaves no click; logged at `error`) and a link **with** `maxClicks` answers 503 with `Retry-After: 5` (the limit cannot be guaranteed; logged at `warning`) — the same rule FR-RED-3 states for the Redis counter later. A crash between commit and response may leave one recorded click without a delivered redirect (stated, not prevented).
7. **Per-IP rate limit** on redirects (FR-RED-6): sliding window, default 60 per minute from `RATE_LIMIT_REDIRECT_PER_IP`, Redis storage with the existing lock factory, client IP from the trusted-proxy configuration (FR-KEY-5); over the limit → 429 with `Retry-After`, HTML page or problem details. Same wiring pattern as the auth limiter, including the in-memory storage in the test environment. The limiter is the only Redis use on the hot path and it fails **open**: when its storage or lock is unavailable the request proceeds and a `warning` is logged — the limit holds while Redis is reachable, redirect availability never depends on it.
8. **Configuration**: `RATE_LIMIT_REDIRECT_PER_IP=60` and `VISITOR_HASH_SALT` (a non-secret local default in `.env`, a fixed value in `.env.test`; the real value lives in `.env.local`/CI and is referenced by name only; rotation invalidates unique-visitor continuity and is documented).
9. **Tests**: unit (UTM composition matrix incl. repeated/dotted/bracketed/encoded keys, visitor hash, referer host extraction and its column bounds, redirect decision matrix incl. lookup failure), integration (migration up/down, the recorder inserts one row and increments in one transaction, concurrent redirects never pass `max_clicks`), web (the full matrix over HTTP, `api`-prefixed slug public with no and invalid bearer while `/api/v1/me` stays 401, headers, no `Set-Cookie`, `Accept` negotiation, `HEAD`, rate limit 429 and fail-open on limiter failure, hostile referer hosts on limited and unlimited links, lookup failure → 503, write failure → 302 for an unlimited link and 503 for a limited one, deleting a link cascades to its clicks).
10. **Docs**: how-to gains a "Redirect" section (curl -I examples, the salt and limit variables, the baseline note); roadmap row 5 and requirements §7 row 5 record the raised tier; their scope wording stays as it is.

## Capabilities

### New Capabilities

- `redirect`: public resolution of a short link — endpoint, 404/410/302 matrix, UTM appending, headers and bodies, exact click limit, rate limit, degradation when recording fails.
- `click-logging`: what a successful redirect leaves behind — one click record and one `clickCount` increment per redirect, the record's contents, personal-data minimisation, input bounds, cascade on link deletion.

### Modified Capabilities

None. `links` already states that `clickCount` is returned and that dependent tables cascade on delete; this change gives those statements their first producer without changing them.

## Non-goals

- Asynchronous click logging (`ClickRecorded` message, Redis Streams transport, worker, retries, failed transport), the Redis click counter and its `EVAL` script, and the "no SQL write on the hot path" rule (FR-RED-2/3/4): `add-async-click-logging`, roadmap row 7. This baseline writes synchronously on purpose and says so in the how-to.
- Routing rules, device/OS/bot detection, country and language resolution, A/B variants (`add-routing-rules`, row 6): `resolved_by` is always `default`, `is_bot` always false here.
- Analytics reads, report cache and its invalidation (`add-analytics-read-model`); QR codes; the web UI beyond the four small error pages this endpoint needs.
- API-key and per-user API rate limits (`add-api-keys-and-rate-limiting`, row 10).
- Performance measurement (NFR-PERF-1) — measured once the hot path is final (row 7).
- `clicks` partitioning (stretch).

## Impact

- New: `src/Redirect/**` (controller, resolver/decision, UTM appender, referer host, rate-limit handling), `src/Click/**` (read-only `Click` entity, `ClickRecorderInterface` + DBAL recorder, visitor hasher), `migrations/Version*.php` (`clicks`), `templates/redirect/*.html.twig` (404, 410, 429, 503), `tests/Unit|Integration|Web/**`, how-to section.
- Modified: `config/packages/security.yaml` (**security-sensitive**: `api` firewall pattern `^/api(/|$)`, `^/health$`), `config/packages/framework.yaml` (`redirect_ip` limiter + test storage), `config/services.yaml` (recorder binding), `.env` / `.env.test` (two variables), `src/Auth/Security/JwtProblemDetailsSubscriber.php` and `AuthRateLimitSubscriber.php` delegating to the problem-details helper moved to `src/Shared/Api/` (pure move, behavior unchanged).
- No new dependencies: routing, rate limiter, lock, uid, DBAL, Twig are installed; hashing with `hash('sha256')`.
