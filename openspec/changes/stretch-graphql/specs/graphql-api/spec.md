# Delta — graphql-api

## Purpose

A read-only GraphQL endpoint over the links, the analytics reports and the
current user, serving the same data as the REST API under the same
authorization and the same per-identity rate budget — so that a second protocol
is a second way to ask, not a second set of rules.

## ADDED Requirements

### Requirement: A read-only GraphQL endpoint
The API SHALL serve GraphQL at `/api/v1/graphql`, accepting **`POST` only** with a JSON body carrying `query` and optional `variables`; another method at that path SHALL be refused as method not allowed. The same endpoint SHALL also answer a `POST` at the unversioned `/api/graphql`, which the framework registers, exactly as `/api/docs` answers beside `/api/v1`; both paths SHALL be covered by the same firewall and the same rate budget, and the versioned one SHALL be the documented one. The schema SHALL expose queries only: an item and a collection query for links, an item query for each of the nine analytics reports, and a query for the current user. The schema SHALL contain **no mutation type**, so no data can be created, changed or deleted through it.

#### Scenario: A link is readable through GraphQL
- **WHEN** the owner of a link posts `{ link(id: "<iri>") { slug targetUrl clickCount } }` with a valid credential
- **THEN** the response status is 200 and `data.link` carries that link's slug, target URL and click count, equal to what `GET /api/v1/links/{id}` returns

#### Scenario: Nothing can be written
- **WHEN** a client introspects the schema
- **THEN** the schema declares no mutation type, and a document containing a mutation is rejected

#### Scenario: Both paths answer a POST, and identically
- **WHEN** the same query with the same credential is posted to `/api/v1/graphql` and to `/api/graphql`
- **THEN** both answer with the same data, and an unauthenticated post to either is refused the same way

#### Scenario: The documented path takes POST only
- **WHEN** a client sends `GET` to `/api/v1/graphql`
- **THEN** the response status is 405

#### Scenario: No other path answers
- **WHEN** a client posts a valid query to any path other than those two
- **THEN** the response status is 404

### Requirement: Every API resource declares what it exposes to GraphQL
The schema SHALL contain types for links, the nine analytics reports and the current user, and SHALL NOT contain types or queries for user administration, registration or API keys.

Exclusion SHALL be **explicit**: a resource that is meant to stay out of the schema SHALL declare an empty GraphQL operation list. Silence is not exclusion — a resource that declares no GraphQL operations receives the framework's default set, which includes the mutations that create, update and delete it. Every API resource SHALL therefore carry a declaration, and the check that the schema holds only what is intended SHALL be automatic rather than a matter of review.

#### Scenario: The excluded resources are absent
- **WHEN** a client introspects the schema
- **THEN** it contains no type or query whose name derives from user administration, registration or API keys

#### Scenario: An undeclared resource is caught, because silence would expose it
- **WHEN** a resource class carries an API resource declaration and no GraphQL operation list
- **THEN** it appears in the schema with the framework's default operations, mutations included, and the automatic check fails naming that type — so the resource cannot reach production undeclared

#### Scenario: An explicitly excluded resource stays out
- **WHEN** a resource class declares an empty GraphQL operation list
- **THEN** neither a query nor a mutation for it appears in the schema

### Requirement: Authorization is the same as the REST API's
Every GraphQL query SHALL require the same credential and SHALL be subject to the same voters as the REST operation serving the same data. A caller SHALL NOT be able to read through GraphQL anything the REST API would refuse them, and a refusal SHALL distinguish the cases exactly as the REST API distinguishes them — no more and no less.

That last clause is deliberate and was corrected during implementation: the REST API answers **403** for a resource the caller may not see and **404** for one that does not exist, because "403 tells an integrator plainly" and the enumeration it costs is accepted there and mitigated by the rate limit ([ADR-005](../../../../docs/adr/ADR-005-pages-answer-404.md)). Answering both alike is the *pages'* property, not the API's; importing it here would have made GraphQL disagree with the protocol it mirrors.

#### Scenario: A stranger cannot read someone else's link
- **WHEN** an authenticated user posts a query for a link owned by somebody else
- **THEN** `data.link` is null and the response carries the refusal the REST API gives that caller, distinct from the one a link that does not exist gives

#### Scenario: A stranger cannot read someone else's reports
- **WHEN** an authenticated user queries any of the six per-link reports for a link owned by somebody else
- **THEN** the query returns no report data and carries an error

#### Scenario: The global reports still require an administrator
- **WHEN** a user without the administrator role queries a global statistics report
- **THEN** the query returns no data and carries an error, exactly as the REST operation refuses it

#### Scenario: An anonymous caller reads nothing
- **WHEN** a client posts any query without a credential
- **THEN** the request is refused by the firewall with 401 and a problem-details body, before the executor runs — the credential requirement is the firewall's, not a resolver's

#### Scenario: An invalid credential reads nothing
- **WHEN** a client posts a query with a malformed token, an expired token or an unknown API key
- **THEN** the request is refused with 401 and a problem-details body, at both paths, and no field is resolved

#### Scenario: A blocked account reads nothing
- **WHEN** the holder of a valid credential whose account is blocked posts a query
- **THEN** the request is refused before the executor runs, exactly as the REST API refuses that account, and no field is resolved

### Requirement: Report parameters are the same parameters
A report queried through GraphQL SHALL accept the same parameters as its REST operation — the period, the bucket size, the top-N limit and the bot flag — SHALL validate them by the same rules, and SHALL produce the same figures and the same cached entry as the REST call with those parameters. A report SHALL NOT silently answer with default parameters when the caller supplied others.

