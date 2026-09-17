# Tasks — stretch-graphql

Tier `high`: a new dependency, a second entry point to the same authorization,
and a query language whose cost a caller chooses — the denial-of-service shape
the roadmap's `medium` does not cover. Gate 1 on the artifacts before any code,
Gate 2 on the diff, and a demonstrated failing input for every new guard.

No REST operation changes. `tests/Api` — the contract tests, the error
catalogue, the OpenAPI document tests — must pass untouched; if one of them
changes, the refactor of section 3 is wrong.

## 1. Gate 1

- [ ] 1.1 Request Gate 1 on the artifacts (`scripts/gate-run.sh stretch-graphql 1 full`) and disposition every finding before section 2 starts. Verify: the last Gate 1 record in `review.md` reads `approved` or `confirmed` with no finding row left `open`.

## 2. The dependency and the endpoint

- [ ] 2.1 `composer require api-platform/graphql` (or whatever the installed API Platform names its GraphQL package — checked against `composer.json` before the command is run, not assumed), pinned like every other dependency. Verify: `composer show` lists it and `webonyx/graphql-php` beneath it; `make check` still green; the lock file committed.
- [ ] 2.2 `config/packages/api_platform.yaml`: `graphql.enabled: true`, `introspection.enabled: true`, `graphiql.enabled: false`, `graphql_playground.enabled: false`, `max_query_depth: 10`, `max_query_complexity: 200`, each with the reason as a comment (design decision 4). Verify: `bin/console debug:config api_platform graphql` prints exactly those values.
- [ ] 2.3 `config/routes.yaml` declares `api_v1_graphql` at `/api/v1/graphql` on `api_platform.graphql.action.entrypoint`, mirroring the `api_v1_docs` route this repository already declares for the documentation controller (design decision 3a; Gate 1 round 1, finding 2 — the framework's own route is `/graphql` under the `/api` import prefix, so enabling the flag publishes `/api/graphql`, not the versioned path). Verify: `bin/console debug:router | rg graphql` lists both routes, and a test posts a valid query to each and asserts the same data.
- [ ] 2.4 The firewall and the rate limiter cover **both** paths. Verify: `config/packages/security.yaml` matches them with the existing `api` firewall — no new firewall, no new authenticator — and a test asserts an anonymous POST to each is refused 401 with a problem-details body, which is the shape decision 5 assigns to a refusal decided before the executor.

## 3. Report parameters stop being HTTP-shaped

- [ ] 3.1 `ReportRequestFactory::parse()` takes an `array<string, string|null>` instead of a `?Request`; the REST providers pass `$request->query->all()` (design decision 2). Verify: `rg -n 'HttpFoundation' src/Analytics/` shows the factory no longer imports `Request` or `InputBag`.
- [ ] 3.2 **The REST behaviour is unchanged.** Verify: `tests/Api/Analytics` and `tests/Web/Stats` pass with **no edit to either suite** — `git diff main --stat -- tests/Api/Analytics tests/Web/Stats` is empty at Gate 2. That is the whole guard on this refactor.
- [ ] 3.3 The statistics page still parses the same way. Verify: `src/Web/Stats/` passes its parameters through the same call and its tests pass untouched.
- [ ] 3.4 A report resolved through GraphQL uses the parameters it was given. Verify, with a demonstrated failing input: a GraphQL query for a report with an explicit period returns that period's figures, and the test fails with the default-period figures when the arguments are not passed through — which is what the code does today.

## 4. The surface

- [ ] 4.1 `graphQlOperations` declared on every exposed class — `LinkResource` (item + collection query), the nine report classes (item query) and `Me` (item query) — listing **queries only**, because the default set API Platform would otherwise apply carries `create`, `update` and `delete` mutations (design decision 1). Verify: the schema contains a query per declared operation and no mutation.
- [ ] 4.2 The three excluded classes declare `graphQlOperations: []` — `UserAdmin`, `Registration`, `ApiKeyOutput` (Gate 1 round 1, finding 1: leaving a resource undeclared **exposes** it with mutations, read from `OperationDefaultsTrait::addDefaultGraphQlOperations()`). Verify: `rg -n 'graphQlOperations' src/` shows a declaration on all 14 resource classes — 11 with queries, 3 empty — and the count matches the inventory.
- [ ] 4.3 The schema contains nothing else, and no mutation at all. Verify: a test introspects the shipped schema and asserts the set of query names **equals** a written-down list, so a new type fails it rather than joining it; that the mutation type is absent; and that no type name derives from user administration, registration or API keys.
- [ ] 4.4 The exclusion cannot lapse silently, in the direction that actually bites. Verify, with a demonstrated failing input, executed and recorded: **removing** `graphQlOperations: []` from `UserAdmin` — the state the change started from — makes task 4.3's test fail naming the type **and** the mutations that appeared with it; restored, it passes again.

## 5. Authorization carries over

- [ ] 5.1 A stranger reads nothing, and learns nothing. Verify: a test queries another user's link and asserts `data.link` is null with an error, and that the error is the same whether the link exists or not (the property ADR-005 established for the pages).
- [ ] 5.2 A stranger's reports are refused. Verify: a test queries each of the six per-link reports for someone else's link and asserts no report data.
- [ ] 5.3 The global reports still require an administrator. Verify: a test queries the three global reports as a plain user and asserts no data and an error.
- [ ] 5.4 An anonymous caller reads nothing. Verify: a test posts a valid query with no credential and asserts an error and no data.
- [ ] 5.5 The demonstrated failing input for the boundary: removing `security` from one exposed operation makes 5.1 or 5.3 fail — executed and recorded, then restored.

## 6. The cost model

- [ ] 6.1 A GraphQL request consumes one token per **root selection** of the executed operation, refusing the whole request when the budget cannot cover it, before any field is resolved (design decision 3). Verify: a test with a three-root-selection document asserts three tokens consumed, and a test with one token left asserts a refusal and that nothing was resolved.
- [ ] 6.2 The count is taken from the parsed document, by the algorithm decision 3 states. Verify, one case each: a root fragment spread contributes the selections it names; two aliases of one field cost two; `@skip(if: true)` does **not** reduce the cost; a document of only `__schema` and `__type` costs one. These are the bypasses a hand-written counter invites, and each is a test rather than a claim (Gate 1 round 1, finding 4).
- [ ] 6.3 A request that cannot be priced is refused without a token and without resolution: a body that is not a JSON object, a `query` that is not a string, a `variables` that is not an object, a document that does not parse, a document with no operation, and a document with two operations and no `operationName`. Verify: six cases, each asserting the refusal, that no token was consumed and that nothing resolved.
- [ ] 6.4 REST is unaffected. Verify: `tests/Api/Auth/ApiRateLimitTest` passes untouched — one token per REST request, as before.
- [ ] 6.5 The demonstrated failing inputs, executed and recorded: with the per-selection charge removed, a ten-root-selection document costs one token and the test says so; with fragment resolution removed, the fragment case costs one instead of two.
- [ ] 6.6 The depth and complexity ceilings refuse what they are for. Verify: a test posts a document nested past the depth limit and one past the complexity limit, asserting each is refused with an error naming the limit and no data resolved — and that these two are **GraphQL-shaped** refusals, unlike the ones above.

## 7. Errors

- [ ] 7.1 The boundary of decision 5 holds in both directions. Verify: a test asserts a voter refusal, a refused report parameter and an exceeded limit each answer **200 with `errors`**; and that a missing credential, an exhausted budget and an unpriceable body each answer **their own status with `application/problem+json`** — the shapes the firewall and the rate limiter already produce for REST, not duplicated for this endpoint.
- [ ] 7.2 The REST contract is untouched. Verify: `tests/Api/ErrorCatalogueTest` and `tests/Api/Contract` pass with no edit — `git diff main --stat -- tests/Api/ErrorCatalogueTest.php tests/Api/Contract` is empty at Gate 2.
- [ ] 7.3 An internal failure leaks nothing outside `dev`. Verify, with a demonstrated failing input: a resolver made to throw produces an error message carrying no class name, no SQL and no stack frame in the `test` environment; the same in `dev` may carry detail, which is the documented difference.
- [ ] 7.4 Two protocols share one cached report. Verify: a test requests a report through REST and then through GraphQL with the same parameters and asserts the second is served from the cache — the same `generatedAt` and no new query against `clicks`.

## 8. Documents

- [ ] 8.1 `docs/explanation/requirements.md` §4 no longer says GraphQL is disabled; it says what is exposed, read-only, at which path, and §9's stretch list records the item as done. Verify: the file re-read whole around both edits; `rg -n 'GraphQL (is |are )?(disabled|stretch)' docs/` returns nothing stale.
- [ ] 8.2 `docs/reference/api-errors.md` carries the table of decision 5: which refusal is decided where, and therefore in which shape and with which status. Verify: the file re-read whole after the edit; every row of the table has a test behind it in section 7.
- [ ] 8.3 A how-to for the endpoint: both paths and which is documented, a worked query with a credential, the cost model in one sentence with the counting rule, the depth and complexity limits, the two error shapes, and what is deliberately absent. Verify: every command and query in it run in its exact form against the local stack.
- [ ] 8.4 The README names the second protocol where it names the first. Verify: the README re-read whole after the edit; every link resolves.

## 9. Wrap-up

- [ ] 9.1 `make check` green inside the container with no environment override; `openspec validate stretch-graphql --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated with the schema's query list and the recorded failing inputs.
- [ ] 9.2 Green Actions run on the exact branch head: the user pushes; the executor queries `https://api.github.com/repos/Azazu/linkboard/actions/runs?branch=change/stretch-graphql` until the run for `git rev-parse HEAD` is `completed` / `success`; URL and SHA recorded in `handoff.md`. Verify: all four jobs green.

## After every task above is complete — the gate, not a task

Gate 2 is requested once section 9 is done, and it is deliberately not a
checkbox: `scripts/gate-run.sh` runs `scripts/pregate-verify.sh` first, and that
floor rejects any unchecked task. The lifecycle steps are:

1. `scripts/gate-run.sh stretch-graphql 2 full`.
2. Fix every finding, update its Status in `review.md`, and re-review with
   `scripts/gate-run.sh stretch-graphql 2 confirm <round>`.
3. The gate has passed when the last Gate 2 record reads `approved` or
   `confirmed` with no finding row left `open`; `scripts/workflow-verify.sh
   merge stretch-graphql` is what checks that before the merge.
4. After the user merges: `scripts/workflow-verify.sh archive stretch-graphql`,
   then the archive commit on `main`, which syncs the two capability deltas and
   removes roadmap row 15.
