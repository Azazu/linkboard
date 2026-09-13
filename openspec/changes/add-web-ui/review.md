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
