# Tasks — stretch-graphql

Tier `high`: a new dependency, a second entry point to the same authorization,
and a query language whose cost a caller chooses — the denial-of-service shape
the roadmap's `medium` does not cover. Gate 1 on the artifacts before any code,
Gate 2 on the diff, and a demonstrated failing input for every new guard.

No REST operation changes. `tests/Api` — the contract tests, the error
catalogue, the OpenAPI document tests — must pass untouched; if one of them
changes, the refactor of section 3 is wrong.

## 1. Gate 1

- [x] 1.1 Request Gate 1 on the artifacts (`scripts/gate-run.sh stretch-graphql 1 full`) and disposition every finding before section 2 starts. Verify: the last Gate 1 record in `review.md` reads `confirmed` with no finding row left `open` — round 1 raised two blockers and three more, confirmations 1 and 2 returned findings for claims left standing elsewhere, and Confirmation 3 (`90b77ec`) confirmed all four. The user was asked before the third attempt, per the two-failed-confirmations rule, and chose to fix and re-review.

## 2. The dependency and the endpoint

- [x] 2.1 `api-platform/graphql:^4.3` required — the package name read from `api-platform/symfony`'s own `suggest` block rather than guessed — pinned like every other dependency. Verify, executed: `composer show` lists `api-platform/graphql v4.3.19` and `webonyx/graphql-php v15.37.2` beneath it; `composer.json` and `composer.lock` committed; `make check` green.
- [x] 2.2 `config/packages/api_platform.yaml`: `graphql.enabled: true`, `introspection.enabled: true`, `graphiql.enabled: false`, `graphql_playground.enabled: false`, `max_query_depth: 10`, `max_query_complexity: 200`, each with its reason as a comment (design decision 4). Verify, executed: `bin/console debug:config api_platform graphql` prints exactly those values.
- [x] 2.3 `config/routes.yaml` declares `api_v1_graphql` at `/api/v1/graphql` on `api_platform.graphql.action.entrypoint` with `methods: [POST]`, mirroring `api_v1_docs` (design decision 3a). Verify, executed: `bin/console debug:router` lists `api_graphql_entrypoint ANY /api/graphql` and `api_v1_graphql POST /api/v1/graphql`; measured with `curl`, `GET /api/v1/graphql` is **405** and `POST` without a credential is **401**.
- [x] 2.5 What the framework's unversioned route answers to a `GET` is **measured, not assumed** (Gate 1 confirmation 1, finding 2). Verify, executed: `GET /api/graphql` answers **401 with `application/problem+json`** — `{"type":"/errors/401","title":"Unauthorized",…,"detail":"Missing bearer token."}` — because the `api` firewall matches `^/api(/|$)` and refuses before the route's method matters. That is the boundary of design decision 5 observed in the wild: a refusal decided before the executor keeps its problem-details shape. `tests/Api/ApiDocsTest` asserts it, replacing the scenario that used to assert 404.
- [x] 2.4 The firewall and the rate limiter cover **both** paths, for **every** credential failure (Gate 1 confirmation 1, finding 3). Verify, executed: `config/packages/security.yaml`'s `api` firewall already matches `^/api(/|$)`, so both paths are covered with no new firewall and no new authenticator, and `app.api.graphql_paths` names them together for the limiter — a path rate-limited differently from its alias is the bug naming them together prevents. `tests/Api/GraphQl/AuthorizationTest` asserts, at **each** path, that no credential, a malformed token and an unknown API key are 401 problem details and a **blocked** account is 403 with `blocked` — the status the REST API gives that account, decided by the user checker in the firewall before any executor.

## 3. Report parameters stop being HTTP-shaped

- [x] 3.1 `ReportRequestFactory::parse()` and `fromValues()` take an `array<string, mixed>` instead of a `?Request`; the REST providers pass the query string through the new `ReportParameters::fromContext()`, which reads `$context['request']->query->all()` for REST and `$context['args']` for GraphQL (design decision 2). Verify, executed: `rg -n 'HttpFoundation' src/Analytics/` returns nothing — the analytics tree no longer knows about HTTP at all.
- [x] 3.2 **The REST behaviour is unchanged.** Verify, executed: `git diff main --stat -- tests/Api/Analytics tests/Web/Stats` is **empty** — those suites pass with no edit whatsoever, which is the whole guard on this refactor. One test did change, `tests/Unit/Analytics/ReportRequestParsingTest.php`, and only in how it *calls* the parser (a map where it used to build a `Request`); every assertion in it is untouched.
- [x] 3.3 The statistics page still parses the same way. Verify, executed: `src/Web/Stats/StatsControls.php` passes its already-assembled `$query` array straight to `parse()` instead of wrapping it in a `Request`, and `tests/Web/Stats` passes untouched.
- [x] 3.4 A report resolved through GraphQL uses the parameters it was given. Verify, executed against the running stack — and the first attempt found that API Platform's `parameters:` are a REST concept that do **not** become GraphQL arguments: the schema exposed `id` alone, so no client could have asked for a period. Each report now declares its own `args`, and REST and GraphQL answer identically for the same period (`clicksInPeriod=2, from=2026-09-14T00:00:00+00:00` both ways). Demonstrated failing input, executed: with `ReportParameters` no longer reading `$context['args']`, that same query returns **`clicksInPeriod=42, from=2026-08-19`** — the default period, with a 200 and no hint to the caller, which is exactly the silent wrong answer this task exists to prevent.

