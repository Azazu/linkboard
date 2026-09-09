# Design — add-link-crud

## Context

See `proposal.md` — Why. State: users, JWT/session auth, `LinkVoter`-less role boundaries, API Platform DTO resources with providers/processors (admin users), the JSON pagination envelope normalizer, PHPUnit suites Unit/Integration/Api/Web, Foundry factories. Constraints: DataMapper entities, `final` by default, UUID v7 ids, every guard with a failing-input test, no new dependencies without a §5 reason.

## Goals / Non-Goals

**Goals:** the link aggregate exactly as §2.2/§3.3 specify it, the first ownership voter, the URL policy as a reusable constraint, an API surface that the redirect, rules, QR and analytics changes extend without reshaping.
**Non-Goals:** see proposal.

## Decisions

1. **`Link` is a plain entity** (`src/Link/Entity/Link.php`) with named constructor arguments and intention-revealing methods (`changeTarget`, `replaceUtm`, `deactivate`/`activate`, `setExpiry`, `setClickLimit`); no setters for `slug`, `owner`, `clickCount` (the latter is touched only by the click handler later through a dedicated method). `LinkRepositoryInterface` in `src/Link/`, `DoctrineLinkRepository` beside it: `findById`, `findBySlug`, `slugExists`, `add`, `remove`, `findPageForOwner(ownerId, filters, order, offset, limit)`, `countForOwner`, `findPage`/`count` (admin). Filters/order are a small `LinkListQuery` value object, not raw request arrays.
2. **Case-sensitive slug uniqueness at the database**: `slug VARCHAR(32) COLLATE "C"` with a unique index in the migration (PostgreSQL's default collation is case-sensitive already; `"C"` makes it explicit and index-friendly). The `Slug` constraint validator pre-checks `slugExists` for a proper 422; the index is the authority under concurrency and a lost race is mapped to the same 422 by the processor (as registration does).
3. **Slug generation** in `SlugGenerator` (`src/Link/SlugGenerator.php`): `random_int(0, 61)` × 7 over the base62 alphabet; the processor retries up to 5 times on `slugExists`/unique violation, then throws a `RuntimeException` rendered as 500 and logged at `error` with the attempt count. Generated slugs never collide with reserved words (all reserved words contain `-`/`_` or are ≠ 7 chars? No: `dashboard`/`api-keys` differ in length, but `assets`… is 6 — the generator still checks `ReservedSlugs::contains` and retries).
4. **`ReservedSlugs`** is one `final class` with a `LIST` constant and `contains()`; the constraint validator and a unit test read it; the unit test also asserts every top-level route from `debug:router` output that has no `/`-suffix parameter is listed — kept honest by comparing against the router at test time (`RouterInterface::getRouteCollection()`), so a new top-level page cannot silently become a valid slug.
5. **`TargetUrl` constraint + validator** (`src/Link/Validator/`): `parse_url` (reject when it fails or lacks scheme/host), scheme ∈ {http, https}, `strlen ≤ 2048`, host ≠ `localhost`, and for literal IPs (`filter_var($host, FILTER_VALIDATE_IP)` after stripping IPv6 brackets) reject when `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` fails **or** the address falls in an explicit prefix table (`127/8`, `::1`, `169.254/16`, `fe80::/10`, `10/8`, `172.16/12`, `192.168/16`, `fc00::/7`) — the explicit table because `filter_var`'s reserved-range semantics differ across PHP versions and must not be the only oracle. Hostnames are not resolved (specification), documented as residual risk. The constraint is reused by `add-routing-rules` for rule/variant targets.
6. **API resource = DTO `LinkResource`** (`src/Link/Api/`), not the entity: `#[ApiResource(shortName: 'Link')]` with operations `Post('/links', input: CreateLinkInput, processor: CreateLinkProcessor)`, `GetCollection('/links', provider: OwnLinksProvider)`, `Get('/links/{id}', provider: LinkItemProvider, security: is_granted('LINK_VIEW', object))`, `Patch('/links/{id}', input: UpdateLinkInput, provider: LinkItemProvider, processor: UpdateLinkProcessor, security: is_granted('LINK_EDIT', object))`, `Delete('/links/{id}', provider: LinkItemProvider, processor: DeleteLinkProcessor, security: is_granted('LINK_DELETE', object))`, `GetCollection('/admin/links', provider: AllLinksProvider, security: is_granted('ROLE_ADMIN'))`. API Platform evaluates item `security` after the provider ran, with `object` = the provided `LinkResource` (carries `ownerId`), which is what `LinkVoter` votes on. Inputs are validated by API Platform's validator (422 `violations`); `UpdateLinkInput` has every field nullable and the processor applies only the present ones (merge-patch semantics via `deserialize` into a fresh object — absent keys stay null, and an explicit `null` for `expiresAt`/`maxClicks`/`utm` clears the value; `isActive` null = unchanged).
7. **Slug immutability**: `UpdateLinkInput` has a nullable `slug` property with a custom `#[SlugUnchanged]`-free approach: the processor compares `slug` (if present) with the current one and throws API Platform's `ValidationException` on `slug` when different — simpler than a context-aware constraint and exactly the FR-LNK-4 rule.
8. **`LinkVoter`** (`src/Link/Security/LinkVoter.php`) supports `LINK_VIEW|LINK_EDIT|LINK_DELETE` on `LinkResource`: granted when `subject->ownerId === user id` or the user has `ROLE_ADMIN`. `AGENTS.md` says voters apply identically to web controllers later; the web UI change reuses it with the same attributes.
9. **Filters and ordering by hand** in `OwnLinksProvider`/`AllLinksProvider` from query parameters (`isActive` boolean, `slug` substring — `LIKE` with escaped `%`/`_`, `order[createdAt|clickCount]` ∈ {asc, desc}; anything else → 400 problem details), declared as OpenAPI `parameters` on the operations so Swagger shows them. Rejected alternative: API Platform Doctrine filters — they need the entity as the resource.
10. **Migration**: `links` per §3.3 with `CONSTRAINT links_max_clicks_positive CHECK (max_clicks > 0)`, FK `owner_id → users(id) ON DELETE CASCADE`, unique index on `slug` (collation "C"), index `(owner_id, created_at DESC)`; reversible `down()`.
11. **Tests as listed in the proposal**; the URL policy has one parametrized unit test per rejected class and per accepted form, and the same list is replayed over HTTP in the Api suite so validator wiring is proven, not assumed.

## Applicability (high tier)

| Question | Applies? | Note |
|---|---|---|
| Crash before/after an external effect | yes | create/update/delete are single-transaction writes; no external effect beyond PostgreSQL in this change (Redis/cache hooks arrive later and must be after-flush) |
| Concurrent writers | yes | two creates with the same custom slug: the unique index is the authority, the loser gets 422 (processor maps the violation); generated-slug collision retried up to 5 times; concurrent PATCHes last-write-wins on distinct fields (accepted; no optimistic locking in scope, stated in Non-goals of the redirect/rules changes if needed) |
| Money rounding | n/a | |
| Empty/zero/null inputs | yes | empty `targetUrl` → 422; `maxClicks` 0 → 422; `utm` `{}` → stored as null; explicit `null` in PATCH clears `expiresAt`/`maxClicks`/`utm`; unknown filter values → 400 |
| Authorization boundary | yes | `LinkVoter` matrix owner/stranger/admin/anonymous on GET/PATCH/DELETE; `/api/v1/admin/links` ROLE_ADMIN via `access_control` and operation security; collection provider scopes by the authenticated user id, never by a client-supplied owner |
| Deletion/expiry | yes | hard delete 204, slug reusable; dependents will cascade via the FK contract; `expiresAt` validated in the future on write, enforced at redirect (later) |
| Idempotency of retries | yes | repeating a DELETE → 404 (row gone); repeating a PATCH with the same body → same state; POST is not idempotent by design (a new generated slug each time) |

## Risks / Trade-offs

- [`filter_var` range semantics differ across PHP versions] → explicit prefix table is the primary check, tested per range.
- [A public hostname may resolve to a private address (DNS rebinding)] → out of scope by specification (no resolution at validation); README/how-to security note; the redirect never fetches targets.
- [Merge-patch with a DTO: distinguishing "absent" from "null"] → nullable properties + `isset`-style tracking via a small `PresentFields` trait fed by a denormalizer context is over-engineering; decision 6 takes the simpler contract: `null` clears, absence keeps, and `isActive` null means unchanged. Documented in the OpenAPI description of the PATCH.
- [Reserved list drifts from routes] → test compares against the router (decision 4).

## Migration Plan

Additive migration; `make migrate` locally, CI applies it through `make test-db`. Rollback: revert; `down()` drops the table.
