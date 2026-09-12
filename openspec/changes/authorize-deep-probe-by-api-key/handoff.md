# Handoff — authorize-deep-probe-by-api-key

**Updated:** 2026-09-12 · claude
**State:** proposing
**Branch:** change/authorize-deep-probe-by-api-key

## Done this session
- Branch `change/authorize-deep-probe-by-api-key` created from `main` (`dce4bc9`, after the archive of `add-api-keys-and-rate-limiting`); change scaffolded with `openspec new change`.

## Next step
`/opsx:propose authorize-deep-probe-by-api-key` — roadmap row 10a, split out of `add-api-keys-and-rate-limiting` by user arbitration (2026-09-12): the `prod` deep health probe becomes available to a valid admin API key — a bounded key lookup with an enforceable total deadline, fail-closed 404, refused/delayed/stalled lookups tested end to end. Its Gate 1 starts from the findings of that change's Gate 1 confirmations 1–2 (libpq's minimum `connect_timeout` is 2 s; a connect timeout and a statement timeout do not compose into a total deadline; the combined delay must be tested). Tier `high`.

## Blockers
None.
