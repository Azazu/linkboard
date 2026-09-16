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

## Confirmation 2 · Gate 1 · Round 2
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** 03b1302a796e03b22d8fb8ba60de660a8848a9e1
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — The proposal and decision 6a correct the legacy state to encryption with an empty passphrase, distinguish it from an unencrypted key, and explicitly require encryption with the effective nonempty passphrase: reject an empty passphrase and accept the effective one. Task 9.2 covers both rejected states and byte-for-byte preservation of the compliant state; tasks 9.4–9.5 require authentication evidence. This resolves the detection defect at Gate 1. The references to both states as "unusable" in task 9.2 and the handoff remain imprecise: an unencrypted key violates the chosen encryption guarantee but can still sign. They do not invalidate the now-explicit detection and verification contract. |
| 2 | confirmed — Decision 6a and task 9.1 now require the bootstrap's precedence: `.env.test`, followed by `.env.test.local`. Task 9.2 applies replacement and preservation to the passphrase in force, task 9.4 verifies generation and authentication with a nonempty local override, and task 9.5 verifies the default setup. Read together, these requirements apply the same effective value to generation and existing-key handling; implementation evidence remains due at Gate 2. |
| 3 | changes-requested (wording remainder, fixed in the following commit) — Nonblocking remainder of the original minor finding: design.md, Risks / Trade-offs, still says the proposal names the Makefile as untouched, immediately after authorizing its jwt-keys target. Remove that stale sentence or restriction. The tasks' scope declaration and the design's key-write boundary are corrected; decision 6a and task 9.3 now require checking the public/private pair on every invocation and repairing a mismatch on retry. This minor wording remainder is at the executor's discretion and does not prevent confirmation of the major findings. |

### Validation

- Reviewed only the requested diff from `668f0342cb784d587d34f8156157f313e6d734a2` to the Reviewed-Commit and collateral material reachable from round 2's findings. Both major findings were marked `fixed`; there were no blockers or open source-round findings.
- Verified the requested branch and HEAD and an initially clean working tree. Checked the revised contract against the bootstrap, Makefile, JWT configuration, and installed Lexik generator and JWT signer source; no implementation of section 9 is claimed by this confirmation.
- Reproduced all three OpenSSL key states entirely in memory with disposable fixture values. Empty/effective passphrase exit codes were respectively `0/0` for an unencrypted key, `0/1` for empty-passphrase encryption, and `1/0` for effective-passphrase encryption. No repository keys or environment files were read or changed by the reproduction.
- `git diff --check` for the requested range passed. `scripts/pregate-verify.sh gate1 harden-quality-and-docs` passed, including strict OpenSpec validation, with zero warnings. Application tests and Gate 2 checks were not run for this planning confirmation.

## Round 1 · Gate 2
**Reviewer:** codex
**Date:** 2026-09-16
**Reviewed-Commit:** c69f769782ee9688d5092ae4e3a5b74d25bef826
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | major | scripts/test-jwt-keys.sh:34–41 | The generator does not resolve the same effective passphrase as the bootstrap: its sed parser implements only a subset of Dotenv syntax. Reproduced with the disposable input `JWT_PASSPHRASE=review-fixture # local comment`: the installed Symfony Dotenv returns `review-fixture`, while the actual extraction lines from this script return `review-fixture # local comment`. A valid local override with such a comment therefore makes the target generate and preserve a key encrypted with a different password from the one authentication uses. Variable expansion, `export` declarations and an unquoted empty override also diverge. Use the same Dotenv parsing and precedence as the bootstrap, and verify generation and preservation with a valid commented or expanded local override, including authentication and a regression that fails with the current parser. | fixed |
| 2 | major | docs/how-to/benchmarks.md:64–76; tasks.md:2.2–2.4 | The published report benchmark is not runnable from the documented host shell: the bare host `curl` addresses `http://nginx`, which is a Compose service name, whereas the published host endpoint is `http://localhost:8082`. The recipe also never establishes `TOKEN`, `LINK`, `FROM` or `TO`, and contains neither the fifteen-sample loop nor the other eight report requests or percentile calculation underlying the table. Thus a fresh reader cannot reproduce the principal benchmark evidence despite task 2.4 claiming the commands were executed exactly as printed. Supply the actual executable recipe, including the execution context, fixture identifiers and authentication setup, cache clearing for each sample, successful-response checks and sample aggregation for the reported endpoints; run that exact recipe and reconcile its evidence. | fixed |
| 3 | minor | docs/adr/ADR-002-cqrs-lite-click-and-analytics.md · Decision; docs/explanation/architecture.md · The two paths | Both new explanations say the write handler persists an entity, but `src/Click/Handler/ClickRecordedHandler.php` explicitly hydrates no entities: it uses a DBAL transaction to INSERT the click and UPDATE the link counter. ADR-002 also says every logging failure becomes a queued message, although `MessengerClickRecorder` catches dispatch failures and loses that record. These descriptions obscure the actual CQRS boundary and failure trade-off this documentation is intended to explain. Correct the new documentation to distinguish direct SQL writes, queued-handler retries and records lost before enqueueing, following the repository's ADR amendment policy where applicable. | fixed |

### Validation

- Verified branch `change/harden-quality-and-docs`, the requested HEAD and an initially clean working tree. Reviewed the diff against `main`, change artifacts, `AGENTS.md` and `openspec/config.yaml`; checked affected claims against the Makefile, Compose/CI configuration, installed Dotenv source, architecture rules, click handler/recorder and analytics implementation. The high risk tier is appropriate.
- Reproduced finding 1 without changing environment files or keypairs: parsed a disposable string through the installed Symfony Dotenv in the PHP container, then ran the actual shell extraction lines with that same string on stdin. The outputs differ as recorded above.
- `git diff --check main...HEAD` and `sh -n scripts/test-jwt-keys.sh` passed. The Gate 2 mechanical floor passed strict OpenSpec validation, task/path checks and Markdown link checks, but failed when `make check` attempted its first Docker command: access to `/var/run/docker.sock` was denied. Consequently this review does not independently establish a green style/static-analysis/test run; this is a verification-environment limitation, not an additional code finding. The executor's recorded green run was considered as supplied evidence.
- Did not rerun benchmarks, reset fixtures, regenerate screenshots or replace local keys. Modified only this review record and ran no git write commands.
