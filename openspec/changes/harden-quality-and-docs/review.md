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

## Round 2 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** 668f0342cb784d587d34f8156157f313e6d734a2
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | proposal.md · key-generation defect; design.md · decision 6a; tasks.md · 9.1–9.2 | The proposed detection does not detect the stated legacy state. An unencrypted private key opens successfully even when a nonempty passphrase is supplied: `openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 2>/dev/null` piped into `openssl pkey -passin pass:review-fixture-only -check -noout` returns `Key is valid` and exit 0. The installed JWT signer also passes the passphrase to `openssl_pkey_get_private`; merely supplying a passphrase does not make an unencrypted key unusable. Consequently, checking whether the key opens will preserve exactly the unencrypted key the proposal says must be replaced, and the planned deliberately mismatched encrypted key does not reproduce that case. Establish the actual failing setup/authentication input, correct the causal claim throughout the artifacts, and specify a detection and verification mechanism that matches the intended guarantee (encryption with the effective passphrase versus usable signing/verification). Include the actual old-target unencrypted state in verification. | fixed |
| 2 | major | design.md · decisions 6 and 6a; tasks.md · section 9 | The two entry points still have different configuration authorities. Decision 6 explicitly applies `.env.test.local` after `.env.test`, while 6a requires generation and validation against `.env.test` alone. With a local `JWT_PASSPHRASE` override, the target will generate an encrypted key using the committed value and the suite will try to open it using the local value; repeated target runs preserve the divergence. Resolve the effective test passphrase with the same precedence as the bootstrap, and add verification with a nonempty local override as well as without that file, for both generation and existing-key handling. | fixed |
| 3 | minor | tasks.md · introductory untouched list; design.md · Applicability and Risks / Trade-offs | Scope reconciliation is incomplete: tasks still declares the Makefile untouched, and the design's scope-creep paragraph says the same even though decision 6a and section 9 authorize editing it. The applicability table also says there is no external effect and nothing writes, despite replacing two key files. Remove the stale scope restrictions and describe the key-write failure boundary, including whether a retry repairs a missing or mismatched public key after a partial replacement; checking only that the private key opens does not establish a usable pair. | fixed |

### Validation

- Verified branch `change/harden-quality-and-docs`, the requested HEAD, and an initially clean working tree.
- Read the change proposal, design, tasks, existing review, handoff and `.openspec.yaml`, together with `AGENTS.md` and `openspec/config.yaml`. The high risk tier remains appropriate; `skip_specs: true` is consistent with no changed application requirements.
- Checked the environment and setup boundaries against `tests/bootstrap.php`, `tests/Integration/TestEnvironmentTest.php`, `phpunit.dist.xml`, the Makefile, CI configuration, JWT configuration and installed Lexik key-generation / JWT signing source. The installed generator writes private and public files separately.
- Ran the disposable in-memory OpenSSL reproduction in finding 1; no repository key or environment file was read or changed by it.
- `scripts/pregate-verify.sh gate1 harden-quality-and-docs` passed, including strict OpenSpec validation, with zero warnings. This is a planning review; application checks and Gate 2 implementation validation were not run.