#### Scenario: The period is honoured
- **WHEN** a report is queried through GraphQL with an explicit period
- **THEN** the figures are those of that period, identical to the REST call with the same period

#### Scenario: A refused parameter is refused the same way
- **WHEN** a report is queried with a period longer than the maximum, or a bucket size the period does not allow, or a limit outside its bounds
- **THEN** the query carries an error naming the parameter, and no report data is returned

#### Scenario: Two protocols share one cached report
- **WHEN** the same report with the same parameters is requested through REST and then through GraphQL
- **THEN** the second request is served from the cache the first populated, and both carry the same `generatedAt`

### Requirement: A GraphQL document costs what the work costs
A GraphQL request SHALL consume one token of the caller's per-identity rate budget for each **root selection** of the executed operation, so that asking for ten reports in one document costs what ten REST calls cost. When the budget cannot cover the document the request SHALL be refused before any field is resolved.

The count SHALL be taken from the document as parsed, not from its text: named spreads and inline fragments at the root SHALL contribute the selections they name, aliases of one field SHALL count separately, two selections sharing one response key SHALL count twice, and `@skip`/`@include` SHALL NOT reduce the count, because a directive evaluated at execution time cannot lower a price charged before execution. The operation SHALL be the one `operationName` names, and an `operationName` naming no definition SHALL be refused rather than ignored. A document whose root selections are all introspection SHALL cost one, whatever their number.

#### Scenario: A document with several root selections costs several tokens
- **WHEN** a caller posts a document with three root selections
- **THEN** three tokens are consumed from that caller's budget

#### Scenario: Aliases and fragments are counted
- **WHEN** a document asks for one field twice under two aliases, a document spreads a named root fragment naming two fields, and a document carries an inline root fragment naming two fields
- **THEN** each costs two

#### Scenario: The named operation is the one charged
- **WHEN** a document carries two operations and the body names one of them
- **THEN** the cost is that operation's root selections, not the other's

#### Scenario: A skipped field is still paid for
- **WHEN** a document's root selection carries `@skip(if: true)`
- **THEN** it is still counted, because the price is charged before the directive is evaluated

#### Scenario: Introspection costs one
- **WHEN** a document asks only for `__schema` and `__type`
- **THEN** one token is consumed

#### Scenario: A document over the budget is refused whole
- **WHEN** a caller with one token left posts a document with three root selections
- **THEN** the request is refused, and no field is resolved

### Requirement: An unusable request is refused before it is priced
A request whose body is not a JSON object with a string `query`, whose `variables` is present but not an object, whose document does not parse, or whose operation cannot be selected — no operation, or several without `operationName` — SHALL be refused without consuming a token and without resolving anything.

#### Scenario: A malformed body is refused
- **WHEN** a client posts a body that is not a JSON object, or one whose `query` is not a string, or one whose `variables` is not an object
- **THEN** the request is refused and no token is consumed

#### Scenario: An unparseable document is refused
- **WHEN** a client posts a `query` that does not parse
- **THEN** the request is refused and no token is consumed

#### Scenario: An ambiguous or unmatched operation is refused
- **WHEN** a document carries two operations and the body names neither, or carries none, or the body names an operation the document does not define
- **THEN** the request is refused and no token is consumed

#### Scenario: A fragment cycle is refused rather than followed
- **WHEN** a document's root fragments refer to one another in a cycle
- **THEN** the request is refused and no token is consumed, because a counter that follows the cycle never returns

### Requirement: The schema bounds what one document may ask
The endpoint SHALL refuse a document exceeding a declared query depth or a declared query complexity, before executing it. Both limits SHALL be configuration with stated defaults.

#### Scenario: An over-deep document is refused
- **WHEN** a client posts a document nested deeper than the declared limit
- **THEN** the response carries an error naming the depth limit and no data is resolved

#### Scenario: An over-complex document is refused
- **WHEN** a client posts a document whose complexity exceeds the declared limit
- **THEN** the response carries an error naming the complexity limit and no data is resolved

### Requirement: Which refusals answer in which shape
A refusal decided **by the GraphQL executor** SHALL answer in the shape the GraphQL specification defines — a 200 response carrying `data` and `errors`. A refusal decided **before the request reaches the executor** SHALL answer in RFC 9457 problem details with its status, because it is the same firewall and the same rate limiter the REST API uses and they are not duplicated for one endpoint. The boundary SHALL be documented where a reader meets errors, so that two shapes in one API are a stated contract rather than a surprise.

Answered as problem details: a missing, invalid or blocked credential (401/403); a request over the per-identity budget (429); a body or document that cannot be priced (400). Answered as GraphQL errors: a voter refusing a resource, a refused report parameter, an exceeded depth or complexity limit, and an unexpected internal failure.

#### Scenario: A refusal by the executor is a GraphQL error
- **WHEN** a query is refused by a voter, by a report parameter or by a depth or complexity limit
- **THEN** the response status is 200 and the body carries an `errors` array in GraphQL's shape

#### Scenario: A refusal before the executor is problem details
- **WHEN** a request carries no credential, or exceeds the per-identity budget, or carries a body that cannot be priced
- **THEN** the response carries its own status — 401, 429 or 400 — and an `application/problem+json` body, like every other API refusal

#### Scenario: The REST contract is untouched
- **WHEN** any REST operation answers any documented error status
- **THEN** its body is `application/problem+json` exactly as before this capability existed

#### Scenario: An internal failure leaks nothing
- **WHEN** a query fails for an unexpected internal reason outside the development environment
- **THEN** the error message carries no stack trace, no SQL and no class name
