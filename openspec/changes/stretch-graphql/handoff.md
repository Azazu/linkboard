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

- Gate 1 round 1 (`5196a8f`, Reviewed-Commit `b191b35`): changes-requested — **two blockers and three more**, all five real, and the two blockers were factual claims about API Platform that I had backwards or unchecked. Verified each against the installed source before fixing.
  1. **The exclusion mechanism was inverted.** I wrote that leaving `graphQlOperations` undeclared keeps a resource out. Read in `OperationDefaultsTrait::addDefaultGraphQlOperations()`: a `null` declaration gets the **default** set — two queries and **three mutations**, `create`, `update`, `delete`. So "leave admin, registration and API keys alone" would have published them **with writes**, through a change whose premise is read-only. Every one of the 14 resources now declares its operations: 11 with queries, 3 with an explicit empty list. My inventory was wrong too — I said five excluded, it is three.
  2. **The path was wrong.** API Platform's entrypoint route is `/graphql`, and `config/routes/api_platform.yaml` imports with `prefix: /api`, so the flag publishes `/api/graphql` — the exact path my own task expected to 404. `route_prefix: /v1` governs resource operations, not that static route. The answer was already in this repository: `api_v1_docs` declares `/api/v1` on API Platform's documentation controller, so `api_v1_graphql` declares `/api/v1/graphql` on its entrypoint controller. Both paths answer, as `/api/docs` and `/api/v1` both answer today, and the spec says so instead of claiming one path.
  3. **"GraphQL always answers in GraphQL's shape" was false.** A missing credential is answered by the firewall as RFC 9457 401 and an exhausted budget by `ApiRateLimitListener` as RFC 9457 429 — neither reaches the executor. Forcing them into GraphQL's shape would mean a second authenticator and a second limiter for one endpoint. The contract is now stated by **where the refusal happens**, as a table: firewall and limiter keep problem details with their status; everything the executor decides is 200 with `errors`.
  4. **The counting rule was a sentence, not an algorithm.** Root fields "of the operation" says nothing about operation selection, fragments, aliases, directives, introspection or a malformed body — each a place a hand-written counter undercharges. Decision 3 now states the algorithm step by step, and section 6 tests each bypass rather than only the whole charge being removed.
  5. Six per-link reports, not nine — the other three are the global admin ones, covered by their own scenario.

## Next step
Gate 1 confirmation: `scripts/gate-run.sh stretch-graphql 1 confirm 1`. All five findings are `fixed`; `openspec validate --strict` and `scripts/pregate-verify.sh gate1` pass, 34 tasks.

## Blockers
None.
