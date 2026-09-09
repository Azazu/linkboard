# Review — add-link-crud

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 2389dc92035dc8640998b59485ba35d60e1b532e
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | `design.md` decision 6 and Risks / Trade-offs; `specs/links/spec.md` Update a link; `tasks.md` 3.1/3.3 | The proposed fresh nullable input object represents both omitted fields and explicit JSON null as null, while the design explicitly declines presence tracking. It therefore cannot implement the promised distinction: on a link with a limit and expiry, PATCH `{"isActive":false}` must preserve both, whereas PATCH `{"maxClicks":null}` must clear only the limit. Choose a concrete mechanism that preserves presence (for example, decoded-body key membership or an explicit sentinel), specify the null contract for each writable field, and add spec scenarios and HTTP verification for omission versus explicit null on populated fields. Merely documenting the desired contract does not supply the missing mechanism. | fixed |
| 2 | major | `design.md` decisions 2/3 and Concurrent writers; `tasks.md` 2.1/3.1/3.3 | Generated-slug retries are promised after a database unique violation, but no transaction/EntityManager recovery mechanism is designed. In the installed Doctrine ORM, `UnitOfWork::commit()` closes the EntityManager and rolls back on failure (`vendor/doctrine/orm/src/UnitOfWork.php`, unsuccessful-commit finally block). Retrying the usual repository persist/flush on that manager cannot succeed; the registration example only translates the exception and ends the request. Specify a retry-safe persistence strategy, including how the owner is attached if a fresh manager is used, and distinguish custom-slug 422 from generated-slug retry/exhaustion. Add an integration verification that forces an actual insert collision after the pre-check and then succeeds on a subsequent candidate, plus exhaustion coverage; stubbed `slugExists` collisions alone do not verify this guarantee. | fixed |
| 3 | major | `proposal.md` item 7; `design.md` decisions 6/8 and external-effect applicability row; `specs/links/spec.md` Ownership and admin access; `tasks.md` 3.1/3.3 | The change claims FR-ADM-2 admin deactivation/deletion, but omits that requirement's info-level audit record with actor id and target id. No link processor logging mechanism, spec scenario, or implementation/verification task covers it, despite the existing audit channel and after-flush pattern in `src/Auth/Api/Admin/BlockUserProcessor.php`. Add audit coverage for admin link mutations, define successful-write ordering and its crash limitation in the applicability table, and plan tests proving successful actions emit the required ids without personal data and rejected/failed actions do not emit a success record. | fixed |

### Validation
- Confirmed the requested branch and HEAD; the working tree was clean before review.
- `scripts/pregate-verify.sh gate1 add-link-crud` passed, including strict OpenSpec validation.
- Reviewed proposal, design, delta spec, tasks and handoff against `AGENTS.md`, `openspec/config.yaml`, the normative requirements and relevant installed/source mechanisms. Risk tier high is appropriate.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 529a897043499d0cd9214b3b6aa2eb4468727598
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Design decision 6 now uses decoded-body key membership to distinguish omission from explicit null; the installed API Platform controller supplies the request in processor context. The proposal, delta spec and tasks 3.1/3.3 agree on the per-field null contract and explicitly verify preservation of populated expiry/limit fields versus clearing only the limit over HTTP. |
| 2 | changes-requested — Design decision 3 and tasks 2.1/3.1 avoid reuse of a closed EntityManager by abandoning retries after an actual INSERT collision, returning 500 on the first such collision. This does not satisfy the retry guarantee in `docs/explanation/requirements.md` FR-LNK-2 or the unchanged generated-slug requirement in `specs/links/spec.md:7`, and task 2.1 now tests that weakened behavior instead of collision recovery. For example, a competitor takes candidate 1 after the pre-check while candidate 2 is free: the proposed processor returns 500 without trying candidate 2. Specify a transaction/EntityManager-safe retry strategy and integration coverage for actual insert collision followed by success and bounded exhaustion, as requested in Round 1. Alternatively, obtain explicit acceptance of the reduced guarantee and reconcile the normative requirement and all affected artifacts; documenting the exception in the design alone does not resolve the finding. |
| 3 | changes-requested — The audit mechanism, ids, data exclusions, after-flush ordering and crash limitation are now specified. However, task 3.3 only plans successful admin deactivate/delete and an owner's successful deactivation; neither it nor the added spec scenarios verifies that rejected requests and failed persistence emit no success audit record, which Round 1 explicitly required. Add verification for an authorization/validation rejection and a forced flush failure with no success record. Also cover the newly promised `link.update` and `link.activate` actions and explicitly assert the absence of URL/email/slug data in successful records, so each claimed audit outcome has verification coverage. |

