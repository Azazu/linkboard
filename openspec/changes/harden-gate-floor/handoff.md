# Handoff — harden-gate-floor

**Updated:** 2026-09-16 · claude
**State:** proposing
**Branch:** change/harden-gate-floor

## Done this session
- Branch `change/harden-gate-floor` created from `main` (`3e7e400`, the archive of `harden-quality-and-docs`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 13a: **PHPStan level 9 over `src`**, and a **migration down/up job in CI**. Both were split out of row 13 by the user on 2026-09-15 for the same reason: they change the gate floor and the verifier, which AGENTS.md makes a `high` trigger, while row 13's documentation and architecture tests did not.
- Tier is expected to be `high` and argued in the proposal rather than inherited: the change edits `phpstan.dist.neon` and `.github/workflows/ci.yml`, the two files every later change is judged by, and `harden-quality-and-docs` stated both as untouched precisely because they belong here.

## Next step
`/opsx:propose harden-gate-floor`. Three things the proposal must establish rather than assume:

1. **The baseline, re-measured.** The 361 errors at level 9 (67 in `src`, 294 in `tests`) were counted on 2026-09-15, before three changes landed. Count again before scoping anything.
2. **What "level 9 over `src`" means for `tests/`.** Level 9 is about `mixed`, and almost all of the earlier count was decoded JSON in tests. Whether `tests/` moves too, stays at its current level, or gets a bounded ignore list is the proposal's decision, with the reason written down.
3. **What a migration down/up job actually proves.** A job that runs every migration down and up again has to say what failure it is there to catch and on what data, or it is a green tick that means nothing.

A scope question the user has not settled yet: `openspec/config.yaml` still opens with `Stage: scaffold; application code arrives through OpenSpec changes`, which reaches every agent — Codex at the gates included — and contradicts the README this series just rewrote. `harden-quality-and-docs` swept for that word and missed this file. Folding the one-line correction into this change was offered as the alternative to a consented direct commit on `main`; it is not in scope until the user says so.

## Blockers
None.
