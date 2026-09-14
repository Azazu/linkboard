# Review — add-web-ui

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-13
**Reviewed-Commit:** 600f8522c1190360f2f91ea2962f67664f7e97e6
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | design.md — decision 3 and Authorization boundary; tasks.md — 4.2 | The proposed access-control expression `^/(dashboard\|links\|api-keys)` also protects valid short links such as `/dashboard-sale`, `/links-promo` and `/api-keys-promo`. `ReservedSlugs::contains()` reserves exact strings only, and the redirect capability requires anonymous, session-free redirects for valid slugs. Implementing this expression would send existing public links to login, contradicting the unchanged-redirect scope. Require a segment boundary after the alternatives and add GET/HEAD regression verification for these prefix slugs, including no session cookie, while retaining guest denial on the actual UI routes. | fixed |
| 2 | major | design.md — decision 6; tasks.md — 2.2; specs/web-ui/spec.md — hardening headers | Excluding `^/api` from the HTML policy also excludes `/api-keys`, one of the authenticated web pages this change promises to harden. The dashboard-only positive header test and API negative tests would miss that omission. Bound the exclusion to the actual API path segment (or explicit API routes), reconcile which headers remain on excluded HTML responses, and add positive hardening assertions on `/api-keys`, including the response displaying a new key. | fixed |
| 3 | major | design.md — decisions 5 and 9; tasks.md — 2.1 and 4.8; specs/web-ui/spec.md — API-key plaintext shown once | A consumed server-side flash does not implement the promised once-only display with Turbo Drive enabled. Turbo snapshots the rendered DOM; creating a key, navigating away and returning through history can restore its plaintext without requesting the server. The planned WebTestCase checks only later server renderings. Specify how the secret is excluded from Turbo snapshots (for example a temporary flash element or disabled snapshot caching on the result page), define HTTP cache handling for that response, and add verification of navigation away/back with Turbo active plus a failing-input demonstration. Official behavior: [Turbo caching and temporary elements](https://turbo.hotwired.dev/handbook/building#understanding-caching). | fixed |
| 4 | minor | proposal.md — item 6; design.md — decision 3; specs/web-ui/spec.md — link-page ownership | The artifacts repeatedly say that the API already returns an indistinguishable 404 for a stranger's link. The main `links` spec explicitly requires 403 for stranger GET/PATCH/DELETE operations, and the proposal separately forbids changing API behavior. Describe 404 as the web presentation of the shared voter denial, preserving API 403, and remove the incorrect API claim across the artifacts so implementation and later spec synchronization cannot silently change that contract. | fixed |

### Validation
- HEAD and branch match the requested commit and `change/add-web-ui`; the working tree was clean before this review.
- `scripts/pregate-verify.sh gate1 add-web-ui` passes, including strict OpenSpec validation.
- Direct evaluation of the proposed path expressions confirms the prefix-slug matches and the `/api-keys` CSP exclusion.
- This is an artifact review; implementation tests are not yet applicable. Only this review file was written; no git write commands were run.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-13
**Reviewed-Commit:** e46102345f2682bd60fbece83f8cdea874bf12f7
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Design decision 3, the authorization applicability row and task 4.2 now use a segment boundary. The prefix-slug scenario refers to the redirect capability (which covers GET and HEAD), and task 4.2 requires public redirects without a session cookie, guest denial on the dashboard, and a failing-input demonstration for removal of the boundary. |
| 2 | confirmed — Design decision 6 and task 2.2 bound the exclusion to the API segment, preserving CSP on `/api-keys`; the spec and task explicitly cover both the list and the response displaying a new key. Excluded HTML retains the other three headers, and widening the exclusion is a planned failing input. |
| 3 | changes-requested — Decision 9a and task 4.8 address Turbo snapshots, but incorrectly treat `Cache-Control: no-store` as preventing the browser's back-forward cache. Chrome can retain such documents; a full-document navigation away from a directly loaded secret-bearing page and Back need not invoke Turbo's snapshot machinery. The new spec's guarantee about every browser/navigation cache therefore lacks a mechanism. Also, task 5.1 only requires documenting a manual check, not executing and recording a browser navigation with Turbo active; the planned negative checks assert markup rather than demonstrate secret redisplay when protection is removed. Specify protection for full-document history restoration (or explicitly reconcile the supported guarantee), and add an executable browser acceptance task with recorded results for Turbo navigation and full-document away/back, plus a failing-input demonstration of the secret-restoration guard. Keep the HTTP no-store header, but do not claim it alone excludes bfcache. |

### Validation
- Reviewed only `600f8522c1190360f2f91ea2962f67664f7e97e6..e46102345f2682bd60fbece83f8cdea874bf12f7` and collateral reachable from findings 1–3. No unrelated findings were introduced.
- Branch and HEAD match the request; the working tree was initially clean. All source-round findings have been dispositioned.
- Direct path-expression evaluation excludes all three prefix slugs from UI protection, protects the actual UI paths, and keeps `/api-keys` outside the CSP exclusion.
- `scripts/pregate-verify.sh gate1 add-web-ui` passes, including strict OpenSpec validation. This is a planning confirmation; application/browser tests are not yet applicable.
- [Turbo caching documentation](https://turbo.hotwired.dev/handbook/building#understanding-caching) confirms the proposed temporary-element and no-cache mechanisms for Turbo's own snapshots. [Chrome's no-store/bfcache documentation](https://developer.chrome.com/docs/web-platform/bfcache-ccns) explicitly allows no-store documents in bfcache under specified conditions; HTTP cache exclusion is not a universal history-restoration exclusion.
- Only `review.md` was modified; no git write commands were run.

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-13
**Reviewed-Commit:** 7127432900a04e7b61be1d58d7836a4aac42829a
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Design decision 3, the authorization applicability row and task 4.2 use the segment boundary. The prefix-slug regression scenario preserves the redirect capability's anonymous GET/HEAD contract, including no session cookie, while the actual UI routes remain protected. Removing the boundary is a specified failing input. |
| 2 | confirmed — Design decision 6 and task 2.2 restrict the CSP exclusion to the API segment. The spec and task require positive header assertions on both `/api-keys` and the response displaying a new key; excluded HTML retains the other three headers. Widening the exclusion is a specified failing input. |
| 3 | changes-requested — Decision 9a now correctly separates HTTP caching from bfcache, adds clearing on persisted `pageshow`, and explicitly bounds the guarantee; task 4.9 also requires executing and recording browser acceptance. However, its negative run removes only `data-turbo-temporary` and the `pageshow` clearing, leaving `turbo-cache-control=no-cache` active. Turbo therefore refetches the consumed-flash page on restoration, so the specified Turbo navigation cannot demonstrate plaintext returning under that mutation. Decision 10 likewise promises redisplay after individual removals even though the remaining protection can prevent it. Define separate negative cases: remove both Turbo snapshot protections for the Turbo restoration case; remove the `pageshow` guard for the full-document case and record that an actual persisted restoration occurred (a network reload does not exercise that guard). Use a fresh secret-bearing page for each case, retain HTTP `no-store`, and reconcile the expected outcomes in task 4.9, decision 10 and the browser scenario. The remaining objection concerns the already-requested executable failing-input demonstration, not a new unrelated finding. |

### Validation
- Reviewed only `600f8522c1190360f2f91ea2962f67664f7e97e6..7127432900a04e7b61be1d58d7836a4aac42829a` and collateral reachable from findings 1–3. All source-round findings were dispositioned; no unrelated minor findings were introduced.
- Branch and HEAD match the request, and the working tree was initially clean. Direct path-expression assertions pass for the three prefix slugs, actual UI paths and API exclusion boundary.
- `scripts/pregate-verify.sh gate1 add-web-ui` passes, including strict OpenSpec validation. This is an artifact confirmation; application/browser acceptance remains implementation work.
- [Turbo's caching documentation](https://turbo.hotwired.dev/handbook/building#opting-out-of-caching) states that `no-cache` pages are fetched over the network even on restoration visits. This makes the retained meta directive material to the negative test. [Chrome's bfcache documentation](https://developer.chrome.com/docs/web-platform/bfcache-ccns) confirms that HTTP `no-store` alone does not exclude persisted documents.
- Finding 3 has now failed two confirmations. Per AGENTS.md, stop the confirmation loop: split or reduce the change, or ask the user to arbitrate before another review attempt.
- Only `review.md` was modified; no git write commands were run.

## Confirmation 3 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-13
**Reviewed-Commit:** 7a76f5a8482440deeb2ac54f10b39a963a719f62
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Decision 3, the authorization applicability row and task 4.2 retain the segment boundary. The prefix-slug scenario incorporates the redirect capability's anonymous GET/HEAD contract, including no session cookie, while actual UI paths remain protected. Removing the boundary is a specified failing input. |
| 2 | confirmed — Decision 6 and task 2.2 restrict the CSP exclusion to the API segment, leaving `/api-keys` protected. The spec and task require all four headers on both the key list and the response displaying a new key; excluded HTML retains the other three headers. Widening the exclusion is a specified failing input. |
| 3 | confirmed — Decisions 9a and 10 and task 4.9 now separate the negative browser cases: remove both Turbo snapshot protections for Turbo restoration; remove only the `pageshow` clearing for full-document restoration. Each uses a fresh key and retains HTTP `no-store`. The full-document case must demonstrate persisted restoration; a refetch is explicitly not exercised, never a pass. Both negative runs must demonstrate plaintext redisplay, with execution results recorded in the commit body and handoff. These criteria agree with the browser scenario and close the remaining objection about overlapping protections masking the failing input. The server-side once-only guarantee and the limits of client-side mitigation remain explicit. |

### Validation
- Reviewed only `600f8522c1190360f2f91ea2962f67664f7e97e6..7a76f5a8482440deeb2ac54f10b39a963a719f62` and collateral reachable from findings 1–3; no unrelated minor findings were introduced.
- Branch and HEAD match the request; the working tree was initially clean and all source-round findings were dispositioned. This third confirmation follows the user's explicit review request and the arbitration recorded in the proposal.
- Direct path-expression assertions pass for the three prefix slugs, actual UI paths and API exclusion boundary. Repository searches checked the affected claims against the current artifacts and redirect contract.
- `scripts/pregate-verify.sh gate1 add-web-ui` passes, including strict OpenSpec validation. This confirms the planning artifacts; application and browser acceptance, including both demonstrated negative cases, remain required implementation work before Gate 2. An unexercised restoration does not satisfy that acceptance task.
- Rechecked [Turbo's caching documentation](https://turbo.hotwired.dev/handbook/building#opting-out-of-caching), which specifies network retrieval for no-cache restoration visits, and [Chrome's bfcache documentation](https://developer.chrome.com/docs/web-platform/bfcache-ccns), which allows no-store documents under specified conditions. The revised negative cases account for both behaviors.
- Only `review.md` was modified; no git write commands were run.

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-13
**Reviewed-Commit:** 255037463321b36cbd8781d71fc316fd605f100e
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | assets/controllers/rules_editor_controller.js:11–21,36–45; templates/link/_form.html.twig:29,56–57 | The editor changes visibility but never reads or updates the hidden `mode` field that `RulesDocumentMapper::toNode()` uses to choose the submitted document. On a new link, click “Edit as JSON”, enter valid rules and save: mode remains `structured`, so the JSON is silently ignored and empty rows produce null rules. On an existing raw document, `connect()` hides the JSON regardless of its stored mode: it reads `data-invalid` from the wrapper, while that attribute is on the textarea. Without JavaScript, a new link has neither visible raw input nor a usable mode selector, so the promised plain-text fallback is unavailable. Wire the displayed editor to the submitted mode, preserve the document across switches, expose validation errors in the active view, and provide a working no-JavaScript fallback. Add browser coverage of actual toggling and saving, including existing variants and invalid raw input; current WebTestCase tests manually overwrite the hidden mode and bypass the defect. | fixed |
| 2 | major | templates/link/_form.html.twig:49–50; assets/controllers/rules_editor_controller.js:24–33 | “Add rule” cannot create usable form fields: the prototype is escaped with `html_attr` inside a template's content, so `template.innerHTML` contains escaped markup and `insertAdjacentHTML()` inserts text rather than controls. Moreover, the prototype has no `row` target wrapper or remove button; new rows therefore do not increase `rowTargets.length`, so subsequent additions reuse the same index. Using the current row count also collides with surviving indices after a removal. Render a real template fragment with the same row wrapper/actions as existing rows and allocate unique indices independently of row count. Verify adding two rules, removing the first, adding another and saving preserves exactly the displayed rules. | fixed |
| 3 | major | templates/api_key/index.html.twig:56–60; src/Web/ApiKey/ApiKeyPageController.php:110–127 | Revoking a key happens on the first click of an ordinary submit button. There is no confirmation page, disclosure step or confirmation handler, although the web-ui requirement and task 4.8 explicitly require a confirmed revocation. A mistaken click immediately invalidates a credential and can stop its consumers. Add an explicit confirmation step that also works without JavaScript, and verify cancelling leaves the key usable while confirming revokes it. The current test submits the button directly and does not exercise confirmation. | fixed |
| 4 | major | src/Web/ApiKey/ApiKeyPageController.php:39,88–89; templates/api_key/index.html.twig:35–68 | The key list always requests offset 0, limit 50 and provides no way to reach the remaining keys. The limit of ten applies only to active keys, while this query includes revoked and expired keys and sorts newest first. Keep an old active key and create/revoke fifty newer keys: the active key disappears from the UI and cannot be selected for revocation, even though it still authenticates. Make all owned keys reachable (consistent with the proposal's pagination non-goal, an untruncated list is also an option), and add coverage with more than fifty total keys including an old active one. | fixed |

### Validation
- Branch and HEAD match `change/add-web-ui` and the requested commit; the working tree was initially clean. Reviewed the branch diff against main, change artifacts, relevant existing services, templates and tests, and recorded implementation/acceptance evidence in commit messages.
- Executed the current rules-editor methods in Node with stubbed Stimulus targets: an existing raw document becomes hidden on connect; selecting JSON leaves the submitted mode `structured`; repeated additions with no new row targets reuse index 0. Checked the prototype escaping against the installed Twig escaper. These are isolated reproductions, not a full browser run.
- `scripts/pregate-verify.sh gate2 add-web-ui` passes whitespace, strict OpenSpec validation, risk/artifact checks and referenced-path checks, but fails at `make check`: this sandbox cannot access `/var/run/docker.sock`. A direct `make check` attempt fails for the same environmental reason; host PHP is unavailable. PHP/application/browser tests were not rerun successfully in this review.
- The recorded successful Actions SHA is `ae9d02544bbbb81fc260e6e557151cc3bc833967`, not the requested HEAD. The intervening diff includes `.gitattributes` and `tests/Unit/Link/RuleViolationMapperTest.php` as well as handoff/tasks, so the handoff's assertion that only handoff/tasks changed after that run is stale. Obtain fresh check evidence when submitting the fixes for review.
- Only `review.md` was modified; no git write commands were run.

## Confirmation 1 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-14
**Reviewed-Commit:** cd23e545d919870c7ea983bd4cd8c0de8048f760
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — The visible mode radios, raw-document accessor rename, initial visibility and no-JavaScript textarea fix the original mode-selection failures. However, the requested document preservation across switches is still absent: `modeChanged()` only calls `render()`, and neither synchronizes the two representations. Add structured rules on a new link, select JSON, and the textarea remains empty; saving now makes `RulesDocumentMapper::toNode()` return null and drops those rules. Conversely, a stored variants document initializes no rows, so choosing Fields and saving clears it. Transfer the current document when representable, and prevent or explicitly confirm a destructive switch when it is not; cover switching after edits in both directions. The new browser script covers typing a replacement JSON document and reopening variants, but neither preservation across switches nor the requested invalid-raw-input browser case. |
| 2 | changes-requested — The shared row fragment and attribute-encoded Stimulus prototype fix escaped controls and missing row actions; the incrementing counter fixes removal/addition within one rendering. It is still initialized from `form.rules.rows` length in `templates/link/_form.html.twig`, which collides after validation re-rendering. Add rows 0 and 1, remove 0, add 2, and submit with an invalid target URL to receive 422: Symfony retains child names 1 and 2 (`CollectionType` defaults `keep_as_list` to false). The rendered counter is 2, so the next Add rule duplicates row 2's names and a subsequent submission overwrites a displayed rule. Initialize above the maximum existing child index or consistently reindex the rendered collection, and extend acceptance through an invalid submission, correction, another addition and successful save. The unconditional “never reused” claim in the handoff should match that behavior. |
| 3 | confirmed — The list now links to an owner-scoped confirmation page describing the consequence and offering Cancel; GET does not revoke, and the confirming POST retains CSRF and ownership enforcement. The updated test follows the link, verifies the key remains unrevoked on arrival and after cancellation, then confirms revocation. The collateral link-deletion change follows the same confirmation flow and retains its voter and CSRF checks. |
| 4 | confirmed — The controller computes an owner-scoped total, bounded page and offset; Previous/Next links expose all pages over the repository's deterministic newest-first ordering. Coverage creates 56 keys with an old active key behind 55 revoked keys, reaches it through Next and revokes it through the confirmation page. The fixed first-50 truncation is removed. |

### Validation
- Reviewed only `255037463321b36cbd8781d71fc316fd605f100e..cd23e545d919870c7ea983bd4cd8c0de8048f760` and collateral reachable from findings 1–4. All four source-round majors were dispositioned as fixed; there were no blockers. No unrelated minor findings were introduced.
- Branch and HEAD match the request; the working tree was initially clean. Read the affected controllers, form mapping, templates, tests, installed Symfony collection listener/defaults, and relevant planning and execution evidence.
- Executed the current controller methods in Node with stubbed Stimulus targets: changing mode leaves the raw value unchanged, and a re-rendered next index of 2 inserts names for row 2 despite surviving indices 1 and 2. These are isolated reproductions, not full browser/application runs. The server-side loss and sparse-index behavior were traced in the mapper and installed Symfony source.
- `scripts/pregate-verify.sh gate2 add-web-ui` passes whitespace, strict OpenSpec validation, artifact and referenced-path checks, but its `make check` fails because this sandbox cannot access `/var/run/docker.sock`. Application/browser tests were not rerun successfully here.
- The commit and handoff record a successful Actions run on `71f08790e8600f92cbecadda019f857e39983dfa`; the diff from that commit to the reviewed HEAD contains only `handoff.md`, resolving the earlier evidence-freshness discrepancy. The remote run result was not independently verified in this review.
- Only `review.md` was modified; no git write commands were run.

## Confirmation 2 · Gate 2 · Round 1
**Reviewer:** codex
**Date:** 2026-09-14
**Reviewed-Commit:** 9d2fa128af8558f068edd731c821bf7b8dc36d3d
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — The document-preservation implementation is resolved: `LinkPages::switchView()` transfers fields through the storage mapper, parses JSON before converting it to fields, and refuses documents the fields cannot represent. Both controllers rebuild the selected view without saving; the template renders that view and its submitted mode, including the raw textarea and errors, and the switch works as a plain POST. However, the explicitly requested invalid-raw-input browser coverage remains absent, as already noted in Confirmation 1. `tests/Acceptance/rules-editor.mjs` exercises an invalid structured target and a valid variants document refused by the fields view; neither submits invalid raw JSON. The server-side `LinkEditTest` covers malformed JSON, but cannot establish the requested browser behavior. Add and record the browser case: select JSON, submit invalid raw input, verify the 422 displays the error and preserves the entered text in the active JSON view while stored rules stay unchanged, then correct and save. This remaining objection concerns the required verification, not a demonstrated failure of the new conversion logic. |
| 2 | confirmed — The prototype uses the same fieldset, row target and remove action as existing rows. The template now initializes the counter above the maximum existing child name, and `addRow()` increments it independently of row count. Surviving indices 1 and 2 therefore allocate 3 after a validation re-render. The extended acceptance script and recorded positive/negative runs cover removal, addition, an invalid submission, correction, another addition and saving the displayed rules. |
| 3 | confirmed — Revocation remains an owner-scoped GET confirmation page with a consequence statement and Cancel link, followed by a CSRF-protected POST through the owner-scoped revocation service. The tests cover arrival, cancellation and confirmation. The collateral link-deletion confirmation retains its voter and CSRF checks and performs no deletion on GET. |
| 4 | confirmed — The owner-scoped total, bounded page and offset, and Previous/Next links expose every page over deterministic newest-first repository ordering. The test with 55 newer revoked keys reaches and revokes the old active key on the second page. The first-50 truncation remains resolved. |

### Validation
- Reviewed only `255037463321b36cbd8781d71fc316fd605f100e..9d2fa128af8558f068edd731c821bf7b8dc36d3d` and collateral reachable from findings 1–4. Branch and HEAD match the request; the working tree was initially clean. All four source-round majors were dispositioned as fixed; there were no blockers. No unrelated minor findings were introduced.
- Read the affected mapping, forms, controllers, templates, browser and web tests, and related artifacts and execution evidence. Repository searches checked the affected editor, validation, switching and fallback claims.
- Executed the current Stimulus controller in Node with stubbed targets and the template's maximum-index formula: surviving indices 1 and 2 allocate distinct indices 3 and 4. This is an isolated check, not a browser run.
- `scripts/pregate-verify.sh gate2 add-web-ui` passes whitespace, strict OpenSpec validation, artifact and referenced-path checks. Its `make check` fails because this sandbox cannot access `/var/run/docker.sock`; application/browser tests were not rerun successfully here.
- The handoff records successful Actions run 34819584049 on `ba246317bcc9a41594fa0eaab7c00ced28922c53`; only `handoff.md` differs between that commit and the reviewed HEAD. The remote run result was not independently verified here.
- Finding 1 has now failed two confirmations. Per AGENTS.md, stop the confirmation loop: split or reduce the change, or ask the user to arbitrate before another review attempt.
- Only `review.md` was modified; no git write commands were run.
