# Review — stretch-graphql

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-17
**Reviewed-Commit:** b191b358c647bd23d2f2f4d46c23b87ebaad6753
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | `design.md` decision 1; `proposal.md` What Changes; `tasks.md` 4.1–4.4 | The exclusion mechanism is the inverse of API Platform 4.3's behaviour. When GraphQL is enabled and an `ApiResource` leaves `graphQlOperations` as `null`, `MetadataCollectionFactoryTrait` calls `addDefaultGraphQlOperations()`, which adds item/collection queries and create/update/delete mutations. Thus leaving `UserAdmin`, `Registration`, and `ApiKeyOutput` “alone” exposes them rather than excluding them, violating the read-only and schema-boundary requirements. The artifacts also repeatedly refer to five excluded resources although the enumerated 14 resources minus Link, Me, and nine reports leave three. Revise the mechanism (for example, explicit empty GraphQL operation lists on every excluded resource), inventory, implementation tasks, and the demonstrated regression test before implementation. | fixed |
| 2 | major | `design.md` decisions 1/4; `tasks.md` 2.2–2.3 | The planned configuration does not produce the specified endpoint. The installed API Platform route is `/graphql`, and the repository imports all API Platform routes with `prefix: /api`; `defaults.route_prefix: /v1` applies to resource operations, not that external GraphQL route. Enabling GraphQL therefore registers `/api/graphql`—the exact path task 2.3 expects to be 404—while no design decision or implementation step explains how `/api/v1/graphql` will be registered without moving `/api/docs`. Specify the routing mechanism and add the corresponding implementation task, then keep the positive and negative route tests. | fixed |
| 3 | blocker | `specs/graphql-api/spec.md` “GraphQL answers in GraphQL's shape”; `design.md` decisions 3, 5, and 6; `tasks.md` 2.4, 6.1, and 7.1 | The promised universal GraphQL error shape conflicts with the chosen pre-controller enforcement. Missing/invalid credentials are currently answered by the existing firewall handlers as RFC 9457 401/403 responses, and `ApiRateLimitListener` directly sets an RFC 9457 429 response on `LoginSuccessEvent`; none reaches API Platform's GraphQL executor. Consequently the anonymous and over-budget cases cannot both be firewall/pre-resolution refusals and `200` GraphQL `errors` responses under the stated “same firewall, no new authenticator” design. Decide the transport contract for authentication and rate-limit failures, make the spec and api-docs delta consistent with it, and name an implementation mechanism plus tests for each pre-controller failure path. | fixed |
| 4 | major | `design.md` decision 3 and Applicability; `specs/graphql-api/spec.md` “A GraphQL document costs what the work costs”; `tasks.md` 6.1–6.3 | The high-risk rate-limit parser is underspecified and its tests cover only a flat, single-operation document. “Root fields of the operation” must define and verify selection of `operationName`, rejection of ambiguous/missing operations, root fields introduced through named/inline fragments, aliases/repeated selections, directives, introspection, and malformed bodies. Otherwise a hand-written counter can undercharge work or charge an unselected operation. The Applicability table mentions some malformed cases, but no implementation/verification task covers them, and the only demonstrated failing input merely removes the whole per-field charge. Add a precise counting/rejection algorithm and bypass-focused tests/failing inputs. | fixed |
| 5 | minor | `specs/graphql-api/spec.md` scenario “A stranger cannot read someone else's reports” | The scenario says “any of the nine reports for a link”, but only six reports are per-link; the other three are global administrator reports and are covered by the next scenario. This is not an executable acceptance condition as written. Change it to the six per-link reports, matching task 5.2. | fixed |

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-17
**Reviewed-Commit:** 1aecb289115ef4abea26d7901908339c313f1b81
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — The intended explicit operation lists and the regression direction are now correct, but the exclusion claim was not reconciled across the artifacts. `design.md` still says the surface is chosen by declarations on “the five exposed classes”; `proposal.md` Impact still says “the other five” are left alone; and `handoff.md` still says the excluded resources declare nothing and that the failing input adds a declaration. These directly contradict the corrected inventory and mechanism (11 exposed, 3 explicitly empty). |
| 2 | changes-requested — The versioned-route mechanism is now named, but its method contract and verification remain inconsistent with the installed route. API Platform's `api_graphql_entrypoint` route declares no method restriction, while the api-docs delta asserts `GET /api/graphql` is 405 because the endpoint accepts POST only; task 2.3 tests only successful POSTs, and the planned custom route likewise does not specify `methods: [POST]`. The route plan therefore still does not establish the claimed positive/negative behavior. |
| 3 | changes-requested — The artifacts now consistently choose problem details for pre-executor failures and identify the firewall/rate-limit listener as the mechanism. However, the contract explicitly covers missing, invalid, and blocked credentials, while tasks 2.4 and 7.1 verify only a missing credential; no task verifies invalid or blocked credentials at either GraphQL path. The requested tests for each credential failure path are therefore incomplete. |
| 4 | changes-requested — The parser plan is more precise, but it still omits named-operation selection tests, a missing/nonexistent `operationName`, inline fragments, repeated selections with the same response key, and fragment-cycle rejection. Tasks 6.2–6.5 cover only named fragment spreads, aliases, directives, introspection, malformed bodies, no operation, and ambiguity without `operationName`; thus several bypass cases explicitly named in the finding remain unspecified or unverified. |

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-17
**Reviewed-Commit:** fc51d0a47ccd9b0b55400325d79c6268c76fba8d
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — The inventory, explicit empty lists, implementation tasks, and demonstrated regression direction are now correct, but the capability spec still states the inverse mechanism. `specs/graphql-api/spec.md` says a resource carrying `ApiResource` “without a GraphQL operation” does not appear in the schema (lines 35–44); under the API Platform default behavior established by this finding, that undeclared resource receives default queries and mutations. The requirement and scenario must instead express explicit exclusion (for example, `graphQlOperations: []`) so the living requirement agrees with the design and tasks. |
| 2 | confirmed — The artifacts now specify the custom `/api/v1/graphql` route, bind it to the GraphQL entrypoint with `methods: [POST]`, test POST on both registered paths and 405 only on the versioned route, and defer the framework route's GET behavior to an explicit measurement task rather than asserting an unsupported status. |
| 3 | confirmed — The firewall/rate-limit problem-details boundary is consistent across the design and specs, and task 2.4 now verifies missing, malformed, expired, unknown-key, and blocked-account credentials at both GraphQL paths before execution. |
| 4 | confirmed — The counting algorithm and verification tasks now cover named-operation selection and unmatched names, named and inline root fragments, repeated response keys, directives, introspection, malformed and ambiguous documents, and fragment cycles, with demonstrated failing inputs for selection, fragment expansion, cycle protection, and per-selection charging. |

