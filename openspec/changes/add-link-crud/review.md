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
