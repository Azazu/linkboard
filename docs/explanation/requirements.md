# Linkboard — Technical Specification

**Status:** normative for all OpenSpec changes listed in `openspec/ROADMAP.md`.
**Provenance:** expanded from the Linkboard brief of the author's portfolio plan (project 2). Behavioral decisions were fixed in the requirements interview of 2026-09-08; their authoritative record (D1–D20) lives in the proposal of the change `write-requirements-spec` and is traced to sections of this document in Appendix A. A decision changes only through a new change that edits this document and the roadmap together.

Per-capability requirements with SHALL statements and scenarios are derived from this document into `openspec/specs/<capability>/spec.md` by the implementing changes; where the two disagree, the spec is corrected to match this document or this document is amended in the same change — never left inconsistent.

---

## 0. Goal and positioning

Linkboard is a short-link service with smart routing and click analytics, built as a portfolio project. Its purpose is to show a prospective employer the enterprise side of modern PHP on a domain the author knows from production work on device-aware link routing.

What it demonstrates:

- **Symfony 8.1 / PHP 8.4** as the application framework, with a proper service layer and DI container (decision and trade-off against 7.4 LTS: `docs/adr/ADR-001-symfony-8-on-php-8.4.md`).
- **Doctrine ORM as a DataMapper** — entities without persistence logic, repositories behind interfaces — as a deliberate contrast with ActiveRecord-style ORMs (Eloquent, Yii AR).
- **API Platform** for a versioned REST API with generated OpenAPI documentation and Swagger UI.
- **PostgreSQL 16**: JSONB routing rules validated on write, window functions and `date_trunc` for analytics, optional table partitioning.
- **Redis 7**: rate limiting, click counters on the hot path, cache of hot reports, Messenger transport.
- **Symfony Messenger**: the redirect never waits for the click to be written.
- **DDD-lite and CQRS-lite**: bounded contexts in `src/`, a click write model separated from an analytics read model.
- **A server-rendered web UI** on Twig + Symfony UX (Turbo, Stimulus, Chart.js) with a dashboard, so the project is usable and screenshot-able, not API-only.
- **Engineering discipline**: strict types, PHPStan, PHP-CS-Fixer, PHPUnit across unit/integration/API layers, GitHub Actions CI, reversible migrations, ADRs, and an AI-assisted development workflow with independent review gates (`AGENTS.md`, `docs/adr/ADR-000-agent-workflow.md`).

Interview talking points the design must keep true: no synchronous database write on the redirect path; analytics computed in SQL, not in PHP loops; rule matching order explicit and tested as a matrix; API keys hashed at rest; every schema change a reviewed migration.

---

## 1. Roles and permissions

| Role | Who | Obtains the role |
|---|---|---|
| **Guest** | anyone following a short link or opening the public pages | no account |
| **User** | registered account | self-registration (web form or API) |
| **Admin** | operator of the instance | `ROLE_ADMIN` granted by a console command (`app:user:promote <email>`); no self-service path |

Permission matrix. "Own" means the link or key belongs to the authenticated user; admins pass every owner check via the same voters.

| Action | Guest | User | Admin |
|---|---|---|---|
| Follow a short link (`GET /{slug}`) | ✓ | ✓ | ✓ |
| Register, log in (web or API) | ✓ | — | — |
| Create links | — | ✓ | ✓ |
| Read, update, deactivate, delete a link | — | own | any |
| View a link's analytics and QR code | — | own | any |
| Create and revoke API keys | — | own | own |
| List all users, block/unblock a user | — | — | ✓ |
| List all links, deactivate any link | — | — | ✓ |
| View global statistics | — | — | ✓ |
| Change another user's role | — | — | console only |

Authorization is enforced by Symfony security voters (`LinkVoter`, `ApiKeyVoter`) applied identically to API operations and web controllers. A blocked user keeps their data but every authenticated request is refused with 403 and existing JWTs and API keys stop working immediately (the user check happens per request, not per token issuance). Links of a blocked user keep redirecting unless deactivated.

---

## 2. Functional requirements

Requirement ids (`FR-<AREA>-<n>`) are stable references for specs, tasks and tests. Wording: SHALL = mandatory, SHOULD = expected unless a documented reason exists, MAY = optional.

### 2.1 Accounts and authentication (AUTH)

- **FR-AUTH-1** A guest SHALL be able to register with email and password. Email is unique case-insensitively; passwords are at least 12 characters; passwords are hashed with Symfony's default `auto` hasher (bcrypt/argon2id as available). No email verification is sent (non-goal).
- **FR-AUTH-2** The web UI SHALL authenticate users with a session form login (`/login`, `/logout`, CSRF-protected) on its own firewall. Remember-me is not provided.
- **FR-AUTH-3** The API SHALL authenticate with either a JWT (`Authorization: Bearer <jwt>`, issued by `POST /api/v1/auth/token` from email + password, lifetime 1 hour, no refresh tokens) or an API key (`Authorization: Bearer <key>` distinguished by prefix, see 2.8). Both resolve to the same user entity and the same voters.
- **FR-AUTH-4** Auth endpoints (`/login`, `/register`, `/api/v1/auth/*`) SHALL be rate limited at 10 requests per minute per client IP (configurable, see 2.8).
- **FR-AUTH-5** An admin SHALL be created only by the console command `app:user:promote <email>`; a matching `app:user:demote` exists. There is no UI or API operation that changes roles.
- **FR-AUTH-6** An admin SHALL be able to block and unblock a user. A blocked user cannot log in, and every authenticated request with their credentials is refused with 403 `blocked`.

### 2.2 Links (LNK)