## Confirmation 3 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-17
**Reviewed-Commit:** 90b77ec2e16801dad0708f16677c90508f7207bc
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — The capability spec now requires every API resource to declare its GraphQL operation list, requires excluded resources to use an explicit empty list, and states that an undeclared resource receives the framework defaults including mutations. Its scenarios and tasks also verify both explicit exclusion and automatic failure for an undeclared resource, consistent with the corrected 11-exposed/3-excluded inventory and regression direction. |
| 2 | confirmed — The custom `/api/v1/graphql` route is explicitly bound to the GraphQL entrypoint with `methods: [POST]`; both registered POST paths and the versioned route's 405 behavior are covered, while the framework route's unrestricted GET behavior is left to an explicit measurement task rather than an unsupported assertion. |
| 3 | confirmed — The specs and design consistently assign firewall and rate-limit refusals to RFC 9457 problem details and executor refusals to GraphQL errors, with tests required for missing, malformed, expired, unknown-key, and blocked-account credentials at both GraphQL paths. |
| 4 | confirmed — The parser algorithm and verification tasks cover operation selection and unmatched names, named and inline root fragments, aliases and repeated response keys, directives, introspection, malformed and ambiguous documents, and fragment cycles, with bypass-focused demonstrated failing inputs. |

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-17
**Reviewed-Commit:** f1e946b2fa82ab04421916ebb56f1f89983f83c0
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | `src/Auth/RateLimit/GraphQlCost.php:156-181`; `tests/Unit/Auth/GraphQlCostTest.php` | The pre-executor cost guard can itself be used for denial of service. `count()` recursively re-expands every fragment occurrence without memoization or a bounded/saturating total. An acyclic, valid document whose `F0` spreads `F1` twice, `F1` spreads `F2` twice, and so on before a final `me` field has linear input size but makes this listener visit exponentially many selections (and eventually overflow the integer addition). This runs during `LoginSuccessEvent`, before API Platform's depth/complexity validation, so the configured complexity ceiling cannot protect the worker. The cycle test covers only cyclic recursion and does not catch this acyclic expansion. Make pricing bounded for a document of this shape (for example by memoizing fragment costs and refusing/saturating once the relevant limit is exceeded), handle arithmetic overflow as a refusal, and add a regression input that completes promptly. | fixed |
| 2 | major | `src/Auth/RateLimit/GraphQlCost.php:64-74`; `tests/Unit/Auth/GraphQlCostTest.php:67-79`; `tests/Api/GraphQl/LimitsAndErrorsTest.php:47-65` | The promised JSON-object check for `variables` does not distinguish an object from a list. Associative `json_decode(..., true)` maps both `{"variables": {"n": 1}}` and `{"variables": [1]}` to PHP arrays, and the code accepts both. API Platform likewise accepts the array, so a request the capability says must be refused before pricing can consume a token and reach execution. Decode in a way that preserves object/list shape (including the empty-object/empty-list distinction) and cover a JSON list in both the unit cost test and the pre-executor response test. | fixed |
| 3 | major | `tests/Api/GraphQl/SharedCacheAndBudgetTest.php`; `tasks.md` 6.1; `specs/graphql-api/spec.md` scenario “A document over the budget is refused whole” | The integration tests establish successful token accounting but never exercise the rate-limit refusal this change introduces. There is no GraphQL 429 case in the test tree, no assertion that an over-budget multi-root document is refused before any resolver runs, and no evidence recorded in task 6.1 for that half of the task. A regression that consumes the wrong amount on rejection or continues into the executor would leave all current tests green. Add a request whose root cost exceeds the remaining budget and assert 429 problem details, rate-limit headers, and a resolver-visible side effect/counter proving that no field was resolved. | fixed |
| 4 | major | `tests/Api/GraphQl/LimitsAndErrorsTest.php:104-123`; `tasks.md` 7.3; `specs/graphql-api/spec.md` scenario “An internal failure leaks nothing” | `testAnErrorCarriesNoInternalDetailOutsideDev()` does not produce an unexpected internal failure; it produces the same expected depth-validation error as the preceding limit test. Consequently the assertions for SQL, application class names, and exception details are vacuous and do not verify the checked task or acceptance scenario. Exercise a resolver/provider that throws an exception containing recognizable internal detail under `APP_DEBUG=0`, then assert the GraphQL response is generic and omits that detail. | fixed |
| 5 | major | `openspec/changes/stretch-graphql/design.md:238-250` | The authorization design still states the superseded claim that a stranger's link is indistinguishable from a missing link, and says `security.yaml` gains the GraphQL path. The implemented and specified API contract deliberately distinguishes `Access Denied` from `No such link`, and the existing `^/api/` firewall already covers both paths without a security configuration edit. This is the exact stale-claim class the change's own handoff says was swept after Gate 1; reconcile the design with the capability, tests, and actual mechanism. | fixed |

