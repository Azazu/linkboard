# Handoff — add-users-and-security

**Updated:** 2026-09-09 · claude
**State:** ready-to-merge
**Branch:** change/add-users-and-security

## Done this session
- Change started: branch and scaffold created (after the `fix-ci-php-job` archive; `main` run 34321101954 green).
- Proposal (tier high), four delta specs (user-accounts, authentication, user-administration, api-error-format 422 shape), design with applicability table, 8 task blocks. `lexik/jwt-authentication-bundle` 3.2 verified compatible with Symfony 8 on Packagist. Gate 1 Round 1: changes-requested, three blockers, all addressed in the artifacts: (1) prod deep probe stays an unconditional 404, authorized later by an admin API key in `add-api-keys-and-rate-limiting` — health spec amended (MODIFIED delta), tests planned; (2) JWT keys isolated per environment (`config/jwt/<env>/`), `make jwt-keys` generates dev and test, `lexik:jwt:check-config` per env with a failing input; (3) request-path limiter on array storage in test, a twin limiter `auth_ip_redis` on a Redis pool for the integration test, prod wiring checked by `debug:container`. Confirmation 1 confirmed (bce1825) — Gate 1 passed.
- Review mode switched to `auto` on main (182d1d7, user consent) and merged into this branch: from here the executor runs `scripts/gate-run.sh add-users-and-security 2 full` itself; on a Codex usage-limit failure the executor stops and reports.

- Implemented blocks 1–8 (commits 7f90734 … see git log): users + migration, registration (API + web), JWT with problem details, /me, blocking, admin list/block/unblock with audit, auth rate limit on Redis, bare web pages, docs. make check: cs 0, stan 0, 65 tests / 323 assertions. Deviations recorded in proposal/design: symfony/expression-language added; email in html5 mode (no egulias dependency); test limiter on an in-process storage instead of an array pool (kernel.reset clears pools between requests); JSON pagination envelope normalizer added (plain json has none in API Platform).

- Task 8.4: branch run https://github.com/Azazu/linkboard/actions/runs/34327044243 on head cdea818 — workflow, detect, php all success (run list by head SHA + jobs endpoint). Between cdea818 and the Gate 2 commit only tasks.md and handoff.md change (protocol files).

- Gate 2 Round 1 (c665fff): changes-requested, 4 findings, all fixed: #1 blocked account ends an existing web session (refreshUser throws; BlockedSessionErrorSubscriber stores the error for /login; web test); #2 Redis lock on the limiters (symfony/lock, LOCK_DSN; 25-process concurrency test; without lock 17/25 accepted, with lock 10/25); #3 always-on audit stream handler in prod/dev (config test); #4 API Platform ValidationException on the flush race (processor test). make check: cs 0, stan 0, 69 tests / 369 assertions. Next: user pushes, green run on the head, then `gate-run … 2 confirm 1`.

- Branch run https://github.com/Azazu/linkboard/actions/runs/34328524137 on c1e4306: all jobs success. Gate 2 Confirmation 1 (b4517f5): #2 confirmed; #1, #3, #4 asked for completion — done: stale checker/design wording replaced (the checker does not run on session refresh; refreshUser does); the test env now uses the production-style always-on JSON audit stream (file instead of stderr) and the admin test asserts one record for block and one for unblock with action/actor/target and no email; an HTTP-level test reaches the unique-index race after validation (prePersist listener inserts the competitor) and asserts 422 with an email violation. make check: cs 0, stan 0, 70 tests / 385 assertions. Next: user pushes, green run on the head, `gate-run … 2 confirm 1` (second confirmation).

- Branch run https://github.com/Azazu/linkboard/actions/runs/34329255776 on d8d6e15: all jobs success. Gate 2 Confirmation 2 (9f6d2e8): all four findings confirmed — Gate 2 passed.

## Next step
`/git:merge add-users-and-security` (user). Then the user pushes `main`; the executor checks the `main` run (run list by SHA + jobs endpoint) before offering `/opsx:archive add-users-and-security` (syncs four delta specs incl. the MODIFIED health-check clause). Then `/workflow:start add-link-crud` (tier high).

## Blockers
None.
