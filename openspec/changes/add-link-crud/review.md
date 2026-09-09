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

## Confirmation 4 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** a437dfdbc7b8d0d8f5214fad9f0e0f2ade2254e5
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Decision 6 uses decoded request-body key membership to preserve field presence independently of nullable DTO values. The proposal, spec and tasks 3.1/3.3 agree on the per-field null contract and HTTP verification that omission preserves populated expiry/limit fields while explicit null clears only the limit; null targetUrl/isActive is rejected. The installed API Platform controller supplies the request in processor context. |
| 2 | confirmed — Decision 3 and task 3.1 specify resetManager after a generated-slug INSERT collision, a new Link and owner attachment through getReference on the recovered manager, bounded attempts and separate custom-slug 422 behavior. Task 2.1 now verifies actual collision recovery through candidate 2 being persisted with the correct owner, an open manager and a managed owner, without requiring different lazy-service object identity. Removing reset must break that success test. A separate test occupies all five candidates and verifies exhaustion with 500 and an error log naming five attempts. This resolves the recovery and exhaustion verification gaps without weakening FR-LNK-2. |
| 3 | confirmed — Decision 8, the audit requirement and task 3.3 cover successful update, deactivate, activate and delete with actor/target/owner ids, including one-record precedence for combined updates. Task 3.3 now explicitly asserts the exact safe context keys and scalar values for every successful record and excludes slug, old/new target URLs and email from the entire JSON line. The spec scenarios reflect these exclusions. Verification also covers owner actions, 403/422 rejection and forced flush failure without a success record; after-flush ordering and the crash limitation remain explicit. |

### Validation
- Reviewed only `2389dc92035dc8640998b59485ba35d60e1b532e..a437dfdbc7b8d0d8f5214fad9f0e0f2ade2254e5` and collateral effects of Round 1 findings 1–3; no unrelated findings introduced.
- Confirmed branch `change/add-link-crud`, the requested HEAD and an initially clean working tree; all source-round findings were marked `fixed`.
- Per the user's explicit request, performed this fourth confirmation after the prior stop notice.
- Checked related artifact claims, normative FR-LNK-2/FR-ADM-2, installed Doctrine failed-commit and Symfony lazy-manager reset behavior, API Platform request context and the existing admin audit implementation.
- `scripts/pregate-verify.sh gate1 add-link-crud` passed, including strict OpenSpec validation. This confirms the Gate 1 artifacts; implementation tests remain planned.
- Modified only `review.md`; ran no git write commands.


## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** def03664e7b9e36dc14187c9180aa35cf77c7a51
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | `src/Link/Api/UpdateLinkInput.php:17`; `src/Link/Validator/TargetUrlValidator.php:20`; `src/Link/Api/UpdateLinkProcessor.php:60` | PATCH `{"targetUrl":""}` passes validation and replaces a valid destination with an empty string: the DTO has only a maximum length constraint, TargetUrlValidator deliberately skips empty strings assuming NotBlank will reject them, and the processor rejects only null. Unlike creation, PATCH has no NotBlank guard. This violates the absolute-URL requirement and the promise that updates apply creation's validation. Reject a present empty target while preserving omitted-field behavior; add an HTTP regression asserting 422 on targetUrl and that the stored destination remains unchanged. | fixed |
| 2 | major | `src/Link/Validator/TargetUrlPolicy.php:49` | Treating every host that inet_pton cannot parse as an allowed hostname bypasses the private/loopback/link-local policy without DNS. For example, `http://127.1/`, `http://2130706433/` and `http://0x7f000001/` are accepted by this branch but browser URL parsing targets 127.0.0.1; `http://2852039166/` targets 169.254.169.254. Percent-encoded host digits provide another form (`http://%31%32%37.0.0.1/`). These are numeric/encoded address representations, outside the documented unresolved-DNS exception. Canonicalize or reject ambiguous/encoded IP representations before the hostname fallback, apply the existing range policy to the effective address, and add unit plus POST/PATCH HTTP regressions for these inputs. Reconcile the security-note guarantee with the resulting implementation. | fixed |
| 3 | major | `src/Link/Validator/Slug.php:15` | The PCRE `$` anchor matches before a final newline, so the pattern accepts `"abc\n"` and `"admin\n"`. The latter also misses the exact reserved-word check; the original string is persisted and directly concatenated into shortUrl. This stores a slug outside the promised alphabet and produces a URL whose newline may be removed by a browser, targeting a different slug or the reserved route. With 32 valid characters followed by a newline it also passes the validator but exceeds VARCHAR(32), producing a storage error instead of 422. Use an absolute end-of-string anchor (or the equivalent PCRE option) and add HTTP rejection tests for a trailing newline, including a reserved word and the length boundary. | fixed |