## Confirmation 1 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-17
**Reviewed-Commit:** 5bfb20cedfa57449f801d00411006d501fc365c7
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — `count()` is now memoised and saturating, but `of()` still calls `isAllIntrospection()` before acting on the saturated count, and that traversal has neither memoisation nor a ceiling. The regression ends in `me`, so `isAllIntrospection()` returns false down its first branch; the same linear doubling-fragment document ending in `__typename` makes it traverse every expanded occurrence exponentially. Pricing is therefore still unbounded for an introspection-only instance of the named hostile shape. |
| 2 | changes-requested — Non-empty JSON lists are now rejected, but `isJsonObject()` deliberately accepts `[]`, and `testAnEmptyVariableSetIsAccepted()` pins that behaviour for both `{}` and `[]`. This is the empty-object/empty-list ambiguity the finding explicitly required the decoder to preserve, and it contradicts the capability, design, docs, and task 6.3 claim that every JSON list is refused. The API-level pre-executor data provider also still has no JSON-list case. |
| 3 | changes-requested — The new test with a three-token document and a two-token whole window covers `consume()` throwing for a charge larger than the limiter's total capacity, not the requested case where root cost exceeds the caller's remaining budget; that response asserts only `Retry-After`, not `X-RateLimit-Limit` or `X-RateLimit-Remaining`. The separate exhausted-remaining-budget test asserts only 429 and does not assert problem details, rate-limit headers, or the resolver-visible no-execution proof. No single regression therefore establishes the refusal contract named in the finding. |
| 4 | confirmed — The API test now causes a real report resolver's database statement to throw a recognizable exception under the non-debug test environment, and asserts the response uses the fixed generic message while omitting the injected detail, SQL, class, file, and vendor-path markers. The decorated error handler is also covered directly, including a control assertion that API Platform's undecorated runtime normalizer exposes the injected message. |
| 5 | confirmed — The design now states that a stranger and a missing link remain distinguishable as `Access Denied` versus `No such link`, ties that behavior to the API rather than the page-specific ADR rule, and records that `security.yaml` is unchanged because the existing `^/api(/|$)` firewall covers both GraphQL paths. |
