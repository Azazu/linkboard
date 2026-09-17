# Proposal — stretch-graphql

**Risk-Tier:** high

## Why

§9 of the specification lists GraphQL as a stretch goal "through API Platform,
reusing voters and rate limits", and that clause is the whole of the row: the
interesting part is not that API Platform can serve GraphQL, it is whether the
guarantees the REST API spent four stages establishing survive a second
protocol. §4 currently says GraphQL is disabled, and this change is what makes
that sentence out of date rather than contradicting it.

Three of those guarantees do not carry over by themselves, and each was read
from the source before this was written:

- **The report parameters are HTTP-shaped.** `ReportRequestFactory::parse()`
  reads `from`, `to`, `granularity`, `limit` and `includeBots` from
  `$request->query`, an `InputBag`. GraphQL delivers arguments in the operation
  context, not in a query string, so a report resolved through GraphQL would
  receive `null` for the request and **silently answer with the default 30-day
  period**, whatever the client asked for. A wrong number returned confidently
  is worse than an error.
- **The rate limit counts HTTP requests, not work.** `ApiRateLimitListener`
  consumes one token on authentication success of the `api` firewall — once per
  request. A GraphQL document asking for fifty reports is one request, so it
  would cost exactly what `GET /api/v1/me` costs.
- **Enabling GraphQL exposes every resource, with mutations.** Fourteen classes
  carry `#[ApiResource]`, including `UserAdmin`, `Registration` and
  `ApiKeyOutput`. Read from the installed source: a resource that declares no
  `graphQlOperations` gets the **default** set — two queries and three
  mutations, `create`, `update`, `delete`. So "leave it alone" is the opposite
  of excluding it, and every resource has to say what it exposes, the excluded
  three by declaring an empty list.

## What Changes

- **A read-only GraphQL endpoint at `/api/v1/graphql`** over three groups of
  resources, chosen by the user on 2026-09-17: links, the nine analytics
  reports, and `me`. Queries only — no mutation is exposed for anything. The
  framework also registers the unversioned `/api/graphql`; both are covered by
  the same firewall and budget, exactly as `/api/docs` lives beside `/api/v1`.
- **BREAKING for `api-docs`**: the API gains a second documented protocol, and
  the statement that GraphQL is disabled becomes false.
- **Report parameters stop being HTTP-shaped.** The factory takes a map of
  parameters; the HTTP path passes the query string, GraphQL passes its
  arguments, and both produce the same `ReportRequest` with the same validation
  and the same cache key.
- **The rate limit is charged per root selection**, not per request: a document
  asking for ten reports consumes ten tokens from the same per-identity budget
  a REST caller would spend on ten calls, counted by a stated algorithm that
  resolves root fragments, counts aliases separately and refuses a document it
  cannot price. Plus API Platform's own depth and complexity ceilings, lowered
  from their defaults and stated.
- **Admin, registration and API keys are excluded by declaring an empty
  operation list on each**, not by silence, and a test asserts the schema
  contains neither them nor any mutation type — an exclusion nobody checks is
  an exclusion that lapses, and here silence would have granted writes.
- **One new dependency**, `webonyx/graphql-php`, which API Platform's GraphQL
  support requires.

## Capabilities

### New Capabilities

- `graphql-api`: what the GraphQL endpoint exposes, what it refuses, and the
  guarantees it shares with the REST API — authorization, rate limiting,
  report parameters and error shape.

### Modified Capabilities

- `api-docs`: the API serves a documented GraphQL schema beside the OpenAPI
  document, and the "GraphQL is disabled" statement is replaced by what is
  actually exposed.

## Non-goals

- **No mutations.** Creating a link, registering, issuing an API key and
  blocking a user stay REST-only. A second write path doubles the surface where
  the interesting part of this row — that the read guarantees carry over — is
  already demonstrated by queries.
- **No admin, registration or API-key resources in the schema**, by the same
  argument and with a test.
- **No change to any REST operation.** The OpenAPI document, the error
  catalogue and the contract tests stay as they are; if a REST test changes,
  something went wrong.
- **No GraphiQL or GraphQL Playground in production.** A development IDE is a
  development IDE.
- **No subscriptions, no relay-style mutations, no custom resolvers** beyond
  what the existing providers already do.
- **No second authenticator and no second rate limiter.** A refusal the
  firewall or the limiter decides keeps its problem-details shape and its
  status, because duplicating those rules for one endpoint is a worse cost than
  two error shapes. Everything the GraphQL executor decides is GraphQL-shaped.
  The boundary is a table in the capability, not a blur.

## Impact

- **Dependency**: `webonyx/graphql-php` (through API Platform's GraphQL
  support) — the first new package since stage 3, justified by the row itself.
- **`config/packages/api_platform.yaml`**: the `graphql` block, with
  introspection, depth and complexity settled deliberately.
- **`src/Analytics/`**: the report parameter plumbing — the factory takes a map
  instead of a `Request`. The queries, the cache keys and the DTOs are
  untouched, and the REST providers keep their behaviour.
- **`src/Auth/`**: the rate-limit listener learns what a GraphQL document costs.
- **Resources**: `graphQlOperations` declared on **all fourteen** — queries on
  the eleven exposed (links, `me`, the nine reports), an explicit empty list on
  the three excluded (`UserAdmin`, `Registration`, `ApiKeyOutput`). Silence is
  not an exclusion here: it grants the default set, mutations included.
- **Security surface**: a second entry point to the same voters. That, the new
  dependency and the denial-of-service shape of an unbounded query language are
  why this is `high` rather than the roadmap's `medium`.

## User decisions

- **2026-09-17 — the surface.** Offered a narrow read-only surface (links
  only), the wider read surface (links, the nine reports and `me`), or dropping
  the row and recording why, the user chose the wider read surface.
