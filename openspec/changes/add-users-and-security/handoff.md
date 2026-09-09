# Handoff — add-users-and-security

**Updated:** 2026-09-09 · claude
**State:** awaiting-gate-2
**Branch:** change/add-users-and-security

## Done this session
- Change started: branch and scaffold created (after the `fix-ci-php-job` archive; `main` run 34321101954 green).
- Proposal (tier high), four delta specs (user-accounts, authentication, user-administration, api-error-format 422 shape), design with applicability table, 8 task blocks. `lexik/jwt-authentication-bundle` 3.2 verified compatible with Symfony 8 on Packagist. Gate 1 Round 1: changes-requested, three blockers, all addressed in the artifacts: (1) prod deep probe stays an unconditional 404, authorized later by an admin API key in `add-api-keys-and-rate-limiting` — health spec amended (MODIFIED delta), tests planned; (2) JWT keys isolated per environment (`config/jwt/<env>/`), `make jwt-keys` generates dev and test, `lexik:jwt:check-config` per env with a failing input; (3) request-path limiter on array storage in test, a twin limiter `auth_ip_redis` on a Redis pool for the integration test, prod wiring checked by `debug:container`. Confirmation 1 confirmed (bce1825) — Gate 1 passed.
- Review mode switched to `auto` on main (182d1d7, user consent) and merged into this branch: from here the executor runs `scripts/gate-run.sh add-users-and-security 2 full` itself; on a Codex usage-limit failure the executor stops and reports.

- Implemented blocks 1–8 (commits 7f90734 … see git log): users + migration, registration (API + web), JWT with problem details, /me, blocking, admin list/block/unblock with audit, auth rate limit on Redis, bare web pages, docs. make check: cs 0, stan 0, 65 tests / 323 assertions. Deviations recorded in proposal/design: symfony/expression-language added; email in html5 mode (no egulias dependency); test limiter on an in-process storage instead of an array pool (kernel.reset clears pools between requests); JSON pagination envelope normalizer added (plain json has none in API Platform).

- Task 8.4: branch run https://github.com/Azazu/linkboard/actions/runs/34327044243 on head cdea818 — workflow, detect, php all success (run list by head SHA + jobs endpoint). Between cdea818 and the Gate 2 commit only tasks.md and handoff.md change (protocol files).

## Next step
`scripts/gate-run.sh add-users-and-security 2 full` (auto mode; executor runs it). On approval: `/git:merge add-users-and-security`, user pushes `main`, executor checks the `main` run, then `/opsx:archive` (syncs four delta specs incl. the MODIFIED health-check clause).

## Blockers
None.