### Validation
- Confirmed branch `change/add-link-crud`, HEAD `def03664e7b9e36dc14187c9180aa35cf77c7a51`, and an initially clean working tree. Reviewed `git diff main...change/add-link-crud`, the change artifacts, `openspec/config.yaml`, normative link requirements, implementation and tests. The declared high risk tier is appropriate; Gate 1's latest record is confirmed.
- Checked ownership enforcement, collection scoping, PATCH presence handling, admin audit ordering/data shape, migration constraints, and generated-slug collision recovery against the installed Symfony/Doctrine mechanisms and the tests.
- Reproduced the slug pattern accepting trailing newlines with the local PCRE2 library. Confirmed the alternative-IP examples' effective hosts with Node's WHATWG URL parser, without network requests. Finding 1 follows directly from the installed Length validator, the custom validator's empty-value return, and the processor/entity write path. These are focused parser/source checks, not completed HTTP reproductions.
- `scripts/pregate-verify.sh gate2 add-link-crud` passed whitespace, strict OpenSpec validation, task/path and Markdown-link checks, but failed its `make check` step because this sandbox cannot access `/var/run/docker.sock`. No host PHP is installed. Consequently PHP lint/static analysis/PHPUnit and HTTP regressions could not be independently executed in this review; the executor's earlier green results in the handoff/commit history were read, not reproduced. The environment failure is not attributed to a code regression.
- Modified only `review.md`; ran no git write commands.

## Confirmation 1 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** d324e4f8ddb75b8a92fac6f324552b8a55f48f60
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — TargetUrlValidator now skips only null and passes the empty string to TargetUrlPolicy, which rejects it for missing scheme/host. Omitted PATCH fields retain their nullable/presence behavior. UpdateLinkTest adds the empty-string HTTP case, asserts 422 on targetUrl and GETs the unchanged destination. Removing CreateLinkInput's redundant NotBlank retains empty/missing-target rejection through its default empty string and the shared validator; POST regressions cover both. |
| 2 | changes-requested — The listed ASCII numeric spellings and percent-encoded hosts are now handled, with unit and POST/PATCH regressions, but the same effective-address bypass remains through Unicode host normalization. For example, `http://１２７.０.０.１/` and `http://２８５２０３９１６６/` normalize in Node's WHATWG URL parser to 127.0.0.1 and 169.254.169.254 respectively, without DNS. TargetUrlPolicy.php:66–73 performs only lowercase/trailing-ASCII-dot handling before parseIpv4Host; its ASCII-only last-label pattern at lines 105–106 returns null for these hosts, allowing them as hostnames. Thus the new security-note guarantee that every browser-accepted spelling is checked is still false. Normalize the host using browser-compatible domain-to-ASCII processing before localhost/numeric classification, or reject non-ASCII hosts before the hostname fallback; retain safe ASCII/punycode hostname behavior. Add unit and POST/PATCH regressions for these normalized literal addresses, including unchanged storage after rejected PATCH, and reconcile the affected security claims. This is the unresolved parser/address-policy finding, not an unrelated finding. |
| 3 | confirmed — Slug::PATTERN now uses the absolute end anchor `\z`, so a final newline cannot pass the alphabet/length check or reach the reserved-word/database path. CreateLinkTest adds HTTP 422 cases for abc plus newline, admin plus newline, and 32 valid characters plus newline. The related requirement, proposal and delta spec clarify whole-string matching. |

### Validation
- Reviewed only `def03664e7b9e36dc14187c9180aa35cf77c7a51..d324e4f8ddb75b8a92fac6f324552b8a55f48f60` and collateral effects of Gate 2 Round 1 findings 1–3; no unrelated findings introduced.
- Confirmed branch `change/add-link-crud`, the requested HEAD and an initially clean working tree; all three source-round major findings were marked fixed, with no blockers or open rows.
- Inspected the changed validation paths, HTTP regression assertions and related artifact claims. Reproduced Unicode and ASCII effective-address normalization with the local Node WHATWG URL parser without network requests. PHP-side acceptance of the Unicode examples follows from source inspection; no HTTP reproduction is claimed.
- `scripts/pregate-verify.sh gate2 add-link-crud` passed whitespace, strict OpenSpec validation, task/path and Markdown-link checks, but its `make check` step failed because the sandbox cannot access `/var/run/docker.sock`. No host PHP executable is available. Executor-reported green checks and mutation evidence in the handoff/commit were read, not independently reproduced; this environment limitation is not attributed to a code regression.
- Modified only `review.md`; ran no git write commands.

