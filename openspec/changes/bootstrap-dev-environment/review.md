# Review — bootstrap-dev-environment

## Round 1 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-08
**Reviewed-Commit:** 39fd58e2f60ffb9194a5c8be5127910caa658d29
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | design.md: Decision 3 and Risks; tasks.md: 3.2, 4.1 | The planned host-permission override is not executable as documented: `UID` is Bash's readonly special variable, so `UID=$(id -u) GID=$(id -g) make init` emits `UID: readonly variable`. `.env.local` is deliberately not read by Compose, so it cannot provide the promised alternative either. Replace these inputs with non-reserved names such as `HOST_UID`/`HOST_GID` consistently in `.env`, Compose build args, the Dockerfile and docs, and add a verification using values other than 1000. | fixed |
| 2 | major | proposal.md: Impact; design.md: Decision 4 and Risks; tasks.md: 1.1, 2.1 | The plan calls the extension-installer image “pinned” but does not name its tag or digest, while the new actionlint floor explicitly uses `rhysd/actionlint:latest`. These floating OCI inputs make both the image build and the CI-parser check non-reproducible; the stated reviewed-bump guarantee cannot be enforced. Declare immutable version tags or digests in the design/tasks and use those exact references in the runnable commands and Dockerfile. | fixed |

## Confirmation 1 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-08
**Reviewed-Commit:** 15ec00718453ed515bcc74727596b53cc42e7534
**Verdict:** changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | changes-requested — `HOST_UID`/`HOST_GID` replaces the Bash-reserved names, but task 2.3 is scheduled before task 3.1 adds those build args to Compose, so it is not feasible at its lifecycle point. Its required `touch var/.probe` also cannot run on the clean checkout, where `var/` does not exist and the artificial uid 1234 cannot own the host bind mount. Move the Compose wiring before this verification and make the non-1000 test self-contained (or verify the image uid without a bind-mounted workspace). |
| 2 | confirmed — every newly introduced OCI input and the actionlint command now name exact tag-and-digest references, so planned builds and parser checks are reproducible. |

## Confirmation 2 · Gate 1 · Round 1
**Reviewer:** codex
**Date:** 2026-09-08
**Reviewed-Commit:** 6f69f0a4914cb30d46031e70ab9dbf4d44fb6676
**Verdict:** confirmed

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed — tasks 2.1 and 2.3 now build and run the image directly with `HOST_UID`/`HOST_GID` build arguments, so the non-1000 check is feasible before Compose wiring and does not depend on a host bind mount or pre-existing `var/`. |
| 2 | confirmed — previously confirmed in Confirmation 1; unchanged by this diff. |

## Round 2 · Gate 1
**Reviewer:** codex
**Date:** 2026-09-08
**Reviewed-Commit:** bb4e446e780586d597300f34e963cdf3c2963398
**Verdict:** changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | design.md: Decision 9; tasks.md: 3a.1 | The planned guard only says that each target starts with `test -f composer.json || { ...; exit 0; }`. Make runs separate recipe lines in separate shells, so an `exit 0` in that guard returns only from its line and Make then executes the following `vendor/bin/php-cs-fixer`, PHPStan or PHPUnit line. The empty-app `make check` will still fail unless the guard and actual invocation are one shell conditional (or an equivalent Make-level conditional). Specify that mechanism and retain the no-composer success plus composer-present failing-input checks. | fixed |
