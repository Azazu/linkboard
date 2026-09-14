## Context

See `proposal.md` — Why. What matters for the approach is what already exists and must not be duplicated.

- The nine reports (six per link, three global) are computed by `src/Analytics/Query/*` and assembled into report objects by two API Platform providers, `LinkReportProvider` and `AdminStatsProvider`. Each provider parses the query string through `ReportRequestFactory`, then calls `ReportCache::remember($request->cacheKey($name), [$request->cacheTag()], …)`. The cache key and tag, the 300-second time-to-live and the invalidation on link write are the `analytics` capability's contract, tested by `tests/Api/Analytics` and `tests/Integration/Analytics`.
- `ReportRequestFactory::fromRequest()` already raises `InvalidReportParameter` from the parsing (`Period::of`, `Granularity`, `limit`, `includeBots`) and converts it into API Platform's `ValidationException`, which the error normalizer renders as 422 problem details.
- Blocking an account lives entirely inside `BlockUserProcessor`: the self-block guard, the state change, the flush and the audit line. `UnblockUserProcessor` is its inverse.
- The web layer from row 11 supplies what the new pages build on: `LinkPages::findGranted()` (voter on the `LinkResource`, 404 on denial), the list-and-paginate shape of `LinkListController`, the confirmation-page pattern that Gate 2 of row 11 required for destructive actions, `ChartBuilderInterface` for charts under the content security policy, and the `(/|$)` lesson in the access-control patterns.
- `admin` is already in `ReservedSlugs::LIST`, so no existing link can own the `/admin` segment; `admin-sale` can and must keep redirecting.

## Goals / Non-Goals

**Goals**

- One computation per report, reached from two presenters, sharing one cache entry — so the page and the API can never disagree about a number.
- One implementation of an account action, reached from a processor and from a form.
- The pages' authorization decisions taken by the mechanisms that already own them (the link voter, the role), not restated.
- Every figure a chart draws also present as text (the no-JavaScript requirement).

**Non-Goals** (beyond the proposal's)

- Changing any cache key, tag, time-to-live or invalidation rule. A payload or key change would make the two presenters' entries disjoint, which is the opposite of the goal.
- A page-level cache in front of the reports: the report cache is the one cache, and its staleness is already stated on the page.
- Making the report objects independent of API Platform's metadata (see decision 1's alternatives).

## Decisions

### 1. The report services return the objects the API already answers

`src/Analytics/Report/LinkReports.php` and `src/Analytics/Report/GlobalReports.php` take a `ReportRequest` and return the same report object the corresponding API operation returns today, through the same `ReportCache::remember` call with the same key and tag. The two providers keep only what is API Platform's: turning an `Operation` and a `Request` into a report request, and returning the object. The pages call the services directly.

*Why:* one cached entry serves both presenters, so a page and the API answering the same parameters return the same numbers and the same `generatedAt` — the property the `analytics` capability's cache requirement states, now true across presenters as well.

*Alternatives.* (a) The page computes through the queries itself: two code paths to the same SQL, two cache entries or none, and the first place a number could drift. Rejected. (b) The shared service returns the query DTOs and each presenter assembles its own report object: the cached payload would have to change shape, and `generatedAt` would have to be cached beside it — a key and payload change to a contract that is under test, for a namespace boundary. Rejected. (c) Move the nine report classes out of `src/Analytics/Api/` into `src/Analytics/Report/`: a rename of nine classes and every reference, with no behavioural gain, inflating a diff that a reviewer has to read. Rejected.

**Each report normalizes the request to the parameters it actually uses.** `ReportRequest::cacheKey()` digests the period, the granularity and the limit for *every* report, while today the parameters that reach it are normalized by the provider's flags: granularity is parsed only for a timeseries (otherwise it stays `day`), the limit only for the breakdowns that take one (otherwise the default), and the admin summary ignores the period entirely (`Period::defaults`). A page has one set of controls feeding nine reports, so handing the reader's request unchanged to every service method would produce a different key from the API's for eight of them — the same numbers computed twice and cached twice, which is precisely what this decision exists to prevent. The normalization therefore moves out of the providers' flags and into the services: each report method reduces the request it is given to the parameters that report uses, and keys and echoes the reduced one. The providers stop deciding it, so the two presenters cannot diverge; the API's keys, echoes and payloads are unchanged, because the reduction reproduces exactly what the flags did.

