# Design — stretch-graphql

## Context

See `proposal.md` — Why. What the design has to work with, read from the source
rather than remembered:

- `config/packages/api_platform.yaml:58` sets `graphql: enabled: false`.
  `bin/console debug:config api_platform graphql` reports the knobs that come
  with it: `introspection.enabled: true`, `max_query_depth: 20`,
  `max_query_complexity: 500`, `graphiql.enabled: false`.
- **Fourteen** classes carry `#[ApiResource]`, and a class that declares no
  `graphQlOperations` gets the **default** set — two queries and the three
  mutations `create`, `update`, `delete` (`OperationDefaultsTrait`). So the flag
  alone publishes all fourteen with writes; the surface is chosen by declaring
  operations on **all fourteen** — queries on eleven, an explicit empty list on
  three — and by a test over the schema, not by hoping.
- **The GraphQL package is not installed**: `vendor/api-platform/` holds
  thirteen directories and none is `graphql`. So nothing about the entrypoint's
  runtime behaviour — what it answers to a `GET`, for instance — can be stated
  here; it is measured in the tasks once the dependency exists, which is why
  this design claims a status for the route it declares and none for the
  framework's.
- `ApiRateLimitListener` consumes one token on `LoginSuccessEvent` of the `api`
  firewall — once per HTTP request, before access control, fail-open, with the
  accepted `RateLimit` stashed for the response headers.
- `LinkReportProvider::provide()` resolves the link, checks `LinkVoter::VIEW`,
  then calls `ReportRequestFactory::fromRequest($context['request'] …)`.
- `ReportRequestFactory::parse()` reads every parameter from `$request->query`,
  an `InputBag`, and tolerates a null request by falling back to defaults.
  **That tolerance is the bug waiting to happen**: through GraphQL there is no
  `Request` in the context, so every report would answer with the default
  period and the caller would never know.

## Goals / Non-Goals

**Goals:**

- A second protocol that cannot read anything the first would refuse.
- Report parameters that mean the same thing in both, including their refusals
  and their cache entries.
- A cost model somebody can explain in one sentence.
- An exposed surface that is a list, not a side effect.

**Non-Goals** (beyond `proposal.md` — Non-goals):

- Not a GraphQL-shaped API. The schema is what API Platform derives from the
  existing resources; no bespoke types, no renaming, no relay conventions.
- Not a performance exercise. GraphQL here is a second way to ask for cached
  reports, not a way to ask for them faster.

## Decisions

### 1. Every resource declares its GraphQL operations — including the empty list

`graphQlOperations` on `LinkResource` (`Query` + `QueryCollection`), on each of
the nine report classes (`Query`) and on `Me` (`Query`); and
**`graphQlOperations: []`** on `UserAdmin`, `Registration` and `ApiKeyOutput`.

*Leaving a resource alone does the opposite of excluding it.* Read from the
installed source rather than assumed (Gate 1 round 1, finding 1): when
`graphQlOperations` is `null`,
`MetadataCollectionFactoryTrait` calls `addDefaultGraphQlOperations()`, which
adds `Query`, `QueryCollection` **and three mutations** — `create`, `update`,
`delete`. So the flag alone would expose administration, registration and API
keys, with writes, through a change whose whole premise is read-only. The
exclusion is therefore an explicit empty list on each of the three, and the
inventory is exact: 14 resources = 11 exposed (links, `me`, nine reports) + 3
excluded.

*And the exposed ones list their queries explicitly*, for the same reason: the
default set carries mutations, so "expose this resource" has to mean "expose
these operations".

*Why a test over the schema on top of that.* An exclusion nobody checks is an
exclusion that lapses: the next resource someone adds inherits the default —
which is now known to include mutations. The test introspects the shipped
schema, asserts the set of query names equals a written-down list, asserts
there is no mutation type at all, and fails when a new type appears — so adding
a resource forces a decision rather than granting one.

*What this does not guarantee.* It is a list of names. A resource exposed under
a name the list already contains would pass; the mechanism is a gate on the
surface, not on the fields behind it.

### 2. Report parameters become a map, and HTTP becomes one adapter

