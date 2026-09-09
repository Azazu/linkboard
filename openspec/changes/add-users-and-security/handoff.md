# Handoff — add-users-and-security

**Updated:** 2026-09-09 · claude
**State:** implementing
**Branch:** change/add-users-and-security

## Done this session
- Change started: branch and scaffold created (after the `fix-ci-php-job` archive; `main` run 34321101954 green).
- Proposal (tier high), four delta specs (user-accounts, authentication, user-administration, api-error-format 422 shape), design with applicability table, 8 task blocks. `lexik/jwt-authentication-bundle` 3.2 verified compatible with Symfony 8 on Packagist. Gate 1 Round 1: changes-requested, three blockers, all addressed in the artifacts: (1) prod deep probe stays an unconditional 404, authorized later by an admin API key in `add-api-keys-and-rate-limiting` — health spec amended (MODIFIED delta), tests planned; (2) JWT keys isolated per environment (`config/jwt/<env>/`), `make jwt-keys` generates dev and test, `lexik:jwt:check-config` per env with a failing input; (3) request-path limiter on array storage in test, a twin limiter `auth_ip_redis` on a Redis pool for the integration test, prod wiring checked by `debug:container`. Confirmation 1 confirmed (bce1825) — Gate 1 passed.
- Review mode switched to `auto` on main (182d1d7, user consent) and merged into this branch: from here the executor runs `scripts/gate-run.sh add-users-and-security 2 full` itself; on a Codex usage-limit failure the executor stops and reports.

## Next step
`/opsx:apply add-users-and-security` — block 1 (dependencies, JWT keys per environment, rate limiter wiring) first, then users, registration, firewalls, admin, rate limit, web pages, docs; green branch run (task 8.4) before `scripts/gate-run.sh add-users-and-security 2 full`.

## Blockers
None.
