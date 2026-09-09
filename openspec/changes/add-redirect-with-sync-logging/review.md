# Review — add-redirect-with-sync-logging

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 6e59b75bb617ada03da5ec51b92935f2a93b041f
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | design.md decision 6; tasks.md 2.1; specs/redirect/spec.md — Destination with UTM appended | `parse_str` followed by `http_build_query` does not preserve arbitrary destination query parameters. For example, `https://example.com/p?tag=a&tag=b&a.b=1` with `utm_source=news` loses the first `tag` and changes `a.b` to `a_b`. These are valid target queries under the current `TargetUrlPolicy`; adding tracking can therefore change the destination's behavior. Specify an algorithm that preserves unrelated query components and replaces only the intended UTM keys, including the treatment of repeated and encoded keys. Add regression scenarios and verification tasks for duplicate keys, dotted keys, bracket notation, and encoded values. | fixed |
| 2 | major | design.md decision 1; config/packages/security.yaml api firewall; tasks.md 2.3 and 3.1 | The claim that every valid slug remains under the lazy `main` firewall is false: the existing API firewall matches `^/api`, while `ReservedSlugs` excludes exact words only. A valid slug such as `api-promo` matches the redirect route but runs through JWT authentication; a request carrying an invalid bearer token can receive 401 before the redirect controller, instead of the public redirect matrix. Plan an explicit firewall boundary correction that keeps valid slug requests outside the API firewall, and cover an `api`-prefixed slug with absent and invalid credentials while retaining protection of actual API routes. Reconcile proposal impact and tasks with that security configuration change. | fixed |
| 3 | major | design.md Goals / Non-Goals and decisions 5, 9; tasks.md 3.3 | The design says there is no Redis on the hot path, but the first controller operation uses both Redis-backed limiter storage and the Redis lock factory. The installed `SlidingWindowLimiter::reserve()` acquires the lock before reading storage and does not convert storage/lock failures into a rate-limit result. A Redis outage can thus fail even an unlimited redirect before the recorder's catch block, contrary to NFR-REL-1; the throwing-recorder test cannot exercise this path. Specify the limiter outage policy, its guarantee boundary, safe logging and response behavior, and add failing-dependency coverage for limiter creation/consumption and lock/storage failure. Update the no-Redis claim throughout the artifacts. | fixed |
| 4 | major | design.md decisions 7, 8, 11; specs/click-logging/spec.md — Untrusted header bounds; tasks.md 2.1 and 3.2 | Truncating the whole Referer to 2048 bytes does not make its extracted host safe for `referer_host varchar(255)`. For example, `https://` followed by 300 ASCII `a` characters and `/` remains within the header bound and yields an overlong host with `parse_url`; the INSERT then fails, causing 503 for a limited link or losing the required click for an unlimited link. Malformed byte sequences can likewise be unsuitable for PostgreSQL text. Define validation/normalization of the extracted host against the column's length and encoding constraints, with invalid input becoming null, and add hostile-header tests proving a 302 and a persisted click for both limited and unlimited links. | fixed |
| 5 | major | specs/click-logging/spec.md — One click per successful redirect; specs/redirect/spec.md — Recording failure never fails an unlimited redirect and HEAD scenario; design.md decision 5 | The observable contracts are mutually inconsistent. The click spec requires every GET 302 to persist exactly one click, while the redirect spec requires an unlimited GET to return 302 when persistence fails. HEAD is also required to have the same status as GET, although the planned HEAD bypass returns 302 when a limited GET would return 503 on recorder failure. The design additionally acknowledges a committed click without a delivered response after a crash. State the normal-operation assumptions and explicit failure/crash/HEAD exceptions in the normative specs, and add corresponding verification coverage so implementation and later async migration have one achievable contract. | fixed |
| 6 | major | design.md decisions 2, 5 and Risks / Trade-offs; specs/redirect/spec.md — Recording failure scenarios; tasks.md 3.3 | A real outage of the click store is not equivalent to the throwing-recorder test in this baseline: links and clicks share PostgreSQL, and `findBySlug` executes outside the catch before `record()`. If PostgreSQL is unavailable when the request begins, the resolver cannot obtain the target or determine whether the link is limited, so the specified 302/503 recording-failure scenarios cannot be guaranteed by this mechanism. Bound those scenarios explicitly to write failures after successful resolution, define the response for link-lookup failure, and test lookup failure separately. If availability during a total database outage is intended, provide a mechanism that can resolve links during that outage rather than claiming the recorder stub proves it. | fixed |

### Validation