`ReportRequestFactory::parse()` stops taking a `?Request` and takes an
`array<string, string|null>` of parameter values. The REST providers pass
`$request->query->all()`; the GraphQL resolvers pass their arguments. One
parser, one set of rules, one cache key.

*Why not read the args inside the factory.* Then the factory would know about
two transports and grow a third when something else calls it — the statistics
page already does, through `parse()`. A map is the thing all three have.

*Why this is the riskiest edit in the change.* It touches the code path of every
report the REST API serves. The mitigation is that the REST tests are not
allowed to change: `tests/Api/Analytics` passes untouched, and that is a task.

*What this does not guarantee.* It fixes the parameters, not the semantics: a
GraphQL client can still ask for a period the REST client would not, and gets
the same refusal.

### 3. The rate limit charges per root field, by a stated algorithm

The listener keeps its one token per request for every REST call. For a request
to a GraphQL path it charges the number of **root selections** of the executed
operation. The algorithm, because a hand-written counter is exactly where a
bypass hides (Gate 1 round 1, finding 4):

1. The body must be a JSON object with a string `query`; `variables`, when
   present and not null, must be a JSON **object** — a JSON list is not one,
   `[]` included. Anything else is **refused** without consuming a token and
   without resolving anything. The body is therefore decoded **as objects**,
   not as associative arrays: with `true` as `json_decode`'s second argument
   `{"n":1}` and `[1]` are both PHP arrays, which is how a list first passed
   this check (Gate 2 round 1, finding 2) — and `{}` and `[]` become the *same*
   empty array, so no later test can tell them apart, which is how the first
   fix still accepted an empty list (Gate 2 confirmation 1, finding 2). A
   `\stdClass` check keeps the distinction the wire format made.
2. The document is parsed with the same parser that will execute it
   (`GraphQL\Language\Parser`). A parse error is refused.
3. The operation is selected: `operationName` when given — and an
   `operationName` matching **no** definition is refused, not silently ignored;
   otherwise the single operation definition in the document. **No operation, or
   more than one without `operationName`, is refused** — GraphQL itself refuses
   these, and charging for an ambiguous document would charge for work nobody
   asked for.
4. The cost is the number of selections in that operation's root selection set,
   counted **after resolving both named spreads and inline fragments at the
   root** — each contributes its own root selections, and a fragment cycle is
   refused rather than followed, because a counter that recurses for ever is a
   denial of service written by the defence. **Aliases count separately** (two
   aliases of one field are two reads), two selections sharing one response key
   count **twice** (the price is per selection asked for, not per key returned),
   and `@skip`/`@include` are **not** evaluated — a directive decided at
   execution time cannot lower a price charged before execution.
5. A document whose root selections are all introspection (`__schema`,
   `__type`, `__typename`) costs **one**, whatever their number: introspection
   reads the schema, not the database.
6. Counting is **memoised per fragment and saturating**: each fragment is priced
   once, and the total stops at `MAX_TOKENS + 1`, above which the document is
   refused rather than priced. Without this, a linear acyclic document whose
   `F0` spreads `F1` twice, `F1` spreads `F2` twice and so on visits 2^n
   selections — a denial of service in the counter itself, reached before any
   ceiling of decision 4 can act, and invisible to the cycle guard because the
   shape is acyclic (Gate 2 round 1, finding 1).
7. **Every walk over the document obeys the same rule, not just the counting
   one.** Deciding whether a document is introspection-only is a second walk,
   and it only traverses the whole expansion when every leaf *is* introspection
   — so the identical hostile shape ending in `__typename` moved the cost one
   method along (Gate 2 confirmation 1, finding 1). Two independent guards now:
   the ceiling is applied **before** that walk, so a document refused for its
   size is never walked again, and the walk memoises per fragment so it is
   bounded even if somebody reorders the checks. Measured, each alone is
   sufficient and neither alone was there: with both removed the 40-fragment
   introspection document did not finish in five minutes.

*Why root selections.* One root selection is one logical read: `{ link(id:…)
{…} linkSummaryReport(…) {…} }` is two reads and costs two, which is exactly
what the same two REST calls cost. A rule that fits in a sentence matters more
here than precision — a cost model nobody can explain is a cost model nobody
maintains.

*Why not complexity-proportional tokens.* Complexity is already bounded by
decision 4; making the budget depend on it too would mean one number governing
two unrelated things, and a caller unable to predict what a query costs.

