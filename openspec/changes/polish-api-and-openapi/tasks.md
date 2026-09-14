# Tasks — polish-api-and-openapi

Tier `medium`: Gate 2 on the code diff before merge; no Gate 1. If any task
turns out to need a change to what an operation answers, stop, raise the
tier in `proposal.md` and request Gate 1 before making it.

## 1. The document tells the truth about errors

- [x] 1.1 Add the OpenAPI factory decorator in `src/Shared/Api/` (design decision 1): for each operation it adds 401 where the path is behind a firewall, 429 where the rate limiter covers it, and 406 where content negotiation can refuse, and replaces every response of status 400 or higher with a single problem-details content carrying the RFC 9457 schema and an example. Verify: `tests/Api/OpenApiDocumentTest.php` asserts that no response of 400 or higher lists the plain JSON media type, that each carries the four problem members in its schema, and that the operations behind a firewall all declare 401.
- [x] 1.2 The decorator takes the firewall and limiter path patterns from configuration rather than repeating them (design decision 2). Verify: a test asserts the documented-401 set equals the set of paths the access-control rules cover, computed from the same parameters, and that the public paths — the authentication endpoints and the documentation — declare no 401; a demonstrated failing input: pointing the decorator at a literal pattern of its own makes the test fail when the configuration and the literal disagree.
- [x] 1.3 Declare 429 with `Retry-After` and the rate-limit headers on the covered operations, and 409 on the API-key creation at its cap (spec `api-docs`, "the rate-limited operations document their headers"). Verify: the same test asserts the headers on a covered operation's success and 429 responses, and that `POST /api/v1/api-keys` declares 409 while no other operation does.
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

## 5. Wrap-up

- [x] 5.1 `make check` green (cs, stan level 8, all suites); `openspec validate polish-api-and-openapi --strict` passes; commits per logical block with the agent trailer; `handoff.md` updated to `awaiting-gate-2`, naming for the reviewer what the decorator reads from configuration and what the contract tests do and do not prove.
- [ ] 5.2 Green Actions run on the exact branch head before Gate 2: the user pushes the change branch; the executor queries `https://api.github.com/repos/Azazu/linkboard/actions/runs?branch=change/polish-api-and-openapi` until the run for `git rev-parse HEAD` is `completed` / `success`; URL and SHA recorded in `handoff.md`. Verify: the run's `head_sha` equals the branch head.
- [ ] 5.3 `scripts/pregate-verify.sh gate2 polish-api-and-openapi` passes and `scripts/gate-run.sh polish-api-and-openapi 2 full` is run; findings fixed and re-reviewed with `scripts/gate-run.sh polish-api-and-openapi 2 confirm <round>`. Verify: `review.md` carries a Gate 2 round bound to the requested commit, and its last decision reads `approved`/`confirmed` with no finding row left `open`.
