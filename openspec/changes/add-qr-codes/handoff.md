# Handoff — add-qr-codes

**Updated:** 2026-09-12 · claude
**State:** implementing
**Branch:** change/add-qr-codes

## Done this session
- Branch `change/add-qr-codes` created from `main` (`b6f29a0`, after the archive of `add-analytics-read-model`); change scaffolded with `openspec new change`.
- Proposal (tier `high`: new dependency `endroid/qr-code` ^6.1 + a new owner/admin endpoint reusing `LinkVoter`), specs (`qr-codes` new: formats, headers, `format` parameter, authorization boundary; `api-docs` modified: the QR operation as the declared exception of JSON-only), design (API Platform operation with a custom controller on `LinkResource`, `outputFormats` svg/png, `?format` selects and `Accept` only gates, renderer over the library's builder at 512 px, SVG byte snapshot, applicability table), tasks (Gate 1, dependency + renderer, operation + API tests, docs, wrap-up with the green-run task before Gate 2). `openspec validate add-qr-codes --strict` passes.

**Security-relevant parts for review:** the dependency addition (three packages, pinned by the lock, `composer audit` in task 1.1) and the authorization boundary of the new endpoint (provider 404 first, then `LINK_VIEW` — the same sequence as `GET /links/{id}`).

- Gate 1 round 1 (`af515f7`, Reviewed-Commit `194022e`): changes-requested — blocker: a custom `controller` bypasses the provider/security/validation chain under the default `use_symfony_listeners: false`; minors: enum `from()` name clash, 404-before-auth versus the firewall's 401. Fixed in the following commit: rendering moves into `LinkQrProcessor` on a `write: true` GET operation served by `MainController` (verified in vendor), `fromQuery`, 401 for anonymous callers whatever the id. Statuses → fixed.

- Gate 1 passed: Confirmation 1 (`b3605f2`, Reviewed-Commit `f99b7cb`) — the processor architecture confirmed against the installed API Platform source. Task 0.1 ticked.
- Implemented (2026-09-12): `e05a709` — `endroid/qr-code` 6.1.3 (+ `bacon/bacon-qr-code` v3.1.1, `dasprid/enum` 1.0.7), `composer audit` clean; `6a0ee53` — `QrFormat`, `QrCodeRenderer` (code area 480 + margin 16 = 512 px; the library's `size` is the code area, a first render at 512 came out 544), SVG snapshot fixture, 4 unit tests; `b7c4837` — `LinkQrProcessor` and the `link_qr` Get on `LinkResource` (`write: true`, `processor`, `security` `LINK_VIEW`, `outputFormats` svg/png, `format` parameter with schema enum + `Choice`), `LinkQrTest` 6 tests / 61 assertions, dev-stack smoke of every case; `cf89e85` — how-to and FR-QR-1. `make check` green: 569 tests / 7409 assertions. Tasks 1.1–4.1 ticked with evidence; every guard has a demonstrated failing input in its commit body (notable: a changed margin does not alter the SVG — `RoundBlockSizeMode::Margin` absorbs it — and removing the `Choice` constraint alone changes nothing because API Platform derives one from the schema `enum`).

**Security-relevant parts for review:** the dependency change (`e05a709`: three packages, lock-pinned, audit clean, no runtime I/O) and the authorization boundary of the new endpoint (`b7c4837`: firewall 401 first, then `LinkItemProvider` 404, then `LinkVoter::VIEW` — the same sequence as `GET /links/{id}`; the `format` query parameter reaches the enum only after declared validation).

## Next step
Task 4.2: the user pushes `change/add-qr-codes`; the executor confirms a completed, successful Actions run whose `head_sha` equals `git rev-parse HEAD` and records it here. Then task 4.3: `scripts/pregate-verify.sh gate2 add-qr-codes`, `scripts/gate-run.sh add-qr-codes 2 full`.

## Blockers
None.