*What this does not guarantee.* A single root selection can still be expensive —
that is what the complexity ceiling is for. And the limiter stays **fail-open**
on a storage failure, as it is for REST: consistency with the existing policy,
stated because it is a real limit.

*Fail-open has one deliberate exception.* `RateLimiterInterface::consume($n)`
throws `InvalidArgumentException` when `$n` is larger than the limiter's whole
window — a document asking for more reads than the budget could ever grant.
That is the caller's document being wrong, not the store being unavailable, so
it is caught separately from the fail-open branch and answered **429**. Left in
the fail-open branch it did the opposite of what it looks like: the largest
documents were the ones that went through unpriced (Gate 2 round 1, finding 3).

### 3a. The endpoint's path, and the one this repository already solved

API Platform registers its entrypoint as the static route `/graphql`, and
`config/routes/api_platform.yaml` imports the collection with `prefix: /api` —
so enabling the flag publishes **`/api/graphql`**. The `route_prefix: /v1`
default applies to resource operations, not to that route (Gate 1 round 1,
finding 2; read from `ApiLoader` and `routing/graphql/graphql.php`).

This repository has met this before and answered it: `/api/docs` is API
Platform's own path, and `config/routes.yaml` declares `api_v1_docs` at
`/api/v1` pointing at the same public controller. GraphQL follows that
precedent exactly — a declared route `api_v1_graphql` at `/api/v1/graphql` on
`api_platform.graphql.action.entrypoint`.

*So both paths answer*, `/api/graphql` and `/api/v1/graphql`, exactly as both
`/api/docs` and `/api/v1` answer today. The versioned one is the documented one;
the unversioned one is API Platform's, kept rather than fought. The firewall and
the rate limiter cover **both** — a path that authenticates differently from its
alias is the bug this note exists to prevent.

*The declared route restricts its methods; the framework's is not ours to
describe.* `api_v1_graphql` declares `methods: [POST]`, so a `GET` there is 405 —
a contract this change owns and tests. API Platform's own route declares no
method restriction (read in `routing/graphql/graphql.php`), and what its action
does with a `GET` cannot be stated here because the package is not installed
yet: it is **measured and recorded** in the tasks once it is, rather than
asserted now (Gate 1 confirmation 1, finding 2). The specification therefore
promises a status only for the path this change declares.

### 4. Introspection on, GraphiQL off, depth and complexity lowered

Introspection stays enabled: this API publishes its OpenAPI document, and a
schema a client cannot discover is a worse answer than one it can. GraphiQL and
Playground stay off in every environment — a development IDE mounted in
production is a surface with no owner.

`max_query_depth` drops from 20 to **10** and `max_query_complexity` from 500 to
**200**. The deepest legitimate query here is a link's collection with its
reports — far under ten — so the ceiling is chosen against what the schema can
usefully express rather than against the framework's default.

*What this does not guarantee.* Depth and complexity are static bounds; they do
not know that one report scans a month. The per-identity budget of decision 3 is
what bounds the total, and the report cache is what makes repetition cheap.

### 5. Two error shapes, and the boundary is *where the refusal happens*

The blanket claim "GraphQL always answers in GraphQL's shape" was false, and the
review caught it (Gate 1 round 1, finding 3). Three refusals never reach the
GraphQL executor at all, because they happen before any controller runs:

- **no credential or a bad one** — the `api` firewall's entry point and access
  denied handler answer RFC 9457 401/403;
- **over the rate budget** — `ApiRateLimitListener` sets an RFC 9457 429 on
  `LoginSuccessEvent`, before access control;
- **a malformed body or an unselectable operation** — refused by the listener of
  decision 3, in the same place and therefore in the same shape.

So the contract is stated by *where*, not by *whether*:

| Refusal | Answered by | Shape |
|---|---|---|
| Credential missing, invalid, or blocked | the firewall | RFC 9457, status 401/403 |
| Over the per-identity budget | the rate-limit listener | RFC 9457, status 429 |
| Malformed body, unparseable or ambiguous document | the rate-limit listener | RFC 9457, status 400 |
| Depth or complexity exceeded | the GraphQL executor | 200 with `errors` |
| A voter refusing a resource | the GraphQL executor | 200 with `errors`, `data` null for that field |
| A parameter refused | the GraphQL executor | 200 with `errors` |
| An unexpected internal failure | the GraphQL executor | 200 with `errors`, message generic outside `dev` |