## 4. The surface

- [x] 4.1 `graphQlOperations` declared on every exposed class — `LinkResource` (`Query` + `QueryCollection`), the nine report classes (`Query`) and `Me` (`Query`) — listing queries only, each reusing the REST operation's own provider and security expression (design decision 1). Verify, executed: `bin/console api:graphql:export` lists exactly `link`, `links`, `me`, the six per-link reports and the three global ones, plus the framework's `node`.
- [x] 4.2 The three excluded classes declare `graphQlOperations: []` — `UserAdmin`, `Registration`, `ApiKeyOutput`. Verify, **measured on the way in**: before the nine reports were declared, `api:graphql:export` carried a `type Mutation` with `createAdminSummaryReport`, `updateAdminSummaryReport` and `deleteAdminSummaryReport` — mutations on read models, exactly what Gate 1 round 1 finding 1 predicted. With every resource declared, the export has no mutation type at all.
- [x] 4.3 The schema contains nothing else, and the check is automatic. Verify, executed: `tests/Api/GraphQl/SchemaSurfaceTest.php` asserts the query names **equal** a written-down list, that the mutation type is null, that no type name derives from the three excluded resources, and — the guard the requirement asks for — that **every** class the resource name collection yields carries a non-null `graphQlOperations`, with a floor on how many were inspected so an empty scan cannot pass.
- [x] 4.4 The exclusion cannot lapse silently, in the direction that actually bites. Verify, executed and recorded: **removing** `graphQlOperations: []` from `UserAdmin` — the state this change started from — fails **three** of the four tests at once (the query list, the excluded types, and the declaration guard), because the resource arrives with queries, a type and mutations; restored, all four pass.

## 5. Authorization carries over

- [x] 5.1 A stranger reads nothing, and is refused as the API refuses them. Verify, executed: the test asserts `data.link` is null with `Access Denied` for someone else's link and `No such link` for one that does not exist. **The requirement was wrong when written and was corrected here**: I had imported the *pages'* property (404 to both) into GraphQL, but ADR-005 keeps 403 for the API on purpose — carrying the pages' rule over would have made GraphQL disagree with the protocol it mirrors.
- [x] 5.2 A stranger's reports are refused. Verify, executed: a data provider queries all six per-link reports for someone else's link and asserts each carries errors and no report data.
- [x] 5.3 The global reports still require an administrator. Verify, executed: a data provider queries the three global reports as a plain user and asserts each carries errors.
- [x] 5.4 An anonymous caller reads nothing. Verify, executed: a POST with no credential to **either** path is 401 with `application/problem+json` — refused by the firewall before the executor, which is the shape design decision 5 assigns it.
- [ ] 5.5 The demonstrated failing input for the boundary: removing `security` from one exposed operation makes 5.1 or 5.3 fail — executed and recorded, then restored.

## 6. The cost model

- [x] 6.1 A GraphQL request consumes one token per **root selection** of the executed operation, refusing the whole request when the budget cannot cover it, before any field is resolved (design decision 3). Verify, measured against the running stack: one root selection leaves `X-RateLimit-Remaining: 599`, three aliases leave 596, introspection alone leaves 595 — one token — and a malformed body is 400. `tests/Unit/Auth/GraphQlCostTest.php` pins the algorithm with 23 cases.
- [x] 6.2 The count is taken from the parsed document, by the algorithm decision 3 states. Verify, executed: cases for a named root fragment (2), an inline root fragment (2), two aliases of one field (2), the same field twice unaliased (2), `@skip(if: true)` still charged (2), introspection alone (1) and introspection beside a real field (2).
- [x] 6.2a Operation selection is tested in every shape it comes in. Verify, executed: a two-operation document with a valid `operationName` charges that operation (A→1, B→2, asserted separately); an `operationName` naming nothing is refused; a document with no operation and one with two operations and no name are both refused.
- [x] 6.3 A request that cannot be priced is refused without a token and without resolution. Verify, executed: eleven cases — a body that is not a JSON object, a non-string `query`, non-object `variables`, a non-string `operationName`, an unparseable document, no operation, two operations without a name, an `operationName` naming nothing, a **fragment cycle**, an undefined fragment, and an operation selecting nothing — each asserting the refusal names why and that the cost is 0.
- [x] 6.4 REST is unaffected. Verify, executed: `tests/Api/Auth/ApiRateLimitTest` passes untouched — one token per REST request, as before, because `GraphQlCost::ofOneRequest()` is what a non-GraphQL path gets.
- [x] 6.5 The demonstrated failing inputs, executed and recorded: reducing operation selection to "the first operation" fails the named-operation cases; removing fragment expansion fails the fragment cases; and removing the cycle guard **exhausts 512 MB of memory** rather than failing an assertion — the denial of service written by the defence, exactly as the design predicted. That last mutation also uncovered a real latent fault of my own: `isAllIntrospection()` walked fragments with no cycle guard of its own and was protected only by `count()` running first and throwing. A defence that depends on the order two private methods are called in is a defence that breaks when somebody reorders them, so it now carries its own guard, and the mutation now fails an assertion instead of the process.
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