## Confirmation 2 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** db839ec7678234e37c26c8767d1e9cbc32d16eb1
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — TargetUrlValidator skips only null and sends the empty string through TargetUrlPolicy, which rejects it. Omitted PATCH fields retain their presence handling. UpdateLinkTest asserts 422 on targetUrl for an empty string and verifies the original destination with a subsequent GET. CreateLinkInput's default empty string remains rejected without the redundant NotBlank constraint; POST tests cover both empty and missing targets. |
| 2 | changes-requested — Unicode digit normalization fixes the examples from Confirmation 1, but trailing Unicode dots still bypass the effective-address policy. At src/Link/Validator/TargetUrlPolicy.php:70, rtrim removes ASCII dots BEFORE toAscii. UTS #46 maps a final U+3002/U+FF0E/U+FF61 to an ASCII dot, leaving an empty last label; parseIpv4Host then returns null and isAllowed accepts the host without any range check. For example, http://127.0.0.1。/ and http://2852039166．/ map without IDNA errors to 127.0.0.1. and 2852039166., while the browser targets 127.0.0.1 and 169.254.169.254. The same ordering misses the localhost check for localhost。 . Handle the terminal dot after domain-to-ASCII normalization, before localhost and numeric classification (or reject these representations). Add unit and POST/PATCH rejection regressions for mapped trailing dots, including unchanged storage after rejected PATCH, and keep the security claims consistent. This is the unresolved Round 1 address-policy bypass, not an unrelated finding. |
| 3 | confirmed — Slug::PATTERN uses the absolute end anchor \z. HTTP regression cases require 422 on slug for abc plus newline, admin plus newline, and 32 valid characters plus newline. The requirement, proposal and delta spec retain whole-string matching, preventing the reserved-word and storage-length bypasses. |

### Validation
- Reviewed only `def03664e7b9e36dc14187c9180aa35cf77c7a51..db839ec7678234e37c26c8767d1e9cbc32d16eb1` and collateral effects of Gate 2 Round 1 findings 1–3; no unrelated findings introduced.
- Confirmed branch `change/add-link-crud`, the requested HEAD and an initially clean working tree. Every source-round finding was marked fixed; there were no blockers or open rows.
- Inspected the validation paths, HTTP regression assertions, related artifact claims and executor mutation evidence. Reproduced the trailing-dot domain-to-ASCII results using local ICU 77 through uidna_nameToASCII_UTF8 with non-transitional, CheckBidi and CheckJoiners options: all cited mappings returned zero errors. Independently reproduced the effective IP hosts with Node's WHATWG URL parser, without network requests. PHP-side acceptance follows from source inspection; no HTTP reproduction is claimed.
- `scripts/pregate-verify.sh gate2 add-link-crud` passed whitespace, strict OpenSpec validation, task/path and Markdown-link checks, but its `make check` step failed because this sandbox cannot access `/var/run/docker.sock`. No host PHP executable is available. Executor-reported green checks and mutation results were read, not independently reproduced; this environment limitation is not attributed to a code regression.
- Finding 2 has now failed two confirmations. Per AGENTS.md, stop the automatic confirmation loop and split/reduce the change or ask the user to arbitrate before proceeding.
- Modified only `review.md`; ran no git write commands.


## Confirmation 3 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** d68d62af98cae975056c25cf33deaaebfa72bea8
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — TargetUrlValidator skips only null; an empty string reaches TargetUrlPolicy and is rejected. PATCH presence handling remains intact. The HTTP regression asserts 422 on targetUrl and GETs the unchanged stored destination. Creation's default empty string remains rejected after removal of the redundant NotBlank constraint; POST tests cover missing and empty targets. |
| 2 | changes-requested — The numeric, encoded and Unicode host examples, including mapped trailing dots, are now covered, but the effective-address guarantee still has an authority-parser bypass at src/Link/Validator/TargetUrlPolicy.php:55–89. The literal URL `http://127.0.0.1\@example.com/` (one backslash) is parsed by PHP with host example.com and user information containing the loopback address, so the policy accepts the hostname; a browser treats the backslash as a slash and contacts 127.0.0.1. Likewise `http://169.254.169.254\@example.com/` targets the metadata address without DNS. Mapping only PHP's extracted host cannot fix this disagreement. Reject backslashes before authority extraction or use a browser-compatible URL parser, add unit and POST/PATCH regressions (including unchanged storage after rejected PATCH), and reconcile the effective-address security guarantee. This is a remaining bypass of Round 1 finding 2's address policy and its changed security claim, not an unrelated minor finding. |
| 3 | confirmed — Slug::PATTERN uses the absolute end anchor \z. POST regressions assert 422 on slug for abc plus newline, admin plus newline, and 32 valid characters plus newline. Related requirements, proposal and delta spec state whole-string matching. |

