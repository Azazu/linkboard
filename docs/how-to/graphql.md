# Querying the API with GraphQL

The same data as `/api/v1`, asked for in one request instead of several — and
under the same rules: the same credential, the same voters, the same
per-identity budget. Read-only: the schema has no mutation at all, so creating
a link, registering and issuing an API key stay REST's.

Decided in the change `stretch-graphql`; what it exposes and refuses is the
capability `openspec/specs/graphql-api/spec.md`.

## The endpoint

```
POST /api/v1/graphql
Content-Type: application/json
Authorization: Bearer <token>
```

`POST` only — a `GET` there is 405. The framework registers an unversioned
`/api/graphql` as well, the way `/api/docs` sits beside `/api/v1`; both are
behind the same firewall and the same budget, and the versioned one is the
documented path.

## A worked example

Get a token the same way any API client does, then ask:

```bash
TOKEN=$(curl -sS -X POST http://localhost:8082/api/v1/auth/token \
    -H 'Content-Type: application/json' \
    --data '{"email":"demo@example.com","password":"<the seed printed it>"}' \
    | php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"];')

curl -sS -X POST http://localhost:8082/api/v1/graphql \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $TOKEN" \
    --data '{"query":"{ me { email roles } links(first: 2) { edges { node { slug clickCount } } } }"}'
```

```json
{"data":{"me":{"email":"demo@example.com","roles":["ROLE_USER"]},"links":{"edges":[{"node":{"slug":"beta-signup","clickCount":3}},{"node":{"slug":"support","clickCount":2}}]}}}
```

A report takes the parameters its REST operation takes, by the same names and
the same rules:

```bash
curl -sS -X POST http://localhost:8082/api/v1/graphql \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $TOKEN" \
    --data '{"query":"{ linkSummaryReport(id: \"/api/v1/links/<id>/stats/summary\", from: \"2026-09-14T00:00:00Z\", to: \"2026-09-17T00:00:00Z\") { clicksInPeriod from } }"}'
```

The identifier is the REST path of the same resource — API Platform's IRI — so
a client that already talks to this API has it.

## What a query costs

**One token of your per-identity budget for each root selection**, the same
budget a REST call spends one token of. Asking for ten reports in one document
costs ten, exactly what ten REST calls would; the response carries
`X-RateLimit-Remaining` as every API response does.

Counted from the document as parsed, so: fragments at the root contribute what
they name, two aliases of one field are two reads, `@skip(if: true)` does not
make a selection free — the price is charged before the directive is evaluated
— and a document of nothing but introspection costs one, because introspection
reads the schema rather than the database.

A document that cannot be priced is refused before a token is spent: a body
that is not a JSON object, a `query` that is not a string, `variables` that is
not a JSON object (a list is not one, `[]` included — send `{}` for an empty
variable set), a document that does not parse, one with no operation, one with
several and no `operationName`, one whose root fragments form a cycle, and one
asking for more than 1000 reads — past that ceiling the price is not computed,
since no budget could cover it. A document that asks for more than your whole
window, but stays under the ceiling, is a 429 with a `Retry-After` instead, and
so is one asking for more than you have left right now.

## What the schema will not do

- **Depth above 10** and **complexity above 200** are refused before execution.
- **No mutations**, for anything.
- **No administration, registration or API keys** — those resources declare an
  empty GraphQL operation list, which is what keeps them out. Silence would not:
  a resource that declares nothing receives the framework's default set,
  mutations included.

## Two error shapes

A refusal the firewall or the rate limiter decides keeps its own status and an
`application/problem+json` body — 401, 403, 429, 400. A refusal the GraphQL
executor decides is a 200 with an `errors` array, which is what the GraphQL
specification requires. The table in
[`docs/reference/api-errors.md`](../reference/api-errors.md) says which is
which and why they were not made uniform.
