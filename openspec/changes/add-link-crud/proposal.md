# Proposal — add-link-crud

**Risk-Tier:** high

Tier rationale: security-sensitive input handling (target URL policy = open-redirect and SSRF classes, `AGENTS.md` project triggers), hard deletion, authorization voters. Gate 1 + Gate 2, a demonstrated failing input for every guard, a green branch run before Gate 2 (auto review mode).

## Why

Accounts exist; the product does not. Links are the core aggregate of Linkboard: everything after this change (redirect, routing rules, clicks, analytics, QR, web UI) hangs off `links`. The specification fixes the model and every rule (§2.2 FR-LNK-1…11, §3.3, §4, decisions D6, D8, D9), so this change turns them into the first owned resource of the API and the first ownership voter.

## What Changes

1. **`links` table and `Link` entity** (`src/Link/`): UUID v7 id, `owner_id` FK → users `ON DELETE CASCADE`, `slug` varchar(32) unique with the case-sensitive `"C"` collation, `target_url` text, `rules` jsonb null (column only — see Non-goals), `utm` jsonb null, `expires_at`, `max_clicks` (check > 0), `click_count` default 0, `is_active` default true, timestamps; index `(owner_id, created_at desc)`. Reviewed, reversible migration. Repository interface + Doctrine implementation.
2. **Slug rules (D6)**: generated slugs 7 chars base62 from `random_int`, up to 5 candidates pre-checked against the database before the single persist, then 500 with a logged error (a failed Doctrine commit closes the EntityManager, so retrying after a flush is not an option — design decision 3); custom slugs `^[A-Za-z0-9_-]{3,32}$`, case-sensitive uniqueness (`abc` and `ABC` coexist), a single `ReservedSlugs` constant listing every top-level web/API route (`api`, `admin`, `login`, `logout`, `register`, `dashboard`, `links`, `api-keys`, `health`, `docs`, `qr`, `assets`, `build`, `bundles`, `_profiler`, `_wdt`, `_error`); the slug is immutable (a PATCH carrying a different slug → 422).
3. **Target URL policy (D8)** as a reusable validator (`src/Link/Validator/TargetUrl`): absolute URL, `http`/`https` only, host present, host not `localhost`, literal IPs not in loopback, link-local or private ranges (v4 and v6), length ≤ 2048, no DNS resolution; the residual risk (public hostname resolving privately) is written into the how-to security notes. The same constraint will validate rule/variant targets in `add-routing-rules`.
4. **UTM (D9)** stored as a JSON object with at most the five allowed keys, each ≤ 255 chars; unknown keys → 422. Appending at redirect time is the redirect change's job.
5. **`expires_at` / `max_clicks`** validated on write (future timestamp; positive integer), optional and independent; enforcement at redirect time is the redirect change's job.
6. **API Platform resource `LinkResource`** (DTO, providers/processors — same style as the admin resource): `POST /api/v1/links`, `GET /api/v1/links` (own links only; filters `isActive`, `slug` substring; `order[createdAt|clickCount]`; pagination envelope 30/max 100), `GET|PATCH|DELETE /api/v1/links/{id}` (merge-patch of `targetUrl`, `expiresAt`, `maxClicks`, `utm`, `isActive`), plus `GET /api/v1/admin/links` (all links, `ROLE_ADMIN`). PATCH updates only the fields present in the body (presence from the decoded request body): `expiresAt`/`maxClicks`/`utm` may be cleared with `null`, `targetUrl` and `isActive` may not be `null`. Every response carries `shortUrl` (from `APP_PUBLIC_URL`) and `clickCount`.
7. **Ownership voter `LinkVoter`** (`LINK_VIEW`, `LINK_EDIT`, `LINK_DELETE`): owner or admin; stranger → 403 problem details; anonymous → 401. FR-ADM-2's "deactivate/delete any link" is the admin branch of the same voter, and every admin mutation of another user's link writes one `audit` info record (`link.update|deactivate|activate|delete`, actor id, link id, owner id) after the flush — same channel and handler as user block/unblock.
8. **Hard delete**: `DELETE` → 204; the row is gone and the slug is reusable immediately; the clicks cascade, Redis counter and cache invalidation are wired when those exist (redirect, async-clicks, analytics changes) — this change adds the FK cascade contract to the design so those changes attach to it.
9. **Tests**: unit (slug generator, every rejected URL class and accepted store URL forms, UTM/expiry/limit constraints, reserved list), integration (repository, migration up/down, case-sensitive slug uniqueness at the database), API (create with generated and custom slug, reserved/duplicate/invalid slug, every URL-policy rejection over HTTP, UTM shape, past expiry, zero limit, slug immutability, PATCH fields, list own-only with filters/order/pagination envelope, owner/stranger/admin/anonymous matrix on every item operation, delete then slug reuse, admin list).
10. **Docs**: how-to (create a link with curl, security notes on the URL policy), commands unchanged; roadmap row 4 wording.

## Capabilities

### New Capabilities

- `links`: the link aggregate — creation, slug rules, target URL policy, UTM, expiry and limit fields, listing, update, deletion, ownership and admin access.

### Modified Capabilities

None. (`api-error-format` already carries the 422 `violations` shape; `user-administration` is untouched — admin link operations are specified in `links`.)

## Non-goals

- Routing `rules`: the JSONB column is created (nullable) so the redirect change can read it later, but the API neither accepts nor returns `rules` until `add-routing-rules` defines the schema validation (FR-RUL-1…7).
- Redirect, click counting, UTM appending, expiry/limit enforcement at redirect time (`add-redirect-with-sync-logging`); Redis counter and report cache invalidation on delete (later changes).
- QR (`add-qr-codes`), analytics (`add-analytics-read-model`), web pages for links (`add-web-ui`).
- Bulk operations, import/export, link transfer between users, soft delete.

## Impact

- New: `src/Link/**` (entity, repository interface + Doctrine repository, slug generator, reserved slugs, validators, API resource + providers/processors + input DTOs, voter), `migrations/Version*.php` (links), `tests/**`, `docs/how-to` additions.
- Modified: `config/services.yaml` (repository binding), `config/packages/security.yaml` only if a new access_control line is needed (`^/api/v1/admin/` already covers the admin listing), `docs/explanation/requirements.md` §7 row 4 / `openspec/ROADMAP.md` row 4 wording if the scope note about `rules` needs recording.
- No new dependencies: validation with `symfony/validator`, IP range checks with `filter_var` flags plus explicit prefix checks, random slugs with `random_int`. `APP_PUBLIC_URL` already exists in `.env`.
