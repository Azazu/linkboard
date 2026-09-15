# Tasks — polish-api-and-openapi

Tier `high` (raised on 2026-09-14 by the user, after Gate 2 round 1 found
that the implementation had moved firewall configuration while the proposal
claimed authentication was untouched): Gate 1 on the artifacts, Gate 2 on the
code diff, and a demonstrated failing input for every boundary the change
touches. Sections 1–4 are implemented; sections 0, 6 and 7 are what the
raised tier and round 1's findings add, and section 8 closes the change.

## 0. Gate 1

- [x] 0.1 Request Gate 1 on the corrected artifacts (`scripts/gate-run.sh polish-api-and-openapi 1 full`) and disposition every finding before the remaining implementation tasks start. Verify: the last Gate 1 record reads `confirmed` or `approved` with no finding row left `open`.

## 1. The document tells the truth about errors

- [x] 1.1 Add the OpenAPI factory decorator in `src/Shared/Api/` (design decision 1): for each operation it adds 401 where the path is behind a firewall, 429 where the rate limiter covers it, and 406 where content negotiation can refuse, and replaces every response of status 400 or higher with a single problem-details content carrying the RFC 9457 schema and an example. Verify: `tests/Api/OpenApiDocumentTest.php` asserts that no response of 400 or higher lists the plain JSON media type, that each carries the four problem members in its schema, and that the operations behind a firewall all declare 401.
- [x] 1.2 The decorator takes the firewall and limiter path patterns from configuration rather than repeating them (design decision 2). Verify: a test asserts the documented-401 set equals the set of paths the access-control rules cover, computed from the same parameters, and that the public paths — the authentication endpoints and the documentation — declare no 401; a demonstrated failing input: pointing the decorator at a literal pattern of its own makes the test fail when the configuration and the literal disagree.
- [x] 1.3 Declare 429 with `Retry-After` and the rate-limit headers on the covered operations, and 409 on the API-key creation at its cap (spec `api-docs`, "the rate-limited operations document their headers"). Verify: the same test asserts `Retry-After` on every 429, the remaining-allowance headers on the successful responses of the identity-limited operations **and their absence on the per-address-limited authentication operations**, which send none, and that `POST /api/v1/api-keys` declares 409 while no other operation does.
- [x] 1.4 Assert the rewrite loses nothing. Verify: a test compares the decorated document with the undecorated one from the inner factory and asserts every operation and every response status of the original is still present, so the decorator only adds and narrows.

## 2. Examples

- [x] 2.1 Add `ApiProperty(example: …)` to every property of the API resources and input DTOs under `src/Link/Api/`, `src/Auth/Api/` and `src/Analytics/Api/` (design decision 3). Verify: `tests/Api/OpenApiDocumentTest.php` asserts every property of every schema the document defines carries an example, naming the ones that do not.
- [x] 2.2 Every example is a value its own schema accepts. Verify: a test walks the document's schemas and checks each example against its property's declared type, format and enum — a wrong type or a value outside an enum fails it.

## 3. Contract tests

- [x] 3.1 Add `tests/Api/Contract/` (design decision 4): one representative request per documented operation, asserting the status returned is declared for that operation and the media type matches what the document gives for that status. Verify: the suite covers all 25 operations — a test asserts the table's operation set equals the document's, so an operation added later without a contract case fails the suite rather than going unchecked.
- [x] 3.2 The refusals are exercised too: an anonymous request (401), one refused by authorization (403), one for an identifier nothing has (404), one refused by validation (422), and the API-key cap (409). Verify: each is a case in the contract suite whose observed status is documented for that operation.
- [x] 3.3 The rate-limit refusal (429) where the limiter can be driven in a test, recorded as not exercised where it cannot. Verify: the case observes a 429 — the limiter's factory is swapped for one with a window of three, the way `tests/Api/Auth/ApiRateLimitTest.php` does, so it is exercised rather than recorded as unexercised — and asserts that the operation declares 429 with `Retry-After` and that the response carries the header and the problem media type.
- [x] 3.4 The declared filters and ordering behave as documented. Verify: contract cases send `isActive`, `slug`, `order[createdAt]` and `order[clickCount]` to both link collections and assert the selection and order they produce, and that the analytics report parameters (`from`, `to`, `granularity`, `limit`, `includeBots`) are declared on the report operations.

