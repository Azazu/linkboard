# Review — add-routing-rules

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-10
**Reviewed-Commit:** a6250f9f17f9ecf1126aea7f839082fa5f5b6a5b
**Verdict:** changes-requested

### Findings

| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | design.md decisions 1–3; specs/routing-rules/spec.md — Rules document shape; tasks.md 2.1–2.3 | Associative JSON decoding loses more than the empty-object distinction described in decision 2: a numeric-keyed object such as `{"version":1,"rules":{"0":{"match":{"country":["DE"]},"target":"https://example.com/"}}}` becomes indistinguishable from a valid rules list. The same problem applies to value lists such as `"country":{"0":"DE"}` and to variants. The installed serializer defaults to associative decoding (`vendor/symfony/serializer/Encoder/JsonEncoder.php`), so a parser receiving only that decoded array cannot enforce the promised JSON list types or agree with the published schema on these inputs. Preserve JSON object/list types through write validation (then convert the validated document for persistence), or explicitly revise the accepted contract and schema. Add POST and PATCH rejection fixtures for numeric-keyed objects at every list position, with the specified violation paths and unchanged storage after a rejected PATCH. | fixed |
| 2 | major | design.md decision 8; specs/routing-rules/spec.md — Country resolution; tasks.md 3.4 | Checking `COUNTRY_RESOLVERS` only in the chain constructor does not provide the required application-boot failure. Symfony constructs services on demand; the installed `ContainerDebugCommand` describes service definitions through `BuildDebugContainerTrait` and does not instantiate the named chain. Consequently `COUNTRY_RESOLVERS=bogus` can pass the proposed debug command and fail only when the redirect controller's dependencies are constructed, outside the resolver's degradation guard. Specify an actual boot/configuration validation mechanism and an integration verification that boots the application with `bogus` and fails without manually retrieving or constructing the chain; include the corresponding valid-configuration case. | fixed |
| 3 | major | proposal.md items 2/4; design.md decision 5; specs/routing-rules/spec.md — Unresolvable dimensions are skipped / Language resolution / Evaluation never fails the redirect; tasks.md 5.2 | The planned hostile-input behavior contradicts the normative brief's FR-RUL-7 (`docs/explanation/requirements.md:110`): that requirement explicitly sends malformed/hostile input to the default target and logs a notice, whereas this change silently skips garbage UA and allows an 8 KB repeated `de,` header to select a language rule. The new delta also says oversized headers are unresolved dimensions while its language scenario expects that same oversized header to resolve to `de`. These are different observable destinations and logging contracts, not merely implementation details. Choose one explicit policy for absent, malformed, oversized-but-parseable and throwing inputs; reconcile the brief, proposal, delta, design and tasks, and add tests with both a matching language rule and variants so the selected policy is unambiguous. Task 5.2 currently checks only roadmap/requirements row 6 and would leave FR-RUL-7 contradictory. | fixed |

### Validation

Reviewed the proposal, design, tasks, all four delta specs, handoff, repository instructions/configuration, relevant current application and installed Symfony source, and the normative routing requirements. The branch and HEAD match the requested identifiers. `scripts/pregate-verify.sh gate1 add-routing-rules` passed, including strict OpenSpec validation, with no warnings. This is an artifact review before implementation; no application test run is claimed.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-10
**Reviewed-Commit:** 7498818e24200f9d08af183ef6c430b9eed11f71
**Verdict:** changes-requested

### Findings

