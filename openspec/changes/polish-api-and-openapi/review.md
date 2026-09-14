# Review — polish-api-and-openapi

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-14
**Reviewed-Commit:** 18339ec88c10e59353d22e9c01adc156d3d1e8a8
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | openspec/changes/polish-api-and-openapi/proposal.md — Risk-Tier; config/packages/security.yaml; src/Auth/RateLimit/ApiRateLimitListener.php | The medium-tier justification says authentication and the rate limiter are untouched, but the diff changes access-control configuration, the json_login check path and the runtime limiter exclusion mechanism. AGENTS.md explicitly makes changes touching authentication/security firewall high tier, even if intended to preserve behavior. Restore the documentation-only scope or raise the tier, reconcile the artifacts and obtain Gate 1 with the required evidence before proceeding with this security-related scope. | fixed |
| 2 | major | src/Shared/Api/CommonErrorResponses.php:116; docs/reference/api-errors.md — catalogue; tests/Api/ErrorCatalogueTest.php:47 | Reachable failures are still absent from the document and catalogue. For example, POST /api/v1/auth/register with Content-Type: text/plain reaches API Platform's ContentNegotiationProvider::getInputFormat(), which throws UnsupportedMediaTypeHttpException (415); neither the decorator nor the catalogue includes 415, and the catalogue incorrectly assigns wrong content types to 400. The completeness test only scans numeric ProblemDetails calls and already-documented statuses, so it cannot detect this framework-produced omission. Document 415 on the applicable input operations, correct the catalogue, and add a real unsupported-content-type contract case that also checks catalogue coverage. | fixed |
| 3 | major | src/Shared/Api/CommonErrorResponses.php:132–159 | The token operation inherits only 200 from Lexik and receives 401/429/406 here, leaving its real 400 (malformed JSON or missing credential fields in JsonLoginAuthenticator) and 403 (blocked account in JwtProblemDetailsSubscriber::onFailure) undocumented. Conversely, 406 is added unconditionally although json_login answers this route before API Platform content negotiation and does not reject an incompatible Accept header. Define this operation's statuses from its actual authentication path and test malformed credentials, blocked accounts and incompatible Accept, correcting the catalogue's blanket 406 claim as well. | fixed |
| 4 | major | tests/Api/OpenApiDocumentTest.php:112; src/Shared/Api/CommonErrorResponses.php | The examples requirement is not fulfilled for POST /api/v1/auth/token. Lexik emits inline request properties email/password and an inline response property token without examples; the decorator never enriches them, and the test only visits components.schemas, so it reports success while this documented operation remains an empty skeleton. The actual success response also contains expiresAt (JwtProblemDetailsSubscriber::onSuccess), which is absent from that schema. Describe the complete token payload with examples and include inline request/response schemas in coverage. | fixed |
| 5 | major | config/services.yaml:30–41; tests/Api/OpenApiDocumentTest.php:59 | The claimed shared source of path policy and task 1.2's verification are incomplete. app.api.ip_limited_paths is read only by the decorator; AuthRateLimitSubscriber still uses its independent hard-coded GUARDED list, including a POST-only restriction the decorator does not model. app.api.public_paths also separately assembles the public-rule list instead of deriving it from access control. The test asserts today's literal operation set and contains neither the promised configuration-driven comparison nor the demonstrated drift input. Make the documentation policy follow the runtime policy (including method restrictions), or explicitly constrain and test their equivalence; demonstrate that changing one side cannot silently leave the document stale. | fixed |
| 6 | major | tests/Api/Contract/ApiContractTest.php:55–93; openspec/changes/polish-api-and-openapi/tasks.md — 2.2, 3.2, 3.4 | Checked verification claims lack their stated coverage: no contract case reaches the API-key cap/409; the filter test never exercises the admin collection or order[createdAt]; the example validator never checks format despite task 2.2 claiming it. In addition, cases have no expected status, so a named authorization refusal returning a documented 200 (or a happy path returning a documented 403) still passes status/media matching. Add expected outcomes and the promised cases/format validation, and reconcile task and handoff claims with the actual evidence. | fixed |

### Review evidence and limitations
- Verified a clean initial worktree and HEAD equal to the requested commit; inspected the branch diff against main, change artifacts, repository configuration, relevant runtime handlers and installed Symfony/API Platform/Lexik source.
- `git diff --check main...HEAD` passed.
- Runtime checks were not rerun: `make ps` failed with permission denied on `/var/run/docker.sock`. Findings above are based on source inspection; the executor's reported green suite was not independently reproduced.
- Only this review record was written; no git write commands were run.

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-14
**Reviewed-Commit:** 97e82ee021befd98739b211a6945c0dc68398822
**Verdict:** approved

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | minor | openspec/changes/polish-api-and-openapi/specs/api-docs/spec.md — The allowance is documented where it is sent; tasks.md — 1.3 | The scenario says every rate-limited operation documents allowance headers on success, but AuthRateLimitSubscriber sends only Retry-After on refusal and no allowance headers on success. The existing implementation and handoff correctly distinguish this from the identity limiter. Qualify the scenario and verification wording by limiter: assert allowance headers for identity-limited operations and Retry-After for both, without adding runtime headers. This keeps the normative example consistent with the requirement to document only headers the limiter actually sets and the no-runtime-behavior-change scope. | fixed |

### Review evidence and limitations
- Verified the requested branch, a clean initial worktree and HEAD equal to the Reviewed-Commit above. Read AGENTS.md, openspec/config.yaml, the proposal, design, tasks, both delta specs, existing capability specs, roadmap, handoff and prior Gate 2 record; inspected the relevant security configuration, runtime limiter classes, documentation decorator and installed Symfony authentication source for feasibility.
- The high-tier scope now explicitly includes the firewall, token route and limiter parameterization. Tasks 6.4 and 7.1–7.2 provide runtime-policy equivalence checks, unchanged behavioral suites and executed negative inputs for the moved boundaries; tasks 6.1–6.5 cover the outstanding Gate 2 corrections. No blocking architectural or planning finding remains for Gate 1.
- `openspec validate polish-api-and-openapi --strict`, `git diff --check main...HEAD` and `scripts/pregate-verify.sh gate1 polish-api-and-openapi` passed; the pre-gate floor reported zero warnings.
- This is approval of the revised plan, not confirmation of the existing implementation or closure of Gate 2 round 1. Previously checked implementation claims must be reconciled with the pending corrective tasks and demonstrated evidence before Gate 2. Runtime suites were not rerun for this artifact review.
- Only this review record was written; no git write commands were run.