## 4. The error catalogue

- [x] 4.1 Write `docs/reference/api-errors.md` (design decision 5): every `type` the API can produce, what the condition means, which operations raise it, and the client's recovery — naming `Retry-After` for the rate-limit refusal and the `violations` array for a validation failure. Verify: the file is re-read whole after the last edit and every documented command in it was run in its exact form.
- [x] 4.2 A test keeps the catalogue complete. Verify: it enumerates the types the code can produce — the statuses the document declares plus what `src/Shared/Api/ProblemDetails.php` can emit — and fails naming any that the catalogue does not list; a demonstrated failing input, executed: with the **/errors/409** row removed the test fails naming `/errors/409 (declared by an operation)`, and it passes again once the row is back.

## 6. What Gate 2 round 1 found

- [x] 6.1 **Finding 2** — an unsupported request media type answers 415 and nothing documented it. Declare 415 on the operations that accept a body, correct the catalogue's 400 row (it described media-type failures that are in fact 415), and add the row for 415. Verify: a contract case posts `Content-Type: text/plain` to an operation with a body and asserts the observed status is declared for it; `ErrorCatalogueTest` covers the new type.
- [x] 6.2 **Finding 3** — the token operation's statuses come from `json_login`, not from an operation: it answers 400 for a malformed or incomplete credential payload and 403 for a blocked account, and cannot answer 406 because it replies before content negotiation. Declare its statuses from that path and stop adding 406 to it (design decision 2a). Verify: contract cases send a malformed credential payload, a blocked account's credentials and an incompatible `Accept`, each asserting the observed status against the document; the catalogue's blanket "every operation" claim for 406 is corrected.
- [x] 6.3 **Finding 4** — the JWT bundle generates that operation's request and response schemas inline, without examples, and its success response actually carries `expiresAt`, which the schema omits. Describe the payload with examples and the missing member. Verify: the example test walks inline request and response schemas as well as `components.schemas`, and a demonstrated failing input: removing one inline example fails it.
- [x] 6.4 **Finding 5** — `AuthRateLimitSubscriber` still holds its own hard-coded rule, method included, so the documented 429 set and the limiter's real coverage are two definitions. Make the subscriber read the same parameter, model the method in the decorator, and derive the documented-401 set from the access rules rather than from a separate list. Verify: a test reads `config/packages/security.yaml` and asserts the documented-401 operations are exactly those its rules cover and the documented-429 operations exactly those the two limiters cover, method included; demonstrated failing inputs: changing one access-control pattern, and changing the subscriber's method, each fail that test.
- [x] 6.5 **Finding 6** — contract cases carried no expected status, so a refusal case that returned a documented 200 passed. Give every case the status it expects, add the missing cases (the API-key cap's 409, the admin collection's filters, `order[createdAt]`), and check `format` as well as type in the example validator. Verify: the suite asserts the expected status per case; a demonstrated failing input: pointing a refusal case at a request that succeeds fails it.

## 7. Evidence for the raised tier

