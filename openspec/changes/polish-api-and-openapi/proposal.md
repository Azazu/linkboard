## Why

The API is complete and the document that describes it is not. `GET /api/docs.json` lists 25 operations, every one with a description — and not one of them documents that it can answer 401, 429, 409 or 406, nor carries a single example. Every error response claims to produce `application/json` beside `application/problem+json`, which the API never sends. An integrator reading the document is told less than the code does, and in two places is told something untrue.

There is no published catalogue of what an error means either: the `type` member is `/errors/<status>`, a URI that resolves to nothing and is explained nowhere.

This is row 12 of the roadmap, whose exit criterion is that the OpenAPI document validates and the contract tests are green.

**Risk-Tier:** high

Raised from `medium` on 2026-09-14 (see "User decisions"). The first version of this proposal claimed the change touches neither authentication nor the rate limiter, and then the implementation moved the firewall's public-path patterns, the `json_login` check path and the limiter's exclusion rule into shared parameters. AGENTS.md makes a change touching the security firewall `high` **even when it is intended to preserve behaviour** — the intention is exactly what a review is for. Gate 1 on these artifacts and Gate 2 on the diff, with a demonstrated failing input for every boundary the change touches.

What is security-sensitive here is narrow and worth naming precisely: no rule is rewritten, and no request is decided differently. The firewall's access-control patterns, its check path and the limiter's exemption become references to one definition, so that the document generated from them cannot describe a policy the runtime does not apply. The risk is a transcription error — a pattern that resolves to something other than what it replaced — which is what the evidence must rule out.

## What Changes

- **Every operation documents the statuses it can actually answer.** Today's document carries 200/201/204/400/403/404/422 and nothing else. Missing and added by rule: **401** on every operation behind a firewall, **429** on every operation the rate limiter covers (with its `Retry-After`, `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers), **406** where content negotiation can refuse, and **409** on the one operation that answers it — creating an API key at the cap.
- **Errors are documented as what they are.** Every error response's content becomes `application/problem+json` alone, with the RFC 9457 schema and an example, instead of today's duplicate `application/json` entry that the API never produces.
- **Examples on every operation**, declared once per field rather than once per operation: representative values on the resources' and input DTOs' properties, so request bodies and responses in Swagger UI arrive filled in and the examples cannot drift from the schema they belong to.
- **An error catalogue** at `docs/reference/api-errors.md`: every `type` the API can produce, what it means, which operations raise it, and what a client should do about it — with a test that fails when the code can produce a type the catalogue does not list.
- **The refusals the first round missed.** Gate 2 round 1 found three the rules did not model: an unsupported request media type answers **415** and nothing documented it; the token operation inherits only 200 from the JWT bundle, hiding its real 400 and 403, and was given a 406 it cannot answer because `json_login` replies before content negotiation; and that operation's inline request and response schemas carry neither examples nor the `expiresAt` the success response actually sends. Each is corrected against the authentication path as it really runs.
- **Contract tests** (`tests/Api/Contract/`): for a representative request per operation, the status the API actually returns is one the document declares for that operation, and the media type matches what the document says. The document stops being decoration and becomes a checked claim.
- **Filters and ordering** are already declared as parameters on both link collections (`isActive`, `slug`, `order[createdAt]`, `order[clickCount]`, `page`, `itemsPerPage`); this change verifies them against behaviour in the contract tests and documents the analytics report parameters the same way, rather than adding a filtering mechanism that does not exist.
- **The firewall's path policy and the document's become one definition.** The public-path patterns of `access_control`, the `json_login` check path, the route of the token endpoint, the API limiter's exemption and the per-IP limiter's guarded rule — including its method restriction — are declared once and referenced by every reader, so the document cannot claim a policy the runtime does not apply. Every resolved value is identical to the literal it replaces, which the evidence has to show.
- No new dependency, no migration, no change to any route, payload or status code the API answers.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `api-docs`: new requirements stating that every operation documents each status it can answer, that error responses are documented as problem details only, that operations carry examples, and that the rate-limited operations document their headers.
- `api-error-format`: a new requirement that the error types the API produces are published and kept in step with the code.

## User decisions

- **2026-09-14 — tier raised to `high`** (Gate 2 round 1, finding 1): the reviewer pointed out that the diff changes access-control configuration, the `json_login` check path and the limiter's exclusion mechanism while the proposal claimed authentication was untouched, and that AGENTS.md makes such a change `high` regardless of intent. Offered the choice between restoring a documentation-only scope with an equivalence test, raising the tier, or splitting the parameterization into its own change, the user chose to raise the tier and request Gate 1 — keeping the stronger construction and paying for it with the review it requires.

## Impact

- **New code**: an OpenAPI factory decorator in `src/Shared/Api/` that adds the common error responses by rule; `tests/Api/Contract/` with the document-versus-behaviour tests; `docs/reference/api-errors.md`.
- **Security-sensitive changes**: `config/packages/security.yaml` (three access-control patterns and the `json_login` check path become parameter references), `config/routes.yaml` (the token route's path), `src/Auth/RateLimit/ApiRateLimitListener.php` (its exemption constant becomes an injected list) and `src/Auth/Security/AuthRateLimitSubscriber.php` (its API rule, method included, becomes an injected list). Every resolved value stays what it was.
- **Changed code**: `ApiProperty` examples on `src/Link/Api/LinkResource.php`, `src/Auth/Api/**` and `src/Analytics/Api/**` resources and their input DTOs; the one operation that answers 409 declaring it.
- **Untouched on purpose**: every state provider and processor, every voter, the limiters' policies and windows, the error normalizer's behaviour, and the existing `tests/Api/**` suites — which are what proves the responses the document now claims. The limiters' *paths* move to shared parameters (above) without changing which requests they cover.
- **Dependencies**: none added. The document is generated by API Platform, which is already here; validation uses its own output plus the contract tests rather than a third-party validator.

## Non-goals

- Changing any status code, payload, header or route the API answers. Where the document and the code disagree, the document is what gets corrected.
- Documenting the web UI's pages: they are not an API surface and have their own capability.
- A published JSON Schema for the routing-rules document beyond the one already in `docs/reference/rules-schema.json`, or resolving `/errors/<status>` to a live URL.
- New filters, new orderings or new query parameters: this change documents and tests what exists.
- API versioning, deprecation policy or a changelog of the contract (a stretch concern, not row 12).
