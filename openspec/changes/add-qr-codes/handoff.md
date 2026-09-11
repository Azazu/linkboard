# Handoff — add-qr-codes

**Updated:** 2026-09-11 · claude
**State:** implementing
**Branch:** change/add-qr-codes

## Done this session
- Branch `change/add-qr-codes` created from `main` (`b6f29a0`, after the archive of `add-analytics-read-model`); change scaffolded with `openspec new change`.
- Proposal (tier `high`: new dependency `endroid/qr-code` ^6.1 + a new owner/admin endpoint reusing `LinkVoter`), specs (`qr-codes` new: formats, headers, `format` parameter, authorization boundary; `api-docs` modified: the QR operation as the declared exception of JSON-only), design (API Platform operation with a custom controller on `LinkResource`, `outputFormats` svg/png, `?format` selects and `Accept` only gates, renderer over the library's builder at 512 px, SVG byte snapshot, applicability table), tasks (Gate 1, dependency + renderer, operation + API tests, docs, wrap-up with the green-run task before Gate 2). `openspec validate add-qr-codes --strict` passes.

**Security-relevant parts for review:** the dependency addition (three packages, pinned by the lock, `composer audit` in task 1.1) and the authorization boundary of the new endpoint (provider 404 first, then `LINK_VIEW` — the same sequence as `GET /links/{id}`).

- Gate 1 round 1 (`af515f7`, Reviewed-Commit `194022e`): changes-requested — blocker: a custom `controller` bypasses the provider/security/validation chain under the default `use_symfony_listeners: false`; minors: enum `from()` name clash, 404-before-auth versus the firewall's 401. Fixed in the following commit: rendering moves into `LinkQrProcessor` on a `write: true` GET operation served by `MainController` (verified in vendor), `fromQuery`, 401 for anonymous callers whatever the id. Statuses → fixed.

- Gate 1 passed: Confirmation 1 (`b3605f2`, Reviewed-Commit `f99b7cb`) — the processor architecture confirmed against the installed API Platform source. Task 0.1 ticked.

## Next step
`/opsx:apply add-qr-codes` — tasks 1.1 (dependency), 1.2 (renderer + snapshot), 2.1/2.2 (operation + API tests), 3.1 (docs), 4.x (check, push + green run, Gate 2).

## Blockers
None.
