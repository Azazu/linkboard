# Delta — graphql-api

## Purpose

A read-only GraphQL endpoint over the links, the analytics reports and the
current user, serving the same data as the REST API under the same
authorization and the same per-identity rate budget — so that a second protocol
is a second way to ask, not a second set of rules.

## ADDED Requirements

### Requirement: A read-only GraphQL endpoint
The API SHALL serve GraphQL at `/api/v1/graphql`, accepting `POST` with a JSON body carrying `query` and optional `variables`. The schema SHALL expose queries only: an item and a collection query for links, an item query for each of the nine analytics reports, and a query for the current user. The schema SHALL contain **no mutation type**, so no data can be created, changed or deleted through it.

#### Scenario: A link is readable through GraphQL
- **WHEN** the owner of a link posts `{ link(id: "<iri>") { slug targetUrl clickCount } }` with a valid credential
- **THEN** the response status is 200 and `data.link` carries that link's slug, target URL and click count, equal to what `GET /api/v1/links/{id}` returns

#### Scenario: Nothing can be written
- **WHEN** a client introspects the schema
- **THEN** the schema declares no mutation type, and a document containing a mutation is rejected

#### Scenario: The endpoint lives at one path
- **WHEN** a client posts a valid query to any path other than `/api/v1/graphql`
- **THEN** the response status is 404

### Requirement: The schema exposes only the declared resources
The schema SHALL contain types for links, the nine analytics reports and the current user, and SHALL NOT contain types or queries for user administration, registration or API keys. A resource that is not declared for GraphQL SHALL NOT appear in the schema merely because it is an API resource.

#### Scenario: The excluded resources are absent
- **WHEN** a client introspects the schema
- **THEN** it contains no type or query whose name derives from user administration, registration or API keys

#### Scenario: A newly added API resource is not exposed by accident
- **WHEN** a resource class carries an API resource declaration without a GraphQL operation
- **THEN** it does not appear in the GraphQL schema

### Requirement: Authorization is the same as the REST API's
Every GraphQL query SHALL require the same credential and SHALL be subject to the same voters as the REST operation serving the same data. A caller SHALL NOT be able to read through GraphQL anything the REST API would refuse them, and the refusal SHALL NOT reveal whether the resource exists where the REST API would not.

#### Scenario: A stranger cannot read someone else's link
- **WHEN** an authenticated user posts a query for a link owned by somebody else
- **THEN** `data.link` is null and the response carries an error, and the error does not distinguish "not yours" from "does not exist"

#### Scenario: A stranger cannot read someone else's reports
- **WHEN** an authenticated user queries any of the nine reports for a link owned by somebody else
- **THEN** the query returns no report data and carries an error

#### Scenario: The global reports still require an administrator
- **WHEN** a user without the administrator role queries a global statistics report
- **THEN** the query returns no data and carries an error, exactly as the REST operation refuses it

#### Scenario: An anonymous caller reads nothing
- **WHEN** a client posts any query without a credential
- **THEN** the response carries an error and no data, and the credential requirement is the firewall's, not a resolver's

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
A GraphQL request SHALL consume one token of the caller's per-identity rate budget **for each root field of the document**, so that asking for ten reports in one document costs what ten REST calls cost. When the budget is exhausted the request SHALL be refused before any field is resolved.

#### Scenario: A document with several root fields costs several tokens
- **WHEN** a caller posts a document with three root fields
- **THEN** three tokens are consumed from that caller's budget

#### Scenario: A document over the budget is refused whole
- **WHEN** a caller with one token left posts a document with three root fields
- **THEN** the request is refused, and no field is resolved

### Requirement: The schema bounds what one document may ask
The endpoint SHALL refuse a document exceeding a declared query depth or a declared query complexity, before executing it. Both limits SHALL be configuration with stated defaults.

#### Scenario: An over-deep document is refused
- **WHEN** a client posts a document nested deeper than the declared limit
- **THEN** the response carries an error naming the depth limit and no data is resolved

#### Scenario: An over-complex document is refused
- **WHEN** a client posts a document whose complexity exceeds the declared limit
- **THEN** the response carries an error naming the complexity limit and no data is resolved

### Requirement: GraphQL answers in GraphQL's shape, and says so
The GraphQL endpoint SHALL answer in the shape the GraphQL specification defines — a 200 response carrying `data` and, where applicable, `errors` — and SHALL NOT answer in RFC 9457 problem details. The REST API's problem-details contract SHALL be unchanged by this capability, and the boundary SHALL be documented so that a reader is not surprised by two error formats in one API.

#### Scenario: A GraphQL error is a GraphQL error
- **WHEN** a query is refused for any reason — authorization, a parameter, a limit
- **THEN** the response carries an `errors` array in GraphQL's shape rather than a problem-details body

#### Scenario: The REST contract is untouched
- **WHEN** any REST operation answers any documented error status
- **THEN** its body is `application/problem+json` exactly as before this capability existed

#### Scenario: An internal failure leaks nothing
- **WHEN** a query fails for an unexpected internal reason outside the development environment
- **THEN** the error message carries no stack trace, no SQL and no class name
