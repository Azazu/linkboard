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