### Validation
- Reviewed only `def03664e7b9e36dc14187c9180aa35cf77c7a51..d68d62af98cae975056c25cf33deaaebfa72bea8` and collateral effects of Gate 2 Round 1 findings 1–3. Confirmed the requested branch and HEAD, initially clean working tree, and all source-round findings marked fixed.
- Per the user's explicit request, performed this third confirmation after the previous stop notice; it does not waive unresolved findings.
- Inspected changed validation paths, regression assertions, related artifact claims and executor mutation evidence. Reproduced the backslash examples' effective hosts and mapped-trailing-dot examples with local Node's WHATWG URL parser, without network requests. PHP authority extraction was verified against the [PHP 8.4 parser source](https://raw.githubusercontent.com/php/php-src/PHP-8.4/ext/standard/url.c): parse_host ends authority at slash/question-mark/hash, then takes the host after the last at-sign. Acceptance by the application follows from source inspection; no PHP/HTTP execution is claimed.
- `scripts/pregate-verify.sh gate2 add-link-crud` passed whitespace, strict OpenSpec validation, task/path and Markdown-link checks. Its `make check` failed because the sandbox cannot access `/var/run/docker.sock`; no host PHP executable is available. Executor-reported green checks and mutation results were read, not independently reproduced. This environment limitation is not attributed to a code regression.
- Finding 2 remains unresolved after the requested third confirmation. Stop further automatic confirmations and return to user arbitration or split/reduce the change, per AGENTS.md.
- Modified only `review.md`; ran no git write commands.

## Confirmation 4 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-09
**Reviewed-Commit:** 4be52045f6f008f5cc48b1a821bfb899d12632d9
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — TargetUrlValidator skips only null; a present empty string reaches TargetUrlPolicy and is rejected for lacking a scheme/host. PATCH presence handling is preserved. UpdateLinkTest asserts 422 on targetUrl and verifies the unchanged destination with a subsequent GET. Creation's default empty string remains invalid after removal of the redundant NotBlank constraint; POST regressions cover missing and empty targets. |
| 2 | confirmed — Backslashes, C0 controls, space and DEL are rejected before parse_url, and userinfo is rejected after parsing, closing the authority disagreement from Confirmation 3. Percent-encoded hosts are rejected; other non-bracketed hosts undergo UTS #46 mapping before removal of the single trailing dot and localhost/numeric classification. The numeric parser handles shorthand, decimal, hex and octal IPv4 forms and applies the existing range checks to the resulting address; bracketed IPv6 retains its range and mapped-IPv4 checks. Unit and POST/PATCH regressions cover the original numeric/encoded examples, Unicode addresses, mapped trailing dots and backslash authority examples; rejected PATCH cases verify unchanged storage. Accepted-host unit cases retain public addresses, ASCII/punycode and internationalized hostnames. The requirement, proposal, design, delta spec and security note describe the resulting restrictions and unresolved-DNS limitation consistently. No remaining bypass of the named finding was identified in the reviewed scope. |
| 3 | confirmed — Slug::PATTERN uses the absolute end anchor \z. POST regressions assert 422 on slug for abc plus newline, admin plus newline, and 32 valid characters plus newline. Related requirements, proposal and delta spec specify whole-string matching, closing both the reserved-word and storage-length paths. |

### Validation
- Reviewed only `def03664e7b9e36dc14187c9180aa35cf77c7a51..4be52045f6f008f5cc48b1a821bfb899d12632d9` and collateral effects of Gate 2 Round 1 findings 1–3; no unrelated findings introduced. Confirmed the requested branch and HEAD, an initially clean working tree, and every source-round finding marked fixed.
- Per the user's explicit request, performed this fourth confirmation after the prior stop notice.
- Inspected the changed validation paths, POST/PATCH assertions and their shared 422 assertion helper, related artifact claims, existing ext-intl requirement, and executor mutation evidence in the fix commits. Independently reproduced the cited effective-address examples with local Node's WHATWG URL parser without network requests. Application rejection is established by source inspection here; no PHP or HTTP execution is claimed.
- `scripts/pregate-verify.sh gate2 add-link-crud` passed whitespace, strict OpenSpec validation, task/path and Markdown-link checks. Its `make check` failed because the sandbox cannot access `/var/run/docker.sock`; no host PHP executable is available. Executor-reported green checks (240 tests / 1461 assertions) and mutation results were read, not independently reproduced. This environment limitation is not attributed to a code regression, and this confirmation does not waive the green-check requirement.
- Modified only `review.md`; ran no git write commands.
