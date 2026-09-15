# Review — harden-quality-and-docs

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-15
**Reviewed-Commit:** e5a7964fbfda345d7caed1c55721cac1c1646aad
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | openspec/changes/harden-quality-and-docs/tasks.md · task 9.2 | Task 9.2 includes running Gate 2 and fixing/confirming its findings as part of its completion. However, `scripts/gate-run.sh` runs `scripts/pregate-verify.sh gate2` before invoking the reviewer, and that floor rejects any unchecked task. Keeping 9.2 truthful therefore prevents Gate 2 from starting; checking it beforehand would claim a review and confirmation that have not occurred. This violates the Gate 1 requirement that each task be feasible at its lifecycle point. Limit the checkbox to the pre-review CI evidence and place the Gate 2 invocation and subsequent finding disposition in an uncheckboxed lifecycle/handoff instruction, so every task can truthfully be complete before Gate 2 is requested. | fixed |

### Validation

- Confirmed the current branch is `change/harden-quality-and-docs` and HEAD is the reviewed commit above; the working tree was clean before this review.
- Read `AGENTS.md`, `openspec/config.yaml`, the change proposal, design, tasks, handoff and `.openspec.yaml`. The explicit `skip_specs: true` matches the stated absence of changed application requirements.
- Checked the proposed bootstrap boundary against `tests/bootstrap.php`, `phpunit.dist.xml`, `.env.test` and the CI environment configuration. The high risk tier is appropriate.
- `scripts/pregate-verify.sh gate1 harden-quality-and-docs` passed, including strict OpenSpec validation, with zero warnings. Gate 2 implementation validation remains for the code review.

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-15
**Reviewed-Commit:** cb1e666436a585b80554ee4810a520fc4427fa0f
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — Task 9.2 now covers only pre-review CI evidence. Gate 2 invocation and finding disposition appear in a separate, uncheckboxed lifecycle section after task completion. This removes the circular dependency on the Gate 2 floor's requirement that every task be checked. The handoff records the same correction. |

### Validation

- Reviewed only the diff from `e5a7964fbfda345d7caed1c55721cac1c1646aad` to the reviewed commit and collateral references relevant to finding 1; it is the sole blocker/major finding in round 1 and is marked `fixed`.
- Verified the branch and HEAD match the requested review target and the working tree was initially clean.
- Checked the task lifecycle against `scripts/gate-run.sh` and `scripts/pregate-verify.sh`; checked related change artifacts and roadmap references for a surviving Gate 2 checkbox dependency. No such dependency remains in this change.
- `git diff --check` for the requested commit range passed. No implementation checks were run for this planning-only confirmation.