*What this does not guarantee:* the web pages name classes in the `Analytics\Api` namespace. That is the honest cost of the decision — those classes are the read model's output shape and API Platform's attributes are one presenter's metadata on them. It is recorded here rather than hidden behind a second DTO that would have to be kept in step.

### 2. Parsing is shared; rendering a refused parameter is each presenter's own

`ReportRequestFactory` gains a method that parses and lets `InvalidReportParameter` escape; `fromRequest()` keeps wrapping it into `ValidationException` so the API's 422 is untouched. The pages catch `InvalidReportParameter` and attach its message to the form control named by its `parameter` property.

*Why:* the rules about periods, granularity bounds and the bots flag stay in one place; only the rendering differs. The exception already carries the parameter name, which is exactly what a form needs to place the message.

*What this does not guarantee:* the page's messages are the report layer's wording, not a form constraint's — they are written for a reader and are the same words the API returns. One further asymmetry is deliberate: the API ignores `from`/`to` on the admin summary and therefore never refuses a malformed one there, while the global page parses the period once for the three reports it shows and refuses a malformed value for the whole page. The summary is still computed over the defaults, as decision 1's normalization requires.

### 3. A refused parameter re-renders the page with 422 and no figures

When a parameter is refused the page renders with the controls carrying what was submitted, the message on the offending control, and the report sections replaced by one line saying the figures are not shown until the parameters are corrected. The status is 422.

*Why 422 rather than 200:* the page and the API then give the same class of answer to the same refusal, and 422 is the status Turbo re-renders in place. *Why not a redirect to the defaults:* it would silently discard what the reader typed, and the address would no longer describe what is shown.

*What this does not guarantee:* a refused parameter is not a 400 — the page itself was served; it is the parameters that were refused.

### 4. Two authorization boundaries, each asked of the mechanism that owns it

`/links/{id}/stats` asks `LinkPages::findGranted($id, LinkVoter::VIEW)` — the same voter and the same attribute the API's report provider asks, answering 404 on denial as every other link page does. The administrative pages are guarded twice: `access_control` on `^/admin(/|$)` with `ROLE_ADMIN` as the coarse net, and `#[IsGranted(User::ROLE_ADMIN)]` on each controller, the same doubling row 11 used for `ROLE_USER`.

*Why the segment boundary:* an access-control path is an unanchored regular expression. `^/admin` alone would also match `/admin-sale`, a valid slug, and send a public redirect to the login page — the defect Gate 1 of row 11 found on `^/(dashboard|links|api-keys)`.

*What this does not guarantee:* the API keeps answering 403 where the pages answer 404. That asymmetry is row 11's decision and is not revisited: an API client is told it lacks permission, a browser is told there is no such page.

### 5. Blocking an account becomes a use case with the processors as adapters

`src/Auth/UseCase/BlockUser.php` and `UnblockUser.php` hold the self-block guard, the state change, the flush and the audit line, in that order. The guard raises a domain exception; `BlockUserProcessor` translates it into the `ValidationException` the API answers today, and the page turns it into a message on the confirmation page. `tests/Api/**` is the net for the API's behaviour and is not edited.

*Why:* a form has no JSON body and no `Operation` to hand a processor — the same reason row 11 moved link writes into `src/Link/UseCase/`. Duplicating the guard would put an authorization rule in two places, which is the defect class this process exists to prevent.

*What this does not guarantee:* the audit line is written after the flush, so a crash between them leaves a blocked account with no audit record. That is today's behaviour and is kept deliberately — a line written before a write that then fails would be worse, because it would claim something that did not happen.

### 6. The administrative pages reuse the owner pages' query shapes

`/admin/links` uses `LinkRepositoryInterface::findPage()`/`count()` with the same `LinkListQuery` the owner's list builds; the parsing of the filter and ordering query string moves into one helper that both list controllers call. `/admin/users` uses `UserRepositoryInterface::findPage()`/`count()`. Owners' addresses on `/admin/links` come from one batch lookup of the identifiers on the page, never a lookup per row.

