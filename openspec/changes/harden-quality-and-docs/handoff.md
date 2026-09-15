# Handoff — harden-quality-and-docs

**Updated:** 2026-09-15 · claude
**State:** proposing
**Branch:** change/harden-quality-and-docs

## Done this session
- Branch `change/harden-quality-and-docs` created from `main` (`f6e9241`, after the archive of `polish-api-and-openapi`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 13 and §7 of `docs/explanation/requirements.md`: README with screenshots, an architecture diagram and benchmarks; a PHPStan strictness sweep; migration down/up in CI; architecture tests (NFR-QA-2); an ADR index. Exit criterion the roadmap records: the README is complete and the CI matrix green.
- Tier `low` as the roadmap declares it — its minimum. The proposal states the tier and its reasoning. Two parts of the scope could raise it and the proposal says so before deciding: **migration down/up in CI** touches the verifier and reversibility, and **a PHPStan strictness sweep** can change production code while claiming not to. Whatever the reasoning concludes, it is written in the proposal before implementation, and the tier is raised at the first task that turns out to touch a security boundary or the verifier's floor.

## Next step
`/opsx:propose harden-quality-and-docs` — proposal with the tier decided and argued, the spec deltas the scope needs, design if the change warrants one, and tasks. This is the last row of Stage 4.

## Blockers
None.