| # | Resolution |
|---|------------|
| 1 | changes-requested — The raw-body, object-preserving decode in design decisions 1–2 fixes the write-validation mechanism. However, tasks 2.1/2.3 and the new JSON-object scenario cover only three positions: rules, country and variants; the requested POST/PATCH fixtures for device, os and language lists are still missing. Add those cases with their exact paths and unchanged storage after each rejected PATCH. Also correct task 2.1's stored-mode expectation: after associative decoding, an object with consecutive numeric keys is indistinguishable from a list, so the stored-array parser cannot reject that representation while accepting the equivalent canonical list. Limit the guarantee to type-preserving write validation and use a distinguishable malformed stored fixture (or preserve JSON types on reads as well); align decision 1's claim that any stored deviation yields violations. |
| 2 | confirmed — Design decision 8 and tasks 1.2/3.4 now specify a public StartupChecks service invoked from the overridable Kernel::boot(), which iterates the tagged checks and constructs the chain. The proposed integration test calls bootKernel() with bogus without retrieving the chain, includes a successful fixed configuration, and the console verification uses about rather than relying on debug:container to instantiate the service. This addresses the boot-time validation gap. |
| 3 | changes-requested — The absent/unrecognised versus hostile-header policy and the 8 KB language scenario are reconciled, but the newly explicit throwing-input policy remains contradictory for geolocation. FR-RUL-7, proposal item 4 and the hostile-input/failures requirement promise default target, null dimensions and a notice naming the link for any exception inside geolocation. Design decision 8, task 3.4 and the Country resolution reader-failure scenario still catch a reader RuntimeException, log locally and return an unknown country, allowing a matching language rule or variants to win. Choose and state the exception boundary consistently across these artifacts (including the expected missing-database/address-not-found cases), and add a reader-error redirect scenario with a matching language rule and variants that asserts the chosen destination, click facts and logging context. |

### Validation

Confirmation scope: the diff from a6250f9f17f9ecf1126aea7f839082fa5f5b6a5b to 7498818e24200f9d08af183ef6c430b9eed11f71 and collateral claims reachable from findings 1–3. All source-round findings were dispositioned as fixed before confirmation. Checked repository instructions/configuration, the affected planning artifacts and normative requirement, and relevant installed Symfony/application source. Branch and HEAD match the requested identifiers. `scripts/pregate-verify.sh gate1 add-routing-rules` passed, including strict OpenSpec validation, with zero warnings. This remains a pre-implementation artifact review; no application test run is claimed.

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-10
**Reviewed-Commit:** 837e28df563eecb4db176d16475a7f7c9d6b45de
**Verdict:** confirmed

### Findings

| # | Resolution |
|---|------------|
| 1 | confirmed — Design decisions 1–2 validate the raw request body with JSON objects preserved, then persist the canonical array. The JSON-kind scenario and tasks 2.1/2.3 now cover numeric-keyed objects at all six list positions (rules, device, os, country, language, variants) on POST and PATCH, with exact violation paths and a GET proving unchanged storage after each rejected PATCH. Decisions 1/6 and the spec explicitly limit JSON-kind guarantees to write validation; stored-mode tests now use distinguishable malformed arrays. |
| 2 | confirmed — Decision 8 and tasks 1.2/3.4 retain the explicit Kernel::boot() override invoking the public StartupChecks service and instantiating the tagged chain. The integration verification boots with bogus without retrieving the chain and includes a successful fixed configuration; the console verification uses about and includes a successful normal configuration. The constructor-only validation gap remains resolved. |
| 3 | confirmed — The revised FR-RUL-7, proposal, design, delta and tasks distinguish absent/unrecognised inputs from hostile inputs and require the latter to bypass profiling/evaluation, use the default target and emit one notice. Decision 8 and the country/degradation scenarios now distinguish expected database-open failures and address-not-found outcomes from unexpected lookup exceptions: the latter propagate through the chain to the redirect guard. Tasks 3.4/4.3 verify propagation and the reader-error redirect with a matching language rule and variants, asserting the default destination, null dimensions/variant and one notice with link id and exception class; expected non-answers instead select the language rule with their specified warning/no-log behavior. |

### Validation

Confirmation scope: the diff from a6250f9f17f9ecf1126aea7f839082fa5f5b6a5b to 837e28df563eecb4db176d16475a7f7c9d6b45de and collateral effects reachable from findings 1–3, including the outstanding points from Confirmation 1. All source-round findings were dispositioned as fixed. Checked the affected planning artifacts, repository instructions/configuration, normative routing requirements and relevant installed Symfony/application source; repository searches located the related claims. Branch and HEAD match the requested identifiers. `scripts/pregate-verify.sh gate1 add-routing-rules` passed, including strict OpenSpec validation, with zero warnings. This confirms the pre-implementation artifacts; no application test run is claimed. Only this review file was modified; no git write commands were run.
