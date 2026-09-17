# Handoff — stretch-graphql

**Updated:** 2026-09-17 · claude
**State:** proposing
**Branch:** change/stretch-graphql

## Done this session
- Branch `change/stretch-graphql` created from `main` (`8d69404`, the archive of `stretch-partition-clicks`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 15 and §9 of `docs/explanation/requirements.md`: a GraphQL endpoint through API Platform, **reusing the voters and the rate limits** — that clause is the whole point of the row, and the thing a reviewer will check first.

## Measured before proposing, not assumed
- `config/packages/api_platform.yaml:58` sets `graphql: enabled: false`, with the comment "Not part of the specification; off to keep the surface minimal". §4 of the brief says the same: "JSON-LD/Hydra and GraphQL are disabled (GraphQL is stretch)". So this change flips a switch the specification deliberately left off, and both statements have to be corrected rather than contradicted.
- **No GraphQL package is installed** (`composer show | grep graphql` is empty): API Platform's GraphQL support needs `webonyx/graphql-php`. That is a new dependency, and the anti-overengineering rule means the proposal has to justify it against the row's own value rather than against "API Platform can do it".
- **14 API resources** carry `#[ApiResource]`: `LinkResource`, `Me`, `Registration`, `ApiKeyOutput`, `UserAdmin` and the nine analytics reports. Enabling GraphQL exposes *all* of them unless each operation is chosen deliberately — which is the risk this change is really about.

## Next step
`/opsx:propose stretch-graphql`. Four things the proposal must settle, because they decide what is built:

1. **How much surface.** GraphQL on every resource, or a named subset? The nine analytics reports are parameterised read models with cache keys derived from their parameters (`ReportRequest::cacheKey()`); exposing them through a query language that composes parameters freely is not the same operation as the REST one, and the report cache is the place that would notice.
2. **Whether the guarantees actually carry over.** "The same voters and rate limits" is easy to say. The limiters are wired per route and per credential in `config/packages/framework.yaml`; one GraphQL endpoint is one route, so a naive setup makes a thousand-field query cost the same as `GET /api/v1/me`. Whatever the answer, it needs a failing input.
3. **The error format.** The API has one error contract — RFC 9457 problem details, with a catalogue and contract tests. GraphQL has its own error shape by specification. Two error formats in one API is a real cost and the proposal has to name which wins where.
4. **Introspection and depth.** A public GraphQL endpoint with introspection on and no depth or complexity limit is a denial-of-service surface; with them off it is harder to justify at all. This is the part that argues the tier up from the roadmap's `medium`.

## Blockers
None.