### Validation
- Reviewed only `2389dc92035dc8640998b59485ba35d60e1b532e..529a897043499d0cd9214b3b6aa2eb4468727598` and collateral effects of Round 1 findings 1–3; no unrelated findings introduced.
- Confirmed branch `change/add-link-crud`, the requested HEAD and an initially clean working tree; all source-round findings were dispositioned as `fixed` before confirmation.
- Checked related claims across repository artifacts and the installed API Platform request-context and Doctrine failed-commit mechanisms.
- `scripts/pregate-verify.sh gate1 add-link-crud` passed, including strict OpenSpec validation. This is an artifact confirmation; implementation tests are still planned.

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 68c26c9d1f58ffe3f2fdb4b3e1e800a297d00d68
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Design decision 6 preserves presence through decoded request-body key membership, independently of nullable DTO values. The installed API Platform controller supplies the request in processor context. The proposal, spec and tasks retain the per-field null contract and HTTP verification that omission preserves populated expiry/limit fields while explicit null clears only the limit. |
| 2 | changes-requested — The persistence mechanism is now viable: decision 3 resets the failed EntityManager, builds a new Link, attaches the owner through getReference on the fresh manager, distinguishes custom-slug 422 from generated-slug retries, and bounds attempts at five. However, task 2.1 conflates two different verification cases: it describes the test "without the reset" as equivalent to "a competitor on all five candidates" and expects retry exhaustion. Without reset, Doctrine closes the manager after the first failed flush, so the next persist fails before five INSERT collisions can occur; an eventual 500 does not prove bounded exhaustion. Separate the successful-recovery test with reset enabled, the mutation check that removing reset breaks that success test, and an exhaustion test with reset enabled and all five candidates occupied, asserting five attempted collisions and the exhaustion error log. This completes the actual-collision and exhaustion verification requested in Round 1. |
| 3 | changes-requested — The added spec scenario and task 3.3 now cover 403 rejection, 422 validation rejection and forced flush failure without a success audit record. After-flush ordering, ids and the crash limitation remain specified. The outstanding coverage from Confirmation 1 is still absent: design decision 8 and the audit requirement promise link.update and link.activate, but task 3.3 and the scenarios only verify successful deactivate/delete. Add successful update/reactivation verification with the required ids and explicit assertions excluding URL, email and slug from successful records. The existing scenario's absence-of-@/slug assertion does not exclude a target URL without either substring. |

### Validation
- Reviewed only `2389dc92035dc8640998b59485ba35d60e1b532e..68c26c9d1f58ffe3f2fdb4b3e1e800a297d00d68` and collateral effects of Round 1 findings 1–3; no unrelated findings introduced.
- Confirmed branch `change/add-link-crud`, the requested HEAD and an initially clean working tree; all source-round findings were marked `fixed` before confirmation.
- Checked related artifact claims, normative FR-LNK-2/FR-ADM-2, installed Doctrine rollback/reset behavior, API Platform request context and the existing admin audit pattern.
- `scripts/pregate-verify.sh gate1 add-link-crud` passed, including strict OpenSpec validation. Implementation tests remain planned; this is a Gate 1 artifact review.
- Findings 2 and 3 have now failed two confirmations. Per AGENTS.md, stop the confirmation loop and split/reduce the change or ask the user to arbitrate before proceeding.

## Confirmation 3 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 75f27c081177bb9611d871df042babd041326d12
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Decision 6 uses decoded request-body key membership independently of nullable DTO values. The proposal, spec and tasks agree on omission versus explicit null, including HTTP verification on populated expiry/limit fields and rejection of null targetUrl/isActive. The installed API Platform controller supplies the request in processor context. |
| 2 | changes-requested — Task 2.1 now separates actual INSERT collision recovery, five-candidate exhaustion with an error log, and custom-slug 422; decision 3 retains resetManager and owner reattachment through getReference. However, the recovery test newly requires ManagerRegistry::getManager() to be a new instance. The installed DoctrineBundle declares the EntityManager service lazy (vendor/doctrine/doctrine-bundle/config/orm.php), and Symfony's ManagerRegistry::resetService resets that lazy object in place through resetLazyObject or ReflectionClass::resetAsLazyGhost/resetAsLazyProxy (vendor/symfony/doctrine-bridge/ManagerRegistry.php). Object identity can therefore remain unchanged after correct recovery, making the required assertion fail for a valid implementation. Replace the identity assertion with recovery evidence: the registry manager is open and successfully persists candidate 2 with the owner attached to its current UnitOfWork; retain the separate exhaustion test. Removing reset must break the successful-recovery test. |
| 3 | changes-requested — The new scenario and task 3.3 cover successful link.update/link.activate and the one-record-per-request precedence rule, alongside deactivate/delete and rejection/flush-failure paths. The outstanding data-exclusion verification remains missing: task 3.3 asserts actions and ids but never excludes URL/email/slug, and the deactivate/delete scenario still checks only absence of @ and the slug. A record leaking https://example.org/moved would satisfy those assertions. Add explicit assertions across successful audit records that the message and context contain no target URL, email or slug (or assert an exact safe message/context shape). The design's exclusion promise alone does not verify the Round 1 requirement. |

### Validation
- Reviewed only `2389dc92035dc8640998b59485ba35d60e1b532e..75f27c081177bb9611d871df042babd041326d12` and collateral effects of Round 1 findings 1–3; no unrelated findings introduced.
- Confirmed branch `change/add-link-crud`, the requested HEAD and an initially clean working tree; every source-round finding was marked `fixed`.
- Per the user's explicit request, performed this third confirmation after the earlier stop notice; this does not waive unresolved findings.
- Checked related artifact claims, normative FR-LNK-2/FR-ADM-2, installed Doctrine rollback and Symfony manager-reset mechanisms, API Platform request context and the existing admin audit implementation.
- `scripts/pregate-verify.sh gate1 add-link-crud` passed, including strict OpenSpec validation. This is a Gate 1 artifact review; implementation tests remain planned.
- Findings 2 and 3 remain unresolved after the requested third confirmation. Stop further automatic confirmations and return to user arbitration or split/reduce the change, per AGENTS.md.
