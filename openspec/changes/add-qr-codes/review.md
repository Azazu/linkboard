# Review — add-qr-codes

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-11
**Reviewed-Commit:** 194022e75971f4897b86848c5fb76c6bc3937b1f
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | design.md, Decisions 1 and 7; tasks.md, 2.1 | The proposed custom controller does not run the pipeline on which the authorization and validation guarantees depend. This repository leaves `use_symfony_listeners` at its installed default `false` (`vendor/api-platform/symfony/Bundle/DependencyInjection/Configuration.php`). `MainControllerResourceMetadataCollectionFactory` skips operations with an explicit controller, and `ApiLoader` routes directly to that controller; the provider/processor chain is invoked by `MainController`, while the equivalent Symfony listeners are loaded only when that option is true (`ApiPlatformExtension.php`). Consequently, declaring `provider`, `security` and `QueryParameter` on this custom-controller operation does not supply `$data` or execute the promised voter, negotiation and validation pipeline. In addition, the explicitly attribute-free controller is a private service under the current service defaults, whereas `ApiLoader` requires the controller to be accessible through its container. Revise the architecture and tasks to use a supported pipeline integration (for example, retain the main controller and render through a suitable state processor), or explicitly design and validate the listener/controller registration changes and their impact on existing operations. Preserve HTTP verification of owner/admin/stranger, invalid format and unacceptable media type, including the security mutation check. | fixed |
| 2 | minor | design.md, Decision 2; tasks.md, 1.2 | The planned enum method `from(?string)` with `from(null)` defaulting to SVG conflicts with PHP's enum API: a backed enum already has a non-overridable `from(int|string)` method, and a unit enum cannot declare `from` either. Name the nullable/defaulting factory separately (for example `fromQuery`) and align the controller and test plan with that name. | fixed |
| 3 | minor | specs/qr-codes/spec.md, Authorization boundary; proposal.md, What Changes 2; design.md, Applicability | The unconditional promise that an unknown or malformed id returns 404 before any authorization check, looking the same to everyone, conflicts with the retained firewall: `access_control` requires `ROLE_USER` before the item provider runs, so an anonymous request to an unknown id returns 401. The scenario currently tests unknown ids only for authenticated callers. Qualify the 404-before-voter guarantee as applying after authentication and explicitly cover anonymous requests to malformed/unknown ids, keeping the existing firewall boundary. | fixed |

Validation: branch and HEAD match the requested identifiers; the working tree was clean before review. `scripts/pregate-verify.sh gate1 add-qr-codes` passed, including strict OpenSpec validation. Findings are based on the change artifacts, current application configuration and installed API Platform source; no implementation files were changed.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-11
**Reviewed-Commit:** f99b7cb66ae67e1888300cc22bed80dc8b30bda2
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Design decisions 1 and 7 and task 2.1 replace the explicit controller with an autoconfigured `ProcessorInterface` implementation, selected through `processor: LinkQrProcessor::class` and `write: true`. The installed metadata factory selects `MainController` when no custom controller is declared; its provider chain executes before `WriteProcessor`, which invokes the selected processor for this explicitly writable GET. Processor autoconfiguration and the service locator resolve the private service, and `SerializeProcessor` / `RespondProcessor` preserve its `Response`. Task 2.2 retains owner/admin/stranger, invalid-format and unacceptable-media-type HTTP checks and the security mutation check, and adds a mutation check for removing `write: true`. The proposal's impact section agrees with the processor architecture. |

Validation: reviewed only the diff from `194022e75971f4897b86848c5fb76c6bc3937b1f` to the Reviewed-Commit and collateral effects of finding 1, the source round's only blocker or major finding. Branch and HEAD match the request; the working tree was initially clean and every source finding was dispositioned. Checked the revised integration against installed API Platform source and current service configuration. `scripts/pregate-verify.sh gate1 add-qr-codes` passed, including strict OpenSpec validation. This confirms the Gate 1 design resolution; implementation and HTTP execution remain Gate 2 work. No unrelated findings introduced.
