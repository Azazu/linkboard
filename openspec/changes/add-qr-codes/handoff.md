# Handoff — add-qr-codes

**Updated:** 2026-09-11 · claude
**State:** proposing
**Branch:** change/add-qr-codes

## Done this session
- Branch `change/add-qr-codes` created from `main` (`b6f29a0`, after the archive of `add-analytics-read-model`); change scaffolded with `openspec new change`.
- Proposal (tier `high`: new dependency `endroid/qr-code` ^6.1 + a new owner/admin endpoint reusing `LinkVoter`), specs (`qr-codes` new: formats, headers, `format` parameter, authorization boundary; `api-docs` modified: the QR operation as the declared exception of JSON-only), design (API Platform operation with a custom controller on `LinkResource`, `outputFormats` svg/png, `?format` selects and `Accept` only gates, renderer over the library's builder at 512 px, SVG byte snapshot, applicability table), tasks (Gate 1, dependency + renderer, operation + API tests, docs, wrap-up with the green-run task before Gate 2). `openspec validate add-qr-codes --strict` passes.

**Security-relevant parts for review:** the dependency addition (three packages, pinned by the lock, `composer audit` in task 1.1) and the authorization boundary of the new endpoint (provider 404 first, then `LINK_VIEW` — the same sequence as `GET /links/{id}`).

## Next step
Gate 1 (high tier): `scripts/pregate-verify.sh gate1 add-qr-codes`, then `scripts/gate-run.sh add-qr-codes 1 full`; findings via `/workflow:fix-findings`. Then `/opsx:apply add-qr-codes`.

## Blockers
None.
