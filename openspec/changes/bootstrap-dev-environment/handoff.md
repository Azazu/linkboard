# Handoff — bootstrap-dev-environment

**Updated:** 2026-09-08 · claude
**State:** awaiting-gate-1
**Branch:** change/bootstrap-dev-environment

## Done this session
- Diagnosed the red CI on `main`: job-level `hashFiles()` in `ci.yml` is an unparseable expression (GitHub annotation, three runs, zero jobs).
- Proposal (tier `high`, CI-infrastructure trigger; CI fix is task 1), design with applicability table, tasks written; `skip_specs: true`.
- Roadmap row 1 and specification §7 row 1 updated to `high` with a realistic exit criterion.
- Gate 1 Round 1: changes-requested (blocker: `UID`/`GID` are Bash read-only names; major: unpinned OCI inputs). Fixed: `HOST_UID`/`HOST_GID` everywhere plus a non-1000 build test (task 2.3); every new OCI input pinned by tag and digest resolved from the registries (php 8.3.33-fpm-alpine, composer 2.10.3, extension installer 2.11.12, actionlint 1.7.12). Confirmation 1: #2 confirmed, #1 changes-requested (task 2.3 not feasible before Compose wiring, bind-mount probe impossible on a clean checkout). Fixed: tasks 2.1 and 2.3 now use plain `docker build`/`docker run` against the image only, no Compose args, no bind mount. Confirmation requested again.

## Next step
User runs Codex with the printed confirmation prompt (Round 1), then `scripts/gate-run.sh bootstrap-dev-environment 1 record`. On approval: `/opsx:apply bootstrap-dev-environment` (task 1.1 first). Security-relevant surface: CI workflow (`.github/workflows/ci.yml`) — flagged for review per AGENTS.md.

## Blockers
None.
