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
- [ ] 2.3 The endpoint answers at `/api/v1/graphql` and nowhere else. Verify: a test posts a valid query there and gets 200, and posts the same to `/api/graphql` and gets 404.
- [ ] 2.4 The firewall covers it. Verify: `config/packages/security.yaml` matches the path with the existing `api` firewall — no new firewall, no new authenticator — and a test asserts an anonymous POST carries an error and no data.

## 3. Report parameters stop being HTTP-shaped

- [ ] 3.1 `ReportRequestFactory::parse()` takes an `array<string, string|null>` instead of a `?Request`; the REST providers pass `$request->query->all()` (design decision 2). Verify: `rg -n 'HttpFoundation' src/Analytics/` shows the factory no longer imports `Request` or `InputBag`.
- [ ] 3.2 **The REST behaviour is unchanged.** Verify: `tests/Api/Analytics` and `tests/Web/Stats` pass with **no edit to either suite** — `git diff main --stat -- tests/Api/Analytics tests/Web/Stats` is empty at Gate 2. That is the whole guard on this refactor.
- [ ] 3.3 The statistics page still parses the same way. Verify: `src/Web/Stats/` passes its parameters through the same call and its tests pass untouched.
- [ ] 3.4 A report resolved through GraphQL uses the parameters it was given. Verify, with a demonstrated failing input: a GraphQL query for a report with an explicit period returns that period's figures, and the test fails with the default-period figures when the arguments are not passed through — which is what the code does today.

## 4. The surface

- [ ] 4.1 `graphQlOperations` declared on `LinkResource` (item + collection), on the nine report classes (item) and on `Me` (item); nothing declared on `UserAdmin`, `Registration` or `ApiKeyOutput` (design decision 1). Verify: the schema contains a query per declared operation.
- [ ] 4.2 The schema contains nothing else. Verify: a test introspects the shipped schema and asserts the set of query names **equals** a written-down list, so a new type fails it rather than joining it; and asserts no type name derives from user administration, registration or API keys.
- [ ] 4.3 No mutation exists. Verify: the test asserts the schema declares no mutation type, and a document containing a mutation is rejected.
- [ ] 4.4 The exclusion cannot lapse silently. Verify, with a demonstrated failing input: a temporary `graphQlOperations` on `UserAdmin` makes task 4.2's test fail, naming the type; removed, it passes again — executed and recorded.

## 5. Authorization carries over

- [ ] 5.1 A stranger reads nothing, and learns nothing. Verify: a test queries another user's link and asserts `data.link` is null with an error, and that the error is the same whether the link exists or not (the property ADR-005 established for the pages).
- [ ] 5.2 A stranger's reports are refused. Verify: a test queries each of the six per-link reports for someone else's link and asserts no report data.
- [ ] 5.3 The global reports still require an administrator. Verify: a test queries the three global reports as a plain user and asserts no data and an error.
- [ ] 5.4 An anonymous caller reads nothing. Verify: a test posts a valid query with no credential and asserts an error and no data.
- [ ] 5.5 The demonstrated failing input for the boundary: removing `security` from one exposed operation makes 5.1 or 5.3 fail — executed and recorded, then restored.

## 6. The cost model

- [ ] 6.1 A GraphQL request consumes one token per root field, refusing the whole request when the budget cannot cover it, before any field is resolved (design decision 3). Verify: a test with a three-root-field document asserts three tokens consumed, and a test with one token left asserts a refusal and that nothing was resolved.
- [ ] 6.2 REST is unaffected. Verify: `tests/Api/Auth/ApiRateLimitTest` passes untouched — one token per REST request, as before.
- [ ] 6.3 The demonstrated failing input: with the per-field charge removed, a ten-root-field document costs one token and the test says so — executed and recorded.
- [ ] 6.4 The depth and complexity ceilings refuse what they are for. Verify: a test posts a document nested past the depth limit and one past the complexity limit, asserting each is refused with an error naming the limit and no data resolved.

## 7. Errors

- [ ] 7.1 GraphQL answers in GraphQL's shape and REST keeps problem details (design decision 5). Verify: a test asserts a refused query carries an `errors` array and no problem-details body, and `tests/Api/ErrorCatalogueTest` and `tests/Api/Contract` pass untouched.
- [ ] 7.2 An internal failure leaks nothing outside `dev`. Verify, with a demonstrated failing input: a resolver made to throw produces an error message carrying no class name, no SQL and no stack frame in the `test` environment; the same in `dev` may carry detail, which is the documented difference.
- [ ] 7.3 Two protocols share one cached report. Verify: a test requests a report through REST and then through GraphQL with the same parameters and asserts the second is served from the cache — the same `generatedAt` and no new query against `clicks`.

## 8. Documents

- [ ] 8.1 `docs/explanation/requirements.md` §4 no longer says GraphQL is disabled; it says what is exposed, read-only, at which path, and §9's stretch list records the item as done. Verify: the file re-read whole around both edits; `rg -n 'GraphQL (is |are )?(disabled|stretch)' docs/` returns nothing stale.
- [ ] 8.2 `docs/reference/api-errors.md` states the boundary: problem details for REST, GraphQL's own shape under the GraphQL path. Verify: the file re-read whole after the edit.
- [ ] 8.3 A how-to for the endpoint: the path, a worked query with a credential, the cost model in one sentence, the depth and complexity limits, and what is deliberately absent. Verify: every command and query in it run in its exact form against the local stack.
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