- **FR-LNK-1** A user SHALL create a link from: `target_url` (required), optional custom `slug`, optional `expires_at`, optional `max_clicks`, optional UTM parameters, optional routing `rules` (2.3). When `slug` is omitted the service generates one.
- **FR-LNK-2** Generated slugs SHALL be 7 characters from the base62 alphabet `[A-Za-z0-9]`, produced from a cryptographically secure source, retried on collision (bounded, then 500 with a logged error).
- **FR-LNK-3** Custom slugs SHALL match `^[A-Za-z0-9_-]{3,32}$` as the entire string (no trailing newline), be unique case-sensitively (`abc` and `ABC` are different links), and not appear in the reserved list. The reserved list SHALL contain at least every top-level web and API route of the application: `api`, `admin`, `login`, `logout`, `register`, `dashboard`, `links`, `api-keys`, `health`, `docs`, `qr`, `assets`, `build`, `bundles`, `_profiler`, `_wdt`, `_error`. The list is a single PHP constant used by validation and by tests.
- **FR-LNK-4** The slug SHALL be immutable after creation: update operations that carry a different slug are rejected with 422.
- **FR-LNK-5** `target_url` and every rule or variant target SHALL pass the target URL policy: absolute URL, scheme `http` or `https`, host present, no userinfo, no backslash or control character anywhere, host not `localhost`, not a literal IP in loopback (`127.0.0.0/8`, `::1`), link-local (`169.254.0.0/16`, `fe80::/10`) or private (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `fc00::/7`) ranges — host mapped to ASCII per UTS #46 as browsers do, literal IPs recognised in every spelling a browser accepts (shorthand, decimal, hex, octal, trailing dot, fullwidth digits) and percent-encoded or unmappable hosts rejected — length ≤ 2048. No DNS resolution is performed at validation time; the limits of this policy (a public hostname may still resolve privately) are documented in the security notes of the README. Custom schemes such as `market://` or `itms-apps://` are rejected; app-store links use their `https://` forms.
- **FR-LNK-6** UTM parameters SHALL be stored as up to five keys (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, each ≤ 255 chars) and appended to the resolved destination at redirect time. Existing query parameters of the destination are preserved; a UTM key already present on the destination is overwritten by the link's value.
- **FR-LNK-7** `expires_at` (timestamp with time zone, must be in the future on write) and `max_clicks` (positive integer) SHALL be enforced at redirect time (2.4), never by a scheduled job. Both are optional and independent.
- **FR-LNK-8** A link has `is_active`; the owner and admins MAY toggle it. Inactive links respond 404 on redirect and keep their data and analytics.
- **FR-LNK-9** A user SHALL list their own links with pagination (default 30, max 100 per page), filter by `is_active` and by slug substring, and order by `created_at` (default desc) or `click_count`.
- **FR-LNK-10** The owner and admins SHALL be able to delete a link. Deletion is hard: the link row and its clicks are removed (`ON DELETE CASCADE`) and the slug becomes available again; the Redis counter and cached reports are dropped. `ClickRecorded` messages still in the queue for the deleted link are discarded by the handler without retry (FR-CLK-5). The web UI asks for confirmation. Deletion is a `high`-tier operation in the workflow sense.
- **FR-LNK-11** Every link response SHALL include the denormalized `click_count` (eventually consistent, maintained by the click handler, see 2.5) and the `short_url` built from the configured public base URL (`APP_PUBLIC_URL`).

### 2.3 Routing rules (RUL)

- **FR-RUL-1** `rules` is a JSONB document validated on write against a schema (a PHP validator with the same structure as the JSON Schema published in `docs/reference/rules-schema.json`). Invalid documents are rejected with 422 listing the path of each violation.
- **FR-RUL-2** Document shape (version 1):

  ```json
  {
    "version": 1,
    "rules": [
      { "match": { "device": ["smartphone", "tablet"], "os": ["iOS"] }, "target": "https://apps.apple.com/app/id123" },
      { "match": { "os": ["Android"] }, "target": "https://play.google.com/store/apps/details?id=com.example" },
      { "match": { "country": ["DE", "AT", "CH"] }, "target": "https://example.de/" },
      { "match": { "language": ["uk", "ru"] }, "target": "https://example.com/ua/" }
    ],
    "variants": [
      { "name": "A", "weight": 50, "target": "https://example.com/landing-a" },
      { "name": "B", "weight": 50, "target": "https://example.com/landing-b" }
    ]
  }
  ```

  Constraints: `rules` ≤ 20 entries, each with exactly one dimension in `match` (`device`+`os` count as the device dimension and may appear together), non-empty value arrays; `variants` 2–4 entries, distinct names, integer weights summing to 100; every `target` passes the URL policy (FR-LNK-5). Both `rules` and `variants` are optional; `rules: null` means plain redirect.
- **FR-RUL-3** Dimension vocabularies: `device` ∈ {`desktop`, `smartphone`, `tablet`, `other`}; `os` ∈ {`iOS`, `Android`, `Windows`, `macOS`, `Linux`, `other`}; `country` = ISO 3166-1 alpha-2 upper-case; `language` = ISO 639-1 primary subtag lower-case (first value of `Accept-Language` by quality).
- **FR-RUL-4** Matching order SHALL be fixed and explicit: (1) device-dimension rules in document order, (2) country rules in document order, (3) language rules in document order, (4) A/B variants if present, (5) `target_url`. The first match wins. Rules with an unresolvable dimension (unknown country, no `Accept-Language`) are skipped, not treated as matches.
- **FR-RUL-5** A/B assignment SHALL be deterministic per visitor without cookies: `variant = pick(weights, crc32(link_id ‖ client_ip ‖ user_agent) mod 100)`. The same visitor gets the same variant for the same link while their IP and user agent are unchanged. The chosen variant name and the dimension that resolved the target (`device`, `country`, `language`, `variant`, `default`) are recorded on the click.
- **FR-RUL-6** Device and OS SHALL be resolved with `matomo/device-detector` from the `User-Agent` header (and client hints when present). Country SHALL be resolved through a `CountryResolver` interface with two implementations: `GeoLite2CountryResolver` (`geoip2/geoip2`, database path from `GEOIP_DATABASE_PATH`, file not committed, resolver disabled with a warning when the file is missing) and `HeaderCountryResolver` (reads a proxy-supplied header, name from `GEOIP_COUNTRY_HEADER`, default `CF-IPCountry`). Selection and order are configuration; in tests a fixed-map resolver is used.
- **FR-RUL-7** Rule evaluation SHALL never throw. Hostile input — a `User-Agent` over 1024 bytes, with invalid UTF-8 or control characters; an `Accept-Language` over 256 bytes or outside the header grammar; oversized or malformed client hints — is never parsed: the visitor gets the default target with `resolved_by = default`, the click is still recorded, and one `notice` names the link and the issue classes, never the values. Missing headers and well-formed but unrecognised values are unresolved dimensions and are skipped per FR-RUL-4 without a log record. Any exception inside detection, geolocation or evaluation degrades exactly like hostile input, with the exception class in the `notice`.

### 2.4 Redirect (RED)

