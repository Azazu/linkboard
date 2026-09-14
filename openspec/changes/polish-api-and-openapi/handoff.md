# Handoff — polish-api-and-openapi

**Updated:** 2026-09-14 · claude
**State:** awaiting-gate-2
**Branch:** change/polish-api-and-openapi

## Done this session
- Branch `change/polish-api-and-openapi` created from `main` (`76c8c20`, after the archive of `add-web-admin-and-stats`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 12 and §7 of `docs/explanation/requirements.md`: OpenAPI descriptions and examples for every operation, filters and ordering, `ApiTestCase` contract tests, and an error catalogue in `docs/reference/`. Tier `medium` as the roadmap declares it — the row's own minimum; the proposal states the tier and its reasoning, and raises it if the scope turns out to touch a security boundary.
- Exit criterion the roadmap records: the OpenAPI document validates and the contract tests are green.

- Artifacts written and `openspec validate --strict` passes: proposal (tier `medium` with the reasoning for it and for when it would be raised, non-goals), two deltas — `api-docs` (every operation declares the statuses it can answer; errors documented as problem details alone; examples on every operation; the rate-limited operations document their headers) and `api-error-format` (the error types are published and kept in step) — design (the decorator, the configuration it reads, examples per property, what the contract tests do and do not prove, the catalogue's completeness as a test), tasks (the document's truthfulness, examples, contract tests, the catalogue, wrap-up).

**Measured on `main` before proposing, not assumed:** the document has 25 operations, every one already described; the statuses present are 200/201/204/400/403/404/422; **401, 406, 409 and 429 appear nowhere**; every error response lists `application/json` beside `application/problem+json`, which the API never sends; no operation carries an example; and the collections' filters and ordering are already declared as parameters, so that part of row 12 is verification, not construction.

**Design choices worth the reviewer's attention:** (1) the common error responses come from one `OpenApiFactoryInterface` decorator reading the firewall and limiter patterns from configuration, rather than a hundred hand-written attributes that drift the first time a firewall changes; (2) examples live on the property they illustrate, so they are constrained by the same schema; (3) the contract tests compare the document with real responses rather than with itself, and the design states what that sampling cannot prove.

- Implementation (tasks 1.1–4.2), `make check` green (829 tests, 10732 assertions):
  - **`CommonErrorResponses`**, one `OpenApiFactoryInterface` decorator, adds 401 where a firewall guards the path or the operation authenticates, 429 where either limiter covers it, and 406 everywhere, and narrows every response of 400 or higher to `application/problem+json` with the RFC 9457 schema and an example. Measured after: 25 operations, 401 on 24 (registration takes no credential), 429 on 25 with `Retry-After`, 409 on the one operation that answers it. It is the outermost decorator (priority −100) so it also sees the operation the JWT bundle's factory adds.
  - **The path rules live once** in `config/services.yaml`; `security.yaml`'s access control, its `json_login` check path, `config/routes.yaml`, `ApiRateLimitListener` and the decorator all read them.
  - **Examples** on every property of every resource and input DTO; API Platform's own `Error` and `ConstraintViolation` schemas carry none and a test asserts nothing references them any more.
  - **`tests/Api/Contract/`**: a case per documented operation plus the refusals, asserting the observed status is declared for that operation and the media type matches; the filters and ordering assert what they select; the 429 case drives the limiter rather than being skipped.
  - **`docs/reference/api-errors.md`** with a completeness test.

**Worth the reviewer's attention.** The example type-check caught a real documentation defect: `rules` on both link inputs is `mixed` in PHP, so the generated schema said `string|null` for a field that takes a JSON object — the document now says object, like the resource's own. And the rate-limit header rule had to be split: both limiters name a delay when they refuse, but only the per-identity one reports the remaining allowance, so the auth endpoints document `Retry-After` alone.

**What the contract tests do not prove:** they are a sample. They cannot show the API never answers an undocumented status, only that the answers they asked for are documented.

**Demonstrated failing input recorded:** removing the `/errors/409` row from the catalogue fails `ErrorCatalogueTest` naming `/errors/409 (declared by an operation)`; restoring it passes.

- Branch run on the exact head (`c7765a6`) is green: run 34879393689, `make check EXEC=` native in CI (2026-09-14). Task 5.2 ticked; 5.3 is ticked as the gate is requested, which is what the floor requires.

## Next step
`scripts/gate-run.sh polish-api-and-openapi 2 full`, then fix and confirm every finding.

## Blockers
None.