*Why not force the first three into GraphQL's shape.* It would mean a second
authenticator and a second limiter path for one endpoint — two implementations
of rules this project has spent four stages proving are singular. The cost of
the split is that a GraphQL client meets two shapes; the cost of avoiding it is
two copies of the authentication and rate-limit logic. The split is cheaper and
it is what the documentation now says, in the capability and in
`docs/reference/api-errors.md`.

*Not leaking internals.* API Platform ships `RuntimeExceptionNormalizer`, which
copies the message of any `\RuntimeException` into `errors[].message` whatever
the debug flag says — and a Doctrine failure inside a resolver arrives as
exactly that, carrying the SQL it failed on. `App\Shared\Api\GraphQlErrorBoundary`
decorates `api_platform.graphql.error_handler` and, outside `dev`, answers one
fixed message for every error the application did not mean for the client,
logging the real one instead. What still reaches the caller is what describes
its own request: an error graphql-php itself raised (no previous exception), a
status this API chose (`HttpExceptionInterface`), a refused parameter
(validation), an exception that declares itself client-safe — plus `locations`
and `path`, which point into the document the caller sent. This is the same
promise the REST API's error handling already makes; it was **not** true before
this change, and the test that found it is the one that asserts it.

### 6. What "the same voters" actually rests on

Nothing new. The providers that GraphQL resolves through are the same classes:
`LinkReportProvider` calls `Security::isGranted(LinkVoter::VIEW, …)` before it
reads anything, and the collection provider filters by owner. The credential
requirement is the firewall's, matched by path.

*So the work is proving it, not building it.* The tests query as a stranger, as
an anonymous caller and as a non-admin, and assert that the answer carries no
data. The two refusals stay **distinguishable**: a stranger's link answers
`Access Denied`, an identifier no link has answers `No such link`. That is the
REST contract this surface mirrors — [ADR-005](../../../docs/adr/ADR-005-pages-answer-404.md)
made the *pages* answer 404 to both and deliberately left the API at 403/404,
and GraphQL is the API.

## Applicability

| Question | Answer |
|---|---|
| Authorization boundary | The whole point. A second entry point to the same voters, proven by tests from three angles (stranger, anonymous, non-admin) rather than by the claim that the providers are shared. `security.yaml` is not edited at all: the `api` firewall already matches `^/api(/|$)`, so both entrypoint paths are behind it, and `app.api.graphql_paths` names them together for the rate limiter. |
| Empty/zero/null inputs | Two places. A report queried with no arguments must use the documented defaults — the same ones the REST call uses, not whatever the factory's null-tolerance produces (decision 2). And an empty document, a document with no operation, and a `variables` object that is not an object must be refused rather than resolved as nothing. |
| Crash before/after an external effect | n/a for writes — the schema has no mutation. A resolver that throws mid-document leaves the other fields' results in `data` with an entry in `errors`, which is GraphQL's own contract and is stated in the capability rather than discovered. |
| Idempotency of retries | Queries are reads; retrying one costs another token, which is the intended cost. |
| Concurrent writers | n/a — nothing writes. |
| Deletion/expiry | n/a — nothing deletes. The report cache's TTL is unchanged and shared with REST (decision 2). |
| Money rounding | n/a. |

## Risks / Trade-offs

- **A new dependency for a stretch row** → `webonyx/graphql-php` arrives only
  through API Platform's own GraphQL support; no second library, no custom
  server, and the proposal justifies it against the row rather than against
  convenience.
- **The parameter refactor touches every report** → the REST suites are the
  guard and they are not allowed to change; if one does, the refactor is wrong.
- **Two error formats** → accepted, bounded by a path, and documented where a
  reader meets errors.
- **A schema list can go stale** → the test compares against a written list and
  fails on anything new, so it goes stale loudly.
- **GraphQL invites clients to ask for more than they need** → depth,
  complexity and the per-field budget; and the reports are cached, so the
  expensive thing is already the cheap thing on the second ask.