- **FR-RED-1** `GET /{slug}` is public, outside the API firewall and outside `/api`. Response codes: unknown slug or `is_active = false` → 404; `expires_at` in the past or click limit reached → 410 Gone; otherwise 302 Found with `Location` = resolved target with UTM appended. 301 is never used; 503 occurs only in the Redis-down case of FR-RED-3. 404 and 410 bodies are small HTML pages (web) or problem details when `Accept: application/json`.
- **FR-RED-2** The hot path SHALL perform at most one SQL `SELECT` (link by slug, indexed) and at most one Redis round trip for the click counter (the single `EVAL` call of FR-RED-3, always one command, no `EVALSHA`/`NOSCRIPT` fallback); it SHALL NOT perform any SQL write. Rule evaluation, UTM composition and message dispatch are in-process.
- **FR-RED-3** The click limit SHALL be enforced against a Redis counter `link:{id}:clicks` by one atomic Lua script executed with a single `EVAL` call (the script body is sent every time; `EVALSHA` is deliberately not used, because its `NOSCRIPT` fallback would cost a second round trip after a Redis restart or script-cache flush and break FR-RED-2) that receives the key, the seed value `links.click_count` and `max_clicks` from the already-loaded link row and, atomically: lifts the key to the seed when it is absent or lower (never lowering it), compares the current value with `max_clicks`, and increments only when the redirect is allowed, returning `allowed | exhausted`. Because check, seed and increment happen in one script, concurrent first redirects after key loss cannot double-seed or race past the limit, and a limit set or changed after an unlimited period takes effect on the next redirect that loads the link. The counter, not `links.click_count`, is the authority for the limit because `click_count` is maintained asynchronously by the worker (FR-CLK-3) and lags by the queue backlog. Links without `max_clicks` do not touch Redis on the hot path. If Redis is unavailable, a link **with** `max_clicks` SHALL respond 503 with `Retry-After: 5` (problem details or a small HTML page) and log at `warning`; a link without a limit redirects normally. Guarantee boundary: the limit holds exactly while the counter key exists and reflects every accepted redirect since it was last seeded. Each redirect evaluates the link row it loaded and never re-reads it, so requests in flight at a limit change complete under the limit they loaded (bounded by the in-flight count; once persisted, the persisted count stops further redirects). Whenever the key is seeded or lifted (first redirect of a limited link, a limit set after an unlimited period, a Redis data loss) the seed is `links.click_count` as the seeding request read it (a request never re-reads the row), which excludes every accepted redirect not persisted at that read — still queued or persisted since, dispatch-failed, parked on `failed`, or lost to a crash between the increment and the dispatch — so the limit may be exceeded by at most that many per seeding, compounding with repeated key loss; `clicks` stays the exact record of what was persisted. This is stated in the how-to, and Redis persistence (AOF) is enabled in the Compose setup to make key loss exceptional.
- **FR-RED-4** On every 302 the redirect SHALL dispatch a `ClickRecorded` message (2.5) to the async transport before the response is sent, without waiting for it to be handled. Dispatch failure (transport down) SHALL be caught and logged at `error`; the redirect is still returned. "Failure to log never turns into a failed redirect" is a tested property.
- **FR-RED-5** Redirect responses SHALL carry `Cache-Control: no-store` and `Referrer-Policy: no-referrer-when-downgrade`, and SHALL NOT set cookies.
- **FR-RED-6** Anonymous redirects SHALL be rate limited per client IP (default 60/min, sliding window); over the limit → 429 with `Retry-After`.

### 2.5 Click logging (CLK)

