# Review — add-link-crud

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 2389dc92035dc8640998b59485ba35d60e1b532e
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | `design.md` decision 6 and Risks / Trade-offs; `specs/links/spec.md` Update a link; `tasks.md` 3.1/3.3 | The proposed fresh nullable input object represents both omitted fields and explicit JSON null as null, while the design explicitly declines presence tracking. It therefore cannot implement the promised distinction: on a link with a limit and expiry, PATCH `{"isActive":false}` must preserve both, whereas PATCH `{"maxClicks":null}` must clear only the limit. Choose a concrete mechanism that preserves presence (for example, decoded-body key membership or an explicit sentinel), specify the null contract for each writable field, and add spec scenarios and HTTP verification for omission versus explicit null on populated fields. Merely documenting the desired contract does not supply the missing mechanism. | open |
| 2 | major | `design.md` decisions 2/3 and Concurrent writers; `tasks.md` 2.1/3.1/3.3 | Generated-slug retries are promised after a database unique violation, but no transaction/EntityManager recovery mechanism is designed. In the installed Doctrine ORM, `UnitOfWork::commit()` closes the EntityManager and rolls back on failure (`vendor/doctrine/orm/src/UnitOfWork.php`, unsuccessful-commit finally block). Retrying the usual repository persist/flush on that manager cannot succeed; the registration example only translates the exception and ends the request. Specify a retry-safe persistence strategy, including how the owner is attached if a fresh manager is used, and distinguish custom-slug 422 from generated-slug retry/exhaustion. Add an integration verification that forces an actual insert collision after the pre-check and then succeeds on a subsequent candidate, plus exhaustion coverage; stubbed `slugExists` collisions alone do not verify this guarantee. | open |
| 3 | major | `proposal.md` item 7; `design.md` decisions 6/8 and external-effect applicability row; `specs/links/spec.md` Ownership and admin access; `tasks.md` 3.1/3.3 | The change claims FR-ADM-2 admin deactivation/deletion, but omits that requirement's info-level audit record with actor id and target id. No link processor logging mechanism, spec scenario, or implementation/verification task covers it, despite the existing audit channel and after-flush pattern in `src/Auth/Api/Admin/BlockUserProcessor.php`. Add audit coverage for admin link mutations, define successful-write ordering and its crash limitation in the applicability table, and plan tests proving successful actions emit the required ids without personal data and rejected/failed actions do not emit a success record. | open |

### Validation
- Confirmed the requested branch and HEAD; the working tree was clean before review.
- `scripts/pregate-verify.sh gate1 add-link-crud` passed, including strict OpenSpec validation.
- Reviewed proposal, design, delta spec, tasks and handoff against `AGENTS.md`, `openspec/config.yaml`, the normative requirements and relevant installed/source mechanisms. Risk tier high is appropriate.