- [x] 7.1 Every moved firewall value is identical to the literal it replaced. Verify: a test resolves each parameter and asserts it equals the value `config/packages/security.yaml`, `config/routes.yaml` and the limiter classes used before this change, with the before-values written in the test; and the existing authentication, authorization and rate-limit suites pass unedited.
- [x] 7.2 A demonstrated failing input per moved boundary, executed and recorded. Each mutation edits `config/services.yaml`, then:

  ```
  docker compose exec -T php bin/console cache:clear --env=test
  docker compose exec -T php vendor/bin/phpunit tests/Api/MovedPathPolicyTest.php tests/Api/DocumentedPolicyTest.php tests/Api/Auth/RateLimitTest.php
  ```

  **(a)** `app.api.path.auth` changed to `^/api/v1/authentication/` → `MovedPathPolicyTest::testEveryMovedValueIsTheLiteralItReplaced` and `::testTheFirewallStillCarriesTheSameRules` fail, and `DocumentedPolicyTest::testTheAllowanceHeadersAreDocumentedOnlyWhereTheirLimiterSendsThem` fails because the document now claims an allowance the per-address limiter never sends. **(b)** `app.api.path.token` changed to the path **/api/v1/auth/jwt** → the same two identity tests plus `::testTheTokenRouteStillHasItsPath` fail. **(c)** `app.api.unlimited_paths` emptied → `::testEveryMovedValueIsTheLiteralItReplaced` fails; the policy test does **not**, and that is correct — both sides of it move together, which is why the identity test exists beside it. **(d)** the per-IP rule's method changed to `GET` → the identity test fails and so do `tests/Api/Auth/RateLimitTest::testEleventhAttemptWithinAMinuteIs429WithRetryAfter` and `::testSpoofedForwardedForFromAnUntrustedPeerIsIgnored`, the behavioural net catching a limiter that stopped guarding the endpoint. Restored and re-run: `OK (10 tests, 91 assertions)`.

## 9. What Gate 2 round 2 found

- [x] 9.1 **Finding 1** — the report operations validate their query parameters and answer 422, which nothing documented; the collections answer 400 for a value their parameter schema refuses, also undocumented. Measured before coding: `stats/summary?from=yesterday`, `countries?limit=0`, an inverted period and `timeseries?granularity=week` each answer 422; `links?page=abc` and `links?isActive=maybe` answer 400; `api-keys?itemsPerPage=nine` answers 200. Each operation declares the status it sends — the reports 422, the collections 400, the QR operation 422 for a `format` outside its enum — sharing one description through `src/Shared/Api/RefusedParameters.php`, because which status an operation uses is its own business and no path rule can tell. Verify: `OpenApiDocumentTest::testAnOperationThatTakesQueryParametersSaysHowItRefusesOne` fails for any operation that takes a query parameter without declaring 400 or 422 — it caught the QR operation while being written — and nine contract cases assert the observed refusals, individual and cross-parameter, on both the link and the global reports and on both collections.
- [x] 9.2 **Finding 2** — the 415 declared on `POST /api/v1/auth/token` is unreachable: the authenticator declines a body it cannot read, no controller runs, and the kernel answers as for a missing route. Measured: that request answers **404**, not 415. The operation now declares 404 with the reason and no 415; the catalogue's 404 and 415 rows say the same. Verify: a contract case posts `Content-Type: text/plain` to the token endpoint and asserts 404 against the document.

## 8. Wrap-up

- [ ] 8.1 `make check` green (cs, stan level 8, all suites); `openspec validate polish-api-and-openapi --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated to `awaiting-gate-2`, naming for the reviewer what the decorator reads from configuration and what the contract tests do and do not prove.
- [ ] 8.2 Green Actions run on the exact branch head before Gate 2: the user pushes the change branch; the executor queries `https://api.github.com/repos/Azazu/linkboard/actions/runs?branch=change/polish-api-and-openapi` until the run for `git rev-parse HEAD` is `completed` / `success`; URL and SHA recorded in `handoff.md`. Verify: the run's `head_sha` equals the branch head.
- [ ] 8.3 `scripts/pregate-verify.sh gate2 polish-api-and-openapi` passes and `scripts/gate-run.sh polish-api-and-openapi 2 full` is run; findings fixed and re-reviewed with `scripts/gate-run.sh polish-api-and-openapi 2 confirm <round>`. Verify: the change's review record carries a Gate 2 round bound to the requested commit, and its last decision reads `approved`/`confirmed` with no finding row left `open`. (The record is written by the runner, so this task is ticked as the gate is requested — the floor requires every task checked by then.)