- **FR-CLK-1** `ClickRecorded` is an immutable message carrying the finished click row: `click_id`, `link_id`, `occurred_at`, `country`, `device_type`, `os`, `browser`, `is_bot`, `resolved_by`, `variant`, `referer_host` and the salted `visitor_hash`. Detection, geolocation and hashing happen in the request — routing needs them there (2.3) — so the message never carries the client IP, the user agent or header values. It is dispatched to the `async` transport (Redis Streams) and consumed by `messenger:consume async`.
- **FR-CLK-2** The handler SHALL persist the message as one `clicks` row: `country`, `device_type`, `os`, `browser`, `is_bot`, `referer_host` (host part of `Referer`, null when absent or same-origin), `visitor_hash` = hex SHA-256 of `VISITOR_HASH_SALT ‖ client_ip ‖ user_agent` computed in the request, `variant`, `resolved_by`, `occurred_at`. The raw IP is never persisted, never put on the queue and never logged at `info` or above.
- **FR-CLK-3** The handler SHALL increment `links.click_count` in the same transaction as the insert (`UPDATE … SET click_count = click_count + 1`).
- **FR-CLK-4** Handling SHALL be idempotent per message: `ClickRecorded` carries a UUID `click_id` used as the `clicks` primary key; a redelivered message hits the unique constraint and is acknowledged without a second increment.
- **FR-CLK-5** Failed messages SHALL be retried (3 attempts, exponential backoff, multiplier 2, starting at 1 s, no jitter — exactly 1 s, 2 s, 4 s) and then routed to the `failed` transport (Doctrine, `messenger_messages` table) for inspection with `messenger:failed:show` / `:retry`. Exception: a message whose `link_id` no longer exists (link deleted after the redirect, FR-LNK-10) is acknowledged and discarded on the first attempt with a log line at `info` — the handler catches the foreign-key failure and returns normally (an unrecoverable exception would still be parked by Messenger's failure listener) — never retried and never sent to `failed`. The delete-then-consume race is a required test. Handler exceptions never affect a redirect (they happen in the worker).
- **FR-CLK-6** Bots (`is_bot = true`) SHALL be stored but excluded from reports by default (2.6).

### 2.6 Analytics (ANL)

- **FR-ANL-1** Analytics is a read model: query services in `src/Analytics/` return immutable DTOs computed by SQL; they never load `Click` entities and never aggregate in PHP.
- **FR-ANL-2** Reports per link, each over a half-open UTC period `from`/`to` (`from` inclusive, `to` exclusive; default: the current UTC day and the 29 before it — `to` is the start of the next UTC day, so identical default requests share one cache entry all day; `to` alone gives the 30 days before it, `from` alone runs to the default `to`; max 366 days) and with `includeBots` (default false); a malformed, out-of-range or inconsistent parameter is 422 with one violation per parameter; every report echoes its effective parameters and carries `generatedAt`:

  | Report | Content | SQL mechanism |
  |---|---|---|
  | `summary` | all-time total clicks, unique visitors, first/last click; clicks today (UTC); clicks in period vs the previous period of the same length (delta %, null when the previous period is empty) | `count`, `count(distinct visitor_hash)`, `count(*) FILTER (WHERE …)` per window |
  | `timeseries` | clicks and unique visitors per bucket, `granularity` ∈ {`hour`, `day`} (hour allowed for periods ≤ 14 days); gaps filled with zeros; running total | `date_trunc` (in UTC) + `generate_series` left join; `sum() over (order by bucket)` |
  | `countries` | top N (default 10, max 50) countries with count and share; unknown country is one group (`null`) | `count`, `sum() over ()` for share, `rank() over (order by count desc)` (ties share a rank) |
  | `devices` | breakdown by device type and by OS, count and share (`null` = unrecognised) | as above, grouped twice |
  | `referrers` | top N referrer hosts, `direct` for null | as above |
  | `variants` | clicks and uniques per A/B variant, share — over the clicks a variant resolved only | as above, `WHERE resolved_by = 'variant'` |

- **FR-ANL-3** Reports SHALL be served through the Redis cache (Symfony Cache, tag-aware, keys namespaced per environment): key includes link id, report, period, granularity, limit, bots flag; TTL 300 s; every cached entry is tagged `link-{id}`; the tag is invalidated on link update, deactivation and deletion (deletion also invalidates `global`, because the top-links report names links). The cache is never a dependency: while Redis is unavailable a report is computed from PostgreSQL and answered 200 with a `warning` record, and a refused invalidation never fails the link write (a `warning` names the link id). Clicks arriving between refreshes are visible after TTL — bounded staleness is accepted, `generatedAt` states it, and the UI shows it ("updated up to 5 minutes ago").
- **FR-ANL-4** Global admin statistics: `summary` (totals of users, links, active links, clicks; clicks today; no period, `includeBots` only), `timeseries` (clicks and running total per bucket over every link, same parameters as the per-link one), `top-links` (top N links by clicks in period with slug, owner id, clicks, unique visitors, rank) — same mechanisms, cached under tag `global`. The global timeseries carries no distinct-visitor count: over every link's rows and every bucket that count alone puts it over NFR-PERF-2 (534 ms as `count(DISTINCT)`, 356 ms in the best measured formulation) — a revision of the original shape accepted by the user on 2026-09-11 (change `add-analytics-read-model`, proposal); the stretch `click_daily` aggregate is the way if it is ever wanted.
- **FR-ANL-5** Analytics queries are tested against real PostgreSQL with fixture clicks; every report has at least one test that asserts numbers, and the timeseries test covers gap filling and DST-free UTC bucketing (all timestamps stored and bucketed in UTC).

### 2.7 QR codes (QR)

- **FR-QR-1** For every link the owner (or admin) SHALL obtain a QR code of `short_url` as SVG (default) or PNG (`?format=png`, 512 px), generated with `endroid/qr-code`, via `GET /api/v1/links/{id}/qr` and a download button in the web UI. Responses are cacheable for 1 day (`Cache-Control: private, max-age=86400`); no server-side cache is required. There is no public QR endpoint.

### 2.8 API keys and rate limiting (KEY)

- **FR-KEY-1** A user SHALL create named API keys (`name` ≤ 64 chars, optional `expires_at`), up to 10 active per user. The plaintext key is returned exactly once, on creation; the database stores `key_hash = SHA-256(key)` and a display `prefix` (first 8 characters). Keys have the form `lb_` + 40 base62 characters (≈238 bits of entropy).
- **FR-KEY-2** Authentication with an API key SHALL look the key up by the SHA-256 of the presented value (exact-match index lookup; no plaintext comparison exists), reject expired or revoked keys with 401, and update `last_used_at` at most once per minute per key.
- **FR-KEY-3** A user SHALL list their keys (prefix, name, created, last used, expires) and revoke a key (`revoked_at` set; row retained for audit).
- **FR-KEY-4** Rate limiting uses Symfony RateLimiter with Redis storage, sliding-window policy, limits from env with defaults: `RATE_LIMIT_REDIRECT_PER_IP=60` per minute, `RATE_LIMIT_API_PER_KEY=600` per minute (JWT-authenticated requests use the same limit keyed by user id), `RATE_LIMIT_AUTH_PER_IP=10` per minute. Over the limit → 429 problem details with `Retry-After`; `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers are set on API responses.
- **FR-KEY-5** Client IP SHALL be taken from Symfony's trusted-proxy mechanism (`TRUSTED_PROXIES`, `TRUSTED_HEADERS` env); spoofed `X-Forwarded-For` from an untrusted peer is ignored. This affects rate limiting, geo and the A/B hash and is tested.

### 2.9 Web UI (WEB)

Server-rendered Twig pages using the same application services and voters as the API (no HTTP self-calls). Web controllers and forms live in `src/Web/`, templates in `templates/`, Stimulus controllers and CSS in `assets/`. Symfony UX: Turbo for navigation and form submission, Stimulus controllers for copy-to-clipboard and the rules editor, `symfony/ux-chartjs` for charts. Assets through AssetMapper (no Node build step).

- **FR-WEB-1** Pages: `/login`, `/register`, `/dashboard` (totals, clicks-per-day chart for the user's links, last 10 links), `/links` (paginated list with filters), `/links/new`, `/links/{id}` (details, QR, copy button), `/links/{id}/edit` (target, expiry, limit, UTM, rules as a structured form plus raw-JSON fallback with schema errors shown), `/links/{id}/stats` (all reports of 2.6 as charts and tables, period and granularity selectors, bots toggle), `/api-keys`, `/admin/users`, `/admin/links`, `/admin/stats`.
- **FR-WEB-2** Forms are Symfony Forms with CSRF; validation messages are the same constraint violations the API returns.
- **FR-WEB-3** The UI is responsive (usable at 375 px width), needs no JavaScript for anything but charts and convenience actions, and is styled with a small CSS framework served from `assets/` (Pico.css or equivalent, vendored via AssetMapper importmap, no CDN at runtime).
- **FR-WEB-4** Swagger UI is served by API Platform at `/api/docs` and linked from the UI footer; the OpenAPI JSON is at `/api/docs.json`.

### 2.10 Administration (ADM)

- **FR-ADM-1** Admin API and pages list users (email, role, blocked, link count, created) and links of every user (owner, slug, target, active, clicks) with pagination.
- **FR-ADM-2** Admin actions: block/unblock user (FR-AUTH-6), deactivate/reactivate any link (FR-LNK-8), delete any link (FR-LNK-10), view global statistics (FR-ANL-4). Every admin action is logged at `info` with actor id and target id (no personal data beyond ids).

---

## 3. Data model (PostgreSQL 16)

All tables use `timestamptz` in UTC, UUID v7 primary keys generated in PHP (`symfony/uid`) except `clicks`, whose primary key is the message's `click_id` UUID. Every change is a reviewed, reversible Doctrine migration. Names below are the physical names; entity classes live in the bounded-context directories listed in `AGENTS.md`.

### 3.1 `users`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `email` | varchar(180) | unique index on `lower(email)` |
| `password_hash` | varchar(255) | |
| `roles` | jsonb | Symfony convention, e.g. `["ROLE_USER"]`, `["ROLE_USER","ROLE_ADMIN"]` |
| `is_blocked` | boolean not null default false | |
| `created_at`, `updated_at` | timestamptz | |

### 3.2 `api_keys`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `user_id` | uuid FK → users, `ON DELETE CASCADE` | index |
| `name` | varchar(64) | |
| `key_hash` | char(64) | SHA-256 hex, unique |
| `prefix` | char(8) | display only |
| `expires_at` | timestamptz null | |
| `revoked_at` | timestamptz null | |
| `last_used_at` | timestamptz null | |
| `created_at` | timestamptz | |

### 3.3 `links`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `owner_id` | uuid FK → users, `ON DELETE CASCADE` | index |
| `slug` | varchar(32) | unique (case-sensitive collation `"C"`), immutable |
| `target_url` | text | ≤ 2048, URL policy |
| `rules` | jsonb null | schema of 2.3; GIN index not needed (never queried by content) |
| `utm` | jsonb null | object with the five allowed keys |
| `expires_at` | timestamptz null | |
| `max_clicks` | integer null | check `> 0` |
| `click_count` | integer not null default 0 | denormalized, eventually consistent |
| `is_active` | boolean not null default true | |
| `created_at`, `updated_at` | timestamptz | index `(owner_id, created_at desc)` |

### 3.4 `clicks` (write model; append-only)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | = `click_id` of the message (idempotency) |
| `link_id` | uuid FK → links, `ON DELETE CASCADE` | |
| `occurred_at` | timestamptz not null | |
| `country` | char(2) null | |
| `device_type` | varchar(16) null | vocabulary of FR-RUL-3 |
| `os` | varchar(16) null | |
| `browser` | varchar(32) null | |
| `is_bot` | boolean not null default false | |
| `referer_host` | varchar(255) null | |
| `visitor_hash` | char(64) not null | |
| `variant` | varchar(16) null | |
| `resolved_by` | varchar(8) not null | `device`, `country`, `language`, `variant`, `default` |

Indexes: `(link_id, occurred_at desc)`; partial index `(link_id, occurred_at) WHERE NOT is_bot` for the default reports. No `user_agent` column: the UA is consumed by detection and discarded (data minimization). Partitioning by month is a stretch change (section 9); the schema above is designed so that `occurred_at` can become part of the partition key without changing readers.

### 3.5 Infrastructure tables and Redis keys

- `messenger_messages` — Doctrine transport for the `failed` queue only (the `async` transport is Redis Streams).
- `doctrine_migration_versions`.
- Redis: `link:{id}:clicks` (counter, no TTL, deleted with the link), rate limiter keys (prefix configured per limiter, managed by the component), cache pool keys with tags `link-{id}` and `global`, Messenger stream `messages`.

No materialized views in the core stages; a `click_daily` aggregate is listed as stretch.

---

## 4. API

- Base path `/api/v1`; all API Platform resources are registered under it. `/api/docs` (Swagger UI) and `/api/docs.json` (OpenAPI 3.1) are generated. JSON only (`application/json` request, `application/json` and `application/problem+json` responses); JSON-LD/Hydra and GraphQL are disabled (GraphQL is stretch).
- Errors: RFC 9457 problem details for every non-2xx, including 404/405/429 and framework exceptions; validation errors are 422 with a `violations[]` array of `{propertyPath, message, code}`; no stack traces outside `APP_ENV=dev`.
- Pagination: page-based (`page`, `itemsPerPage` ≤ 100), response carries `totalItems`, `page`, `itemsPerPage`, `items`.
- Authentication as in FR-AUTH-3. Anonymous access only to `/api/v1/auth/*` and the docs.

| Method & path | Auth | Purpose |
|---|---|---|
| `POST /api/v1/auth/register` | none | create account (FR-AUTH-1) |
| `POST /api/v1/auth/token` | none | email + password → `{token, expiresAt}` |
| `GET /api/v1/me` | user | current user (id, email, roles, createdAt) |
| `GET /api/v1/links` | user | own links, filters `isActive`, `slug`, `order[createdAt|clickCount]` |
| `POST /api/v1/links` | user | create (FR-LNK-1…7, FR-RUL-1) |
| `GET /api/v1/links/{id}` | owner/admin | details incl. `shortUrl`, `clickCount` |
| `PATCH /api/v1/links/{id}` | owner/admin | update `targetUrl`, `expiresAt`, `maxClicks`, `utm`, `rules`, `isActive` (merge-patch) |
| `DELETE /api/v1/links/{id}` | owner/admin | hard delete (FR-LNK-10) |
| `GET /api/v1/links/{id}/qr` | owner/admin | SVG/PNG (FR-QR-1) |
| `GET /api/v1/links/{id}/stats/{summary,timeseries,countries,devices,referrers,variants}` | owner/admin | reports of 2.6 with `from`, `to`, `granularity`, `limit`, `includeBots` |
| `GET /api/v1/api-keys` · `POST /api/v1/api-keys` | user | list / create (plaintext once) |
| `DELETE /api/v1/api-keys/{id}` | owner | revoke |
| `GET /api/v1/admin/users` | admin | list users |
| `POST /api/v1/admin/users/{id}/block` · `/unblock` | admin | FR-AUTH-6 |
| `GET /api/v1/admin/links` | admin | all links |
| `GET /api/v1/admin/stats/summary` · `/timeseries` · `/top-links` | admin | FR-ANL-4 |
| `GET /{slug}` | none | redirect, outside `/api` (2.4) |
| `GET /health` | none | liveness: `{"status":"ok"}`; `?deep=1` also checks DB and Redis, admin-only in prod |

Stability rules: field names are camelCase in JSON; `id`s are UUID strings; timestamps are RFC 3339 UTC; the schema of any `/api/v1` response changes only additively — a breaking change means `/api/v2` (non-goal).

---

## 5. Technology stack and justification

Anti-overengineering rule (`openspec/config.yaml`): every component below names the role an existing one could not cover. The implementing change re-justifies a package when it actually adds it and pins versions there.

| Component | Role | Why this, not something already present |
|---|---|---|
| PHP 8.4, Symfony 8.1 (`symfony/skeleton` + Flex) | runtime and framework | project premise; current majors of the Doctrine bundles and PHPUnit require PHP ≥ 8.4 (ADR-001) |
| Doctrine ORM 3 / DBAL 4 / Migrations | persistence as DataMapper, reviewed schema changes | project premise; contrast with ActiveRecord is a stated goal |
| API Platform 4 | REST resources, OpenAPI, Swagger UI, problem details, pagination | replaces hand-written controllers, serializers and docs for every resource |
| `lexik/jwt-authentication-bundle` | JWT issuance and validation for the API firewall | Symfony has access tokens but no JWT signer/issuer; writing one is security-sensitive code with no portfolio value |
| Symfony Security (form login, access-token authenticator, voters) | web session auth, API-key auth, authorization | built in |
| Symfony Messenger + Redis transport (`symfony/redis-messenger`) | async click logging, retries, failed queue | built in; Redis is already in the stack, so no RabbitMQ |
| Symfony RateLimiter + Cache (Redis adapter) | rate limits, hot-report cache | built in |
| `matomo/device-detector` | device/OS/browser/bot detection | the de-facto open UA database; regexes by hand are wrong within months |
| `geoip2/geoip2` (+ GeoLite2 database, not committed) | IP → country | the only offline, license-free-for-dev geo source; abstracted behind `CountryResolver` so it is swappable |
| `endroid/qr-code` | QR rendering (SVG/PNG) | no QR support in Symfony; the library is small and maintained |
| Twig, Symfony UX Turbo/Stimulus, `symfony/ux-chartjs`, AssetMapper | web UI without a Node toolchain | server-rendered UI is the cheapest screenshot-able frontend that still shows modern Symfony |
| `symfony/uid` | UUID v7 ids | built in |
| PostgreSQL 16 | JSONB, window functions, partitioning | project premise |
| Redis 7 | counters, limiter storage, cache, transport | project premise |
| PHPUnit + `symfony/test-pack`, API Platform's `ApiTestCase`, `dama/doctrine-test-bundle`, `zenstruck/foundry` | tests, per-test transactions, factories | standard Symfony testing set; Foundry replaces hand-written fixture builders |
| PHPStan (level 8, `phpstan-symfony`, `phpstan-doctrine`), PHP-CS-Fixer (`@Symfony`, `@Symfony:risky`) | static analysis and style | project premise |
| Docker Compose (php-fpm, nginx, postgres, redis, worker), GitHub Actions | local environment, CI | project premise |

Explicitly not used: RabbitMQ, Elasticsearch, a JS build pipeline (Webpack Encore/Node), a frontend framework, GraphQL (stretch), Sentry or any SaaS.

---

## 6. Non-functional requirements

### 6.1 Performance

- **NFR-PERF-1** Redirect: p95 ≤ 50 ms server time on the local Docker setup with a warm cache, measured with a documented `wrk`/`ab` command in the README; ≤ 1 SQL SELECT and ≤ 1 Redis command per request (FR-RED-2).
- **NFR-PERF-2** Analytics: every per-link report query is bounded by the link's indexes — the planner picks the `(link_id)` index or the composite `(link_id, occurred_at)` one depending on the window (verified with `EXPLAIN` in the analytics change and pasted into its design) — and every report, per-link and global, has p95 ≤ 300 ms uncached on 1 M fixture clicks for a 30-day period.
- **NFR-PERF-3** The worker sustains ≥ 500 clicks/s on the local setup (batch of 10 000 messages drained in ≤ 20 s), demonstrating the redirect is decoupled from persistence.

### 6.2 Security

- **NFR-SEC-1** Secrets (`APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT`, database and Redis credentials) live in `.env.local` or CI variables and are referenced by name only; `.env` holds non-secret defaults. JWT keys are generated locally (`lexik:jwt:generate-keypair`) into a gitignored path.
- **NFR-SEC-2** Passwords hashed with Symfony `auto`; API keys stored as SHA-256; no plaintext credential is ever persisted or logged.
- **NFR-SEC-3** Open-redirect and SSRF classes are addressed by the URL policy (FR-LNK-5) applied to every stored target and by the fact that the server never fetches a target. Residual risk (public hostname resolving to a private address) is documented, not silently accepted.
- **NFR-SEC-4** Untrusted input on the hot path (UA, `Accept-Language`, `Referer`, slug) is length-bounded before processing (UA ≤ 1 KB, `Accept-Language` ≤ 256 B, `Referer` ≤ 2 KB) and never interpolated into SQL or shell.
- **NFR-SEC-5** Security headers on web responses (`X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, a CSP allowing only self-hosted assets); CSRF on all web forms; session cookies `HttpOnly`, `Secure` in prod, `SameSite=Lax`.
- **NFR-SEC-6** Personal data minimization: raw IPs are never stored; `visitor_hash` is salted; a salt rotation invalidates unique-visitor continuity and is documented as such; user agent is discarded after detection.
- **NFR-SEC-7** Every change touching firewalls, voters, API keys, URL validation, rule evaluation, migrations that drop or partition, or Messenger failure handling is `high` tier in the workflow and carries a failing-input test for each new guard (`AGENTS.md`).

### 6.3 Reliability

- **NFR-REL-1** Redirect availability does not depend on the worker, on the Redis transport or on the cache: each failure is caught and degrades (FR-RED-4), covered by tests that stub each dependency as failing. The one deliberate dependency is the click-limit counter: links with `max_clicks` answer 503 while Redis is down rather than exceed their limit (FR-RED-3); unlimited links are unaffected.
- **NFR-REL-2** Click persistence is at-least-once with idempotent handling (FR-CLK-4, FR-CLK-5); nothing is lost while Redis retains the stream; the failed transport is the last resort and is monitored by `messenger:failed:show` in the README runbook.
- **NFR-REL-3** All migrations have a working `down()`, tested by `doctrine:migrations:migrate prev` in CI on the test database.
- **NFR-REL-4** `/health` exists from the first stage and is used by Docker healthchecks and CI smoke tests.

### 6.4 Observability

- **NFR-OBS-1** Monolog JSON lines to stderr in prod and `var/log` in dev; every log line of the redirect and the click handler carries `link_id` and, in the worker, the message id. No IPs or UAs at `info` and above.
- **NFR-OBS-2** The Symfony profiler and API Platform debug pages are enabled only in `dev`.

### 6.5 Code quality and process

- **NFR-QA-1** `declare(strict_types=1)` everywhere, `final` by default, readonly value objects, constructor promotion; PHPStan level 8 clean; PHP-CS-Fixer clean; `make check` is the gate floor and runs in CI on every push and pull request.
- **NFR-QA-2** Architecture rules enforced by tests where cheap: `src/Analytics/` does not reference `Click` entities; entities do not depend on `Doctrine\ORM` beyond mapping attributes; controllers contain no queries (a PHPStan rule or a `deptrac` layer file — deptrac is added only if a violation actually occurs twice, per the process rule on evidence).
- **NFR-QA-3** Every capability in section 2 has an `openspec/specs/<capability>/spec.md` by the time its change is archived; ADRs record the trade-offs listed in section 7 when they are faced.

### 6.6 Testing

Layers as in `AGENTS.md`: `tests/Unit` (no kernel), `tests/Integration` (kernel + PostgreSQL, per-test transactions), `tests/Api` (`ApiTestCase`), `tests/Web` (`WebTestCase` for Twig pages). Always-tested paths:

- slug generation, validation, reserved words, case-sensitive uniqueness;
- URL policy: every rejected class has a test input; every accepted store URL form is a fixture;
- expiry and click limit at redirect (404/410/302 matrix); Redis unavailable → 503 for a limited link, 302 for an unlimited one; the Lua script seeds an absent key from `click_count` exactly once under concurrent first redirects (parallel requests against a real Redis) and never lets the counter pass `max_clicks`;
- rule matching matrix: device × country × language × default, plus skipped-dimension cases and deterministic A/B (same input → same variant; distribution over 10 000 synthetic visitors within ±3 % of weights);
- async dispatch on redirect with the in-memory transport; dispatch failure still returns 302;
- click handler: row shape, `click_count` increment, idempotent redelivery, bot flag; message for a deleted link is discarded without retry and without reaching `failed`;
- analytics: every report against real PostgreSQL fixtures, gap filling, bots toggle, cache hit and invalidation on link update;
- voters: owner vs stranger vs admin for every mutating operation; blocked user refused everywhere;
- API keys: creation returns plaintext once, hashed lookup, expired/revoked → 401, per-key limit → 429; auth endpoints per-IP limit;
- trusted proxy handling of client IP;
- web: login/logout, link create/edit via forms, CSRF failure, dashboard renders with fixtures.

### 6.7 Documentation and delivery

- **NFR-DOC-1** README: screenshots/GIF of the dashboard and Swagger UI, architecture diagram (contexts, write/read paths), `docker compose up` quick start, `make` targets, security notes, "what this shows" list, links to ADRs. Diátaxis layout under `docs/` per `AGENTS.md`.
- **NFR-DOC-2** `docker compose up` on a clean machine yields a working instance with seeded demo data (`app:demo:seed`: two users, ten links with rules, 50 000 synthetic clicks over 60 days) so screenshots and reviewers do not start from an empty dashboard.
- **NFR-DOC-3** GitHub Actions: `make check EXEC=` natively with PostgreSQL and Redis service containers, plus a migration down/up smoke; status badge in the README. Public hosting is stretch (D20).

---

## 7. Stages and changes

The stage plan is the source for `openspec/ROADMAP.md`; ids are stable across both. Each change's `proposal.md` refines scope and exit criteria; the tiers below are the minimum. Order differs from the original brief in one respect: API Platform is installed in the scaffold and link CRUD is built as an API Platform resource from the start, instead of hand-written controllers later rewritten in stage 4. Stage 4 therefore polishes the API surface rather than introducing it.

### Stage 1 — skeleton, accounts, links, synchronous redirect

| # | Change id | Scope | Tier | Exit criterion |
|---|---|---|---|---|
| 1 | `bootstrap-dev-environment` | Docker Compose (php-fpm, nginx, postgres, redis, worker service), php image and nginx config, Makefile targets of `AGENTS.md`, CI workflow parseable with the `php` job self-skipping while `composer.json` is absent | high (CI infrastructure trigger) | `make up` brings postgres and redis healthy and nginx/php running on a clean clone; CI `workflow` job green, `php` job skipped by `detect` |
| 2 | `scaffold-symfony-app` | `symfony/skeleton` 8.1 on PHP 8.4, Doctrine + migrations, API Platform under `/api/v1` with docs, problem-details errors, `/health`, PHPUnit/PHPStan/CS-Fixer wired, bounded-context `src/` layout, `symfony/uid` | medium | `GET /health` and `/api/docs` work; `make check` green with a sample test per layer |
| 3 | `add-users-and-security` | `users`, registration (web + API), form-login firewall for the web, JWT firewall for the API, `app:user:promote/demote`, blocking, role-based authorization boundaries (ownership voters arrive with their resources), auth rate limit | high | matrix test owner/stranger/admin/blocked; auth endpoints 429 after 10/min |
| 4 | `add-link-crud` | `links` entity and migration, slug rules, URL policy, UTM, API Platform resource with voters, `shortUrl`, hard delete; routing `rules` column only — the API accepts rules from `add-routing-rules` | high | FR-LNK-1…11 tests green; every rejected URL class has a failing input |
| 5 | `add-redirect-with-sync-logging` | public `GET /{slug}`, 404/410/302 matrix, UTM append, `clicks` table and a synchronous insert as the baseline, per-IP redirect limit | high (raised from medium by the user) | redirect matrix test; click row written per redirect |

### Stage 2 — smart routing, async click logging

| # | Change id | Scope | Tier | Exit criterion |
|---|---|---|---|---|
| 6 | `add-routing-rules` | JSONB `rules` schema validation, `device-detector`, `CountryResolver` (GeoLite2 + header + test map), matching order, deterministic A/B, `resolved_by`/`variant` on clicks, hostile-input degradation | high | matrix test device × country × language × default; A/B determinism and distribution tests; malformed-UA test |
| 7 | `add-async-click-logging` | `ClickRecorded` on the Redis transport carrying the finished click facts and hash, handler with the row insert and `click_count` in one transaction, idempotency, retries and failed transport, Redis click counter for the limit, dispatch-failure tolerance | high | no SQL write on redirect (asserted); redelivery test; transport-down test still 302; deleted-link message discarded; Redis-down → 503 only for limited links |

### Stage 3 — analytics, QR, API keys

| # | Change id | Scope | Tier | Exit criterion |
|---|---|---|---|---|
| 8 | `add-analytics-read-model` | query services and DTOs for the six reports, `EXPLAIN`-verified indexes, Redis tag cache with invalidation, admin global stats, `app:demo:seed` | medium | report tests against PostgreSQL; cache invalidation test |
| 9 | `add-qr-codes` | `endroid/qr-code`, `GET /api/v1/links/{id}/qr` SVG/PNG | low | snapshot test of SVG; voter test |
| 10 | `add-api-keys-and-rate-limiting` | `api_keys`, hashed lookup authenticator, per-key/per-user API limit, rate-limit headers, trusted-proxy tests, prod deep health probe authorized by an admin API key | high | plaintext-once test; expired/revoked 401; 429 tests |

### Stage 4 — web UI, API polish, quality

| # | Change id | Scope | Tier | Exit criterion |
|---|---|---|---|---|
| 11 | `add-web-ui` | Twig + UX + AssetMapper shell, all pages of FR-WEB-1, forms, rules editor, charts, security headers, CSP | medium | `WebTestCase` suite; screenshots for README produced from seeded data |
| 12 | `polish-api-and-openapi` | OpenAPI descriptions and examples for every operation, filters and ordering, `ApiTestCase` contract tests, error catalogue in `docs/reference/` | medium | OpenAPI validates; contract tests green |
| 13 | `harden-quality-and-docs` | README with screenshots/diagram/benchmarks, PHPStan strictness sweep, migration down/up in CI, architecture tests (NFR-QA-2), ADR index | low | README complete; CI matrix green |

### Stretch (section 9)

| # | Change id | Scope | Tier |
|---|---|---|---|
| 14 | `stretch-partition-clicks` | monthly range partitioning of `clicks`, retention policy and job, ADR | high |
| 15 | `stretch-graphql` | API Platform GraphQL endpoint with the same voters and limits | medium |
| 16 | `stretch-public-hosting` | deploy to a public host with HTTPS, demo instance link in the README | medium |

---

## 8. What each feature teaches (feature → mechanism)

| Feature | Symfony / PostgreSQL mechanism exercised |
|---|---|
| Links CRUD | API Platform resources, state processors/providers, DTO input/output, Doctrine DataMapper with repository interfaces |
| Ownership and admin | Security voters, two firewalls (session + stateless), access-token authenticator |
| Slug and URL rules | Custom Validator constraints, value objects, reserved-word constant shared by tests |
| Routing rules | JSONB column type, schema validation on write, strategy objects, deterministic hashing |
| Redirect | A controller outside API Platform, route ordering, HTTP semantics (302/404/410, cache headers), trusted proxies, an atomic Redis Lua script for the click limit |
| Async clicks | Messenger buses, Redis transport, retry strategy, failed transport, idempotent handlers |
| Analytics | Window functions, `date_trunc`, `generate_series`, read model DTOs, tag-aware cache |
| API keys and limits | Hashing at rest, RateLimiter policies and storage, problem details |
| Web UI | Twig, Forms, CSRF, Turbo/Stimulus, Chart.js via UX, AssetMapper |
| Quality | PHPStan level 8 with Symfony/Doctrine extensions, layered test suites, CI with service containers, reversible migrations |
| Process | OpenSpec changes, risk tiers, independent review gates, ADRs |

---

## 9. Stretch goals

Taken only after stages 1–4 are archived, each as its own change:

- **Monthly partitioning and retention of `clicks`** (ADR on partition key, retention window, effect on unique-visitor counts).
- **GraphQL** through API Platform, reusing voters and rate limits.
- **Public hosting** of a demo instance (HTTPS, seeded data, reset job).
- **`click_daily` aggregate** (materialized view or table refreshed by the worker) if report latency on large fixtures warrants it.
- **Link-page metadata** (title, favicon of the target fetched asynchronously by the worker) — only with an explicit SSRF-safe fetcher design.

---

## 10. Non-goals

Fixed for the portfolio scope; each would be a separate specification change:

- Teams, organizations, shared ownership, link transfer between users.
- Editing a slug after creation; custom domains; vanity domains per user.
- Public statistics pages (`/{slug}+`), public QR endpoint.
- Refresh tokens, OAuth/social login, email verification, password reset, e-mail sending of any kind.
- Per-rule A/B variants, scheduled rules, weighted geo fallbacks, IP allow/deny lists.
- Real-time (WebSocket/Mercure) dashboards; exports (CSV) — the API is the export.
- Browser extensions, mobile apps, a JavaScript SPA.
- Multi-region deployment, click retention policy in the core stages (stretch), GraphQL in the core stages (stretch).
- Any naming of the author's employer or its internal systems anywhere in the repository.

---

## Appendix A — decision traceability (interview of 2026-09-08)

| Decision | Where it is normative |
|---|---|
| D1 language | this document, `AGENTS.md` Language Policy |
| D2 web UI with Swagger and dashboard | §0, §2.9, §7 change 11 |
| D3 JWT + API keys for the API, session for the web | §2.1 FR-AUTH-2/3, §5 |
| D4 individual ownership only | §1, §10 |
| D5 admin via console, admin functions | §1, §2.1 FR-AUTH-5/6, §2.10 |
| D6 slug rules | §2.2 FR-LNK-2/3/4 |
| D7 response codes | §2.4 FR-RED-1 |
| D8 target URL policy | §2.2 FR-LNK-5, §6.2 NFR-SEC-3 |
| D9 UTM appended at redirect | §2.2 FR-LNK-6 |
| D10 device-detector | §2.3 FR-RUL-6, §5 |
| D11 CountryResolver, GeoLite2 + header | §2.3 FR-RUL-6, §5 |
| D12 A/B hash, variant on click | §2.3 FR-RUL-5, §3.4 |
| D13 visitor_hash, no raw IP | §2.5 FR-CLK-2, §6.2 NFR-SEC-6 |
| D14 bots flagged, excluded by default | §2.5 FR-CLK-6, §2.6 FR-ANL-2 |
| D15 report set, no public stats | §2.6 FR-ANL-2, §10 |
| D16 retention is stretch | §3.4, §9, §7 change 14 |
| D17 rate limits | §2.8 FR-KEY-4, §2.1 FR-AUTH-4, §2.4 FR-RED-6 |
| D18 QR SVG/PNG, owner-only | §2.7 FR-QR-1 |
| D19 GraphQL stretch | §4, §9, §7 change 15 |
| D20 Docker + CI in scope, hosting stretch | §6.7 NFR-DOC-3, §9, §7 change 16 |
