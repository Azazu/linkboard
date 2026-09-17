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

- Artifacts written and `openspec validate --strict` passes: proposal (tier `high` argued from the new dependency, the second authorization entry point and the denial-of-service shape of a query language), two capability deltas (`graphql-api` new, `api-docs` modified), design (six decisions plus the applicability table), tasks (31 across nine sections).
- **The decision that shaped everything, found by reading rather than at Gate 2**: `ReportRequestFactory::parse()` reads every report parameter from `$request->query` and *tolerates a null request by falling back to defaults*. Through GraphQL there is no `Request` in the context, so every report would have answered with the default 30-day period and the caller would never have known. The factory takes a map of parameters instead; HTTP passes the query string, GraphQL passes its arguments, and the REST suites are the guard on the refactor — they must pass with no edit at all.
- **The rate limit does not carry over by itself either**: `ApiRateLimitListener` consumes one token per HTTP request, so a document asking for fifty reports would cost what `GET /api/v1/me` costs. The rule chosen is one token per root field — one root field is one logical read, which is exactly what the same REST calls would cost, and it fits in a sentence.
- **The surface is a list, not a side effect**: `graphQlOperations` on links, the nine reports and `me`; nothing on admin, registration or API keys; and a test that asserts the schema's query names **equal** a written-down list, so a new resource fails it rather than joining it. Its own failing input is a temporary declaration on `UserAdmin`.
- **User decision (2026-09-17)**: offered a narrow read surface (links only), the wider read surface, or dropping the row with the reasons recorded, the user chose the wider one — links, the nine reports and `me`, read-only.

## Next step
Gate 1: `scripts/gate-run.sh stretch-graphql 1 full` — tier `high`, so the artifacts are reviewed before any implementation. `scripts/pregate-verify.sh gate1` passes.

## Blockers
None.