- Confirmed the current branch is `change/add-redirect-with-sync-logging` and HEAD is the reviewed commit above; the working tree was clean before this record.
- Read the proposal, design, tasks, delta specs, handoff, roadmap, OpenSpec configuration, applicable requirements, and the existing routing/security, URL-validation and limiter implementation context.
- `scripts/pregate-verify.sh gate1 add-redirect-with-sync-logging` passed, including strict OpenSpec validation, with zero warnings. These mechanical checks do not resolve the design findings above.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** f207eb17fd24ee9e6b01aef3801937d7f6657cc7
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Decision 6 now preserves unrelated raw query pairs and removes every occurrence of the intended percent-decoded UTM keys. The redirect spec and task 2.1 cover repeated, dotted, bracketed and encoded components, including a regression that fails with the old map-based algorithm. |
| 2 | confirmed — Decision 1, proposal impact and task 2.3 explicitly narrow the API firewall to `^/api(/\|$)`. The public-slug scenario and task 3.1 cover `api-promo` with absent and invalid credentials and retain a 401 assertion for the actual API route. |
| 3 | changes-requested — The fail-open policy, Redis dependency boundary and safe warning log are now specified, but decision 9 and task 3.3(c) only plan a throwing factory. That does not exercise successful creation followed by a failure during `consume()`, where the installed `SlidingWindowLimiter` acquires its lock and reads/writes storage. A catch surrounding only `create()` would pass the planned failure test while leaving the original outage path unprotected. Add explicit verification tasks using successful creation with failing lock acquisition and failing storage during consumption; assert the normal redirect response and a warning without the IP, and demonstrate failure when consumption is outside the catch. Retain the creation-failure case. This is the outstanding failing-dependency coverage requested by finding 3. |
| 4 | confirmed — Decision 7 and the header-bounds requirement constrain the extracted host to 255 bytes, valid UTF-8 and no controls, with invalid input becoming null. Tasks 2.1 and 3.2 cover column boundaries and hostile hosts with a persisted click and 302 for both limited and unlimited links. |
| 5 | confirmed — The normative specs now state the unlimited write-failure exception, HEAD's independence from click writes and the commit-to-response crash window. Task 2.1 verifies that HEAD never invokes the recorder; tasks 1.4 and 3.3 cover atomic recording and the explicit write-failure outcomes. The healthy-store HEAD scenario bounds the normal GET/HEAD comparison. |
| 6 | confirmed — Decision 13 and the store-failure requirement distinguish lookup failure (503 before resolution) from write failure after successful resolution (302/503). Tasks 2.1 and 3.3 separately cover a throwing repository, and the artifacts explicitly disclaim availability during a total database outage. |

### Validation

- Reviewed only the diff from `6e59b75bb617ada03da5ec51b92935f2a93b041f` to the Reviewed-Commit and collateral context needed for the six source findings; all six source statuses were `fixed`.
- Verified the requested branch and HEAD and an initially clean working tree. Inspected the existing firewall configuration and installed rate-limiter factory and consumption path.
- `scripts/pregate-verify.sh gate1 add-redirect-with-sync-logging` passed, including strict OpenSpec validation, with zero warnings. This is a Gate 1 artifact confirmation, not implementation validation.

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** cd112a8364ba05866e50f9aa249bcc23517d0879
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Decision 6 preserves unrelated raw query components and replaces all occurrences of the intended percent-decoded UTM keys. The spec and task 2.1 cover repeated, dotted, bracketed and encoded components and require a regression against the old map-based algorithm. |
| 2 | confirmed — The proposal, decision 1 and task 2.3 explicitly narrow the API firewall boundary. The spec and task 3.1 verify public access to an api-prefixed slug with absent and invalid credentials while retaining authentication on the actual API route. |
| 3 | confirmed — Decision 9 and task 3.3(c1–c3) retain creation-failure coverage and now explicitly require successful creation of a real SlidingWindowLimiter followed by failing lock acquisition or storage access during consumption. Each case asserts 302 and a warning naming the failure class without the IP. The planned mutation moving only consume() outside the catch must fail the lock/storage cases while leaving the creation case green, closing Confirmation 1's coverage gap. The fail-open policy and Redis dependency boundary remain explicit in the proposal and normative spec. |
| 4 | confirmed — Decision 7 and the header-bounds requirement validate the extracted host against length, UTF-8 and control-character constraints, converting invalid input to null. Tasks 2.1 and 3.2 require boundary tests and hostile-header requests that persist a click and return 302 for both limited and unlimited links. |
| 5 | confirmed — The normative contracts explicitly distinguish normal recording, unlimited write-failure redirects, HEAD's independence from recording, and the commit-to-response crash window. Tasks 1.4, 2.1 and 3.3 cover transaction atomicity, HEAD bypass and the write-failure outcomes. |
| 6 | confirmed — Decision 13 and the store-failure requirement bound write-failure outcomes to successful link resolution and separately specify lookup failure as 503 with Retry-After. Tasks 2.1 and 3.3 require independent lookup-failure tests; the artifacts disclaim availability during a total database outage. |

### Validation

- Reviewed the diff from `6e59b75bb617ada03da5ec51b92935f2a93b041f` to the Reviewed-Commit and collateral context reachable from the six named findings. All source finding statuses were `fixed`; no unrelated findings were introduced.
- Verified the requested branch and HEAD and an initially clean working tree. Checked the installed rate-limiter factory interface, consumption path and lock acquisition path against the revised failure-test plan.
- `scripts/pregate-verify.sh gate1 add-redirect-with-sync-logging` passed, including strict OpenSpec validation, with zero warnings. This confirms Gate 1 artifacts; implementation and the planned failing-input demonstrations remain subject to Gate 2.
