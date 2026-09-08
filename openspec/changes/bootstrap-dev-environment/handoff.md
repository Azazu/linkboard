# Handoff — bootstrap-dev-environment

**Updated:** 2026-09-08 · claude
**State:** awaiting-gate-2
**Branch:** change/bootstrap-dev-environment

## Done this session
- Diagnosed the red CI on `main`: job-level `hashFiles()` in `ci.yml` is an unparseable expression (GitHub annotation, three runs, zero jobs).
- Proposal (tier `high`, CI-infrastructure trigger; CI fix is task 1), design with applicability table, tasks written; `skip_specs: true`.
- Roadmap row 1 and specification §7 row 1 updated to `high` with a realistic exit criterion.
- Gate 1 Round 1: changes-requested (blocker: `UID`/`GID` are Bash read-only names; major: unpinned OCI inputs). Fixed: `HOST_UID`/`HOST_GID` everywhere plus a non-1000 build test (task 2.3); every new OCI input pinned by tag and digest resolved from the registries (php 8.3.33-fpm-alpine, composer 2.10.3, extension installer 2.11.12, actionlint 1.7.12). Confirmation 1: #2 confirmed, #1 changes-requested (task 2.3 not feasible before Compose wiring, bind-mount probe impossible on a clean checkout). Fixed: tasks 2.1 and 2.3 now use plain `docker build`/`docker run` against the image only, no Compose args, no bind mount. Confirmation requested again → Gate 1 confirmed (a7b60df).
- Implemented tasks 1.1–1.2, 2.1–2.3, 3.1–3.3, 4.1–4.2, 5.1 (commits 0f07eb1, 58bc391, d62c4a6). First Gate 2 floor run: `make check` cannot pass without an application.
- Scope added with the user's decision: Makefile guard (proposal item 6, design decision 9, tasks 3a) — Gate 1 re-requested for the scope change; the guard is implemented only after it is approved.
- Gate 1 Round 2: changes-requested (per-line shell guard would not stop later recipe lines). Fixed in design/tasks: Make-level `ifeq`/`ifdef` conditional so each recipe is either SKIP or the real tool. Confirmation requested.
- Gate 1 Round 2 confirmed (c5a48c4). Guard implemented (e103cbd): `make check` is green-with-SKIP on the empty app, failing input recorded in the commit body. Gate 2 floor passes; Gate 2 requested.
- Task 1.3 accepted: CI run https://github.com/Azazu/linkboard/actions/runs/34252812345 on bb4e446 — workflow success, detect success, php skipped.

## Next step
User runs Codex for Gate 2 (code diff `git diff main...change/bootstrap-dev-environment`), then `scripts/gate-run.sh bootstrap-dev-environment 2 record`. On approval: `/git:merge bootstrap-dev-environment`, then `/opsx:archive`. Note for the reviewer: `make check` passes via the Makefile SKIP guard — no PHP application exists yet (arrives with `scaffold-symfony-app`). Security-relevant surface: CI workflow (`.github/workflows/ci.yml`), Docker image and compose (`.docker/`, `docker-compose.yml`) — flagged per AGENTS.md.

## Blockers
None.
