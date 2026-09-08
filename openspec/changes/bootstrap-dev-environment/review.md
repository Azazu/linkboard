# Review — bootstrap-dev-environment

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-08
**Reviewed-Commit:** 39fd58e2f60ffb9194a5c8be5127910caa658d29
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | design.md: Decision 3 and Risks; tasks.md: 3.2, 4.1 | The planned host-permission override is not executable as documented: `UID` is Bash's readonly special variable, so `UID=$(id -u) GID=$(id -g) make init` emits `UID: readonly variable`. `.env.local` is deliberately not read by Compose, so it cannot provide the promised alternative either. Replace these inputs with non-reserved names such as `HOST_UID`/`HOST_GID` consistently in `.env`, Compose build args, the Dockerfile and docs, and add a verification using values other than 1000. | open |
| 2 | major | proposal.md: Impact; design.md: Decision 4 and Risks; tasks.md: 1.1, 2.1 | The plan calls the extension-installer image “pinned” but does not name its tag or digest, while the new actionlint floor explicitly uses `rhysd/actionlint:latest`. These floating OCI inputs make both the image build and the CI-parser check non-reproducible; the stated reviewed-bump guarantee cannot be enforced. Declare immutable version tags or digests in the design/tasks and use those exact references in the runnable commands and Dockerfile. | open |