*Why:* the filters behave identically on both pages because they are the same code, and the layout rule forbids a lazy-loading surprise in a loop.

### 7. Charts are an enhancement over tables that are always there

Every chart on the two statistics pages is built with `ChartBuilderInterface`, as the dashboard's is, and every series it draws is also rendered as a table row. No inline style or script is introduced: the row 11 acceptance run showed that an inline `style` attribute violates the policy, so anything visual is a class in `assets/styles/app.css`.

This also settles the dashboard, whose daily series is today drawn only as a chart: it gains the same table, so the strengthened requirement is true everywhere rather than true on the new pages and false on the old one.

*What this does not guarantee:* with scripting off the reader loses the picture, not the numbers.

## Applicability

| Question | Answer |
|---|---|
| Crash before/after an external effect | Blocking flushes, then writes the audit line: a crash between them leaves the state changed and unaudited (decision 5). Reports have no external effect — a cache write that fails is logged and the report is answered anyway. |
| Concurrent writers | Two administrators acting on the same account write an absolute state (blocked / not blocked), not a relative one, so the later write wins and no update is lost. A block concurrent with the target's own request is decided by the authenticator on each request, unchanged. |
| Authorization boundary | Two: the link voter's `LINK_VIEW` for a link's statistics (404 on denial), and `ROLE_ADMIN` for `/admin/*` (403 for a signed-in non-admin, login for a guest). Both are asked of existing mechanisms (decision 4), and both are exercised with owner, admin, stranger and guest — the role boundary on the account-changing submissions as well as on the pages, including a non-admin submission carrying a token its own session would accept, so a passing forgery check can never stand in for a missing role check. |
| Empty / zero / null inputs | A link with no clicks at all, a link whose clicks all fall outside the selected period (period figures zero, all-time figures intact — the distinction the `analytics` summary draws), a period with no buckets, an empty top-links list, and the `null` groups the breakdowns define (unknown country, unrecognised device or operating system, `direct` referrer) all render as data, not as errors. An account list with one page and a link list with none are covered. |
| Deletion / expiry | Nothing is deleted here. A deleted link's statistics page answers 404 like its other pages; an inactive or expired link keeps its statistics, as the `analytics` capability requires. |
| Idempotency of retries | Blocking an already blocked account and unblocking an unblocked one change nothing and answer the same — the API's behaviour, now shared. A resubmitted confirmation is therefore safe. |
| Money rounding | n/a — no monetary value exists in this project. |

## Risks / Trade-offs

- **The two refactors touch merged, reviewed code** → the existing `tests/Api/Analytics`, `tests/Api/Admin` and `tests/Integration/Analytics` suites are the regression net and are not edited; a task states that explicitly, as row 11 did for `tests/Api/Link`.
- **A page that renders nine reports could become the slowest page in the project** → every report is served from the same cache entry the API uses, and the page issues no query of its own beyond the link lookup; the tables are rendered from the report objects. The first request after an invalidation computes all of them, and that cost is the API's existing cost, measured by the analytics change's `EXPLAIN` work.
- **The web layer naming `Analytics\Api` classes** → recorded in decision 1 with the alternatives that were weighed; the reviewer should judge it as a deliberate trade, not an oversight.
- **A forgery check that looks like an authorization check** → the two guards are separated in the tests: a non-admin submission with a valid token must be refused by the role, and a missing token must be refused on a request that would otherwise be authorized. Each has its own demonstrated failing input.
- **A `/admin` prefix is a new public surface** → guarded twice (decision 4), with a prefix-slug regression test of the same shape row 11 added for its own pages.
- **Charts drawn from figures a reader cannot check** → every charted series is also a table (decision 7), which is also what makes the page usable without JavaScript.

## Migration Plan

No migration: no schema change, no data change, no change to any API route, payload or status code. The access-control line and the new routes take effect on deploy; rolling back is reverting the merge. Cache entries keep their keys and shapes, so a deploy neither invalidates nor reinterprets what is already cached.
