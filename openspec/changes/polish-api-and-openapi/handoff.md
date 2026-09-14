# Handoff — polish-api-and-openapi

**Updated:** 2026-09-14 · claude
**State:** proposing
**Branch:** change/polish-api-and-openapi

## Done this session
- Branch `change/polish-api-and-openapi` created from `main` (`76c8c20`, after the archive of `add-web-admin-and-stats`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 12 and §7 of `docs/explanation/requirements.md`: OpenAPI descriptions and examples for every operation, filters and ordering, `ApiTestCase` contract tests, and an error catalogue in `docs/reference/`. Tier `medium` as the roadmap declares it — the row's own minimum; the proposal states the tier and its reasoning, and raises it if the scope turns out to touch a security boundary.
- Exit criterion the roadmap records: the OpenAPI document validates and the contract tests are green.

- Artifacts written and `openspec validate --strict` passes: proposal (tier `medium` with the reasoning for it and for when it would be raised, non-goals), two deltas — `api-docs` (every operation declares the statuses it can answer; errors documented as problem details alone; examples on every operation; the rate-limited operations document their headers) and `api-error-format` (the error types are published and kept in step) — design (the decorator, the configuration it reads, examples per property, what the contract tests do and do not prove, the catalogue's completeness as a test), tasks (the document's truthfulness, examples, contract tests, the catalogue, wrap-up).

**Measured on `main` before proposing, not assumed:** the document has 25 operations, every one already described; the statuses present are 200/201/204/400/403/404/422; **401, 406, 409 and 429 appear nowhere**; every error response lists `application/json` beside `application/problem+json`, which the API never sends; no operation carries an example; and the collections' filters and ordering are already declared as parameters, so that part of row 12 is verification, not construction.

**Design choices worth the reviewer's attention:** (1) the common error responses come from one `OpenApiFactoryInterface` decorator reading the firewall and limiter patterns from configuration, rather than a hundred hand-written attributes that drift the first time a firewall changes; (2) examples live on the property they illustrate, so they are constrained by the same schema; (3) the contract tests compare the document with real responses rather than with itself, and the design states what that sampling cannot prove.

## Next step
`/opsx:apply polish-api-and-openapi` — implement in the task order. Tier `medium`: no Gate 1; Gate 2 on the code diff before merge.

## Blockers
None.
