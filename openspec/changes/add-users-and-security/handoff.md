# Handoff — add-users-and-security

**Updated:** 2026-09-09 · claude
**State:** awaiting-gate-1
**Branch:** change/add-users-and-security

## Done this session
- Change started: branch and scaffold created (after the `fix-ci-php-job` archive; `main` run 34321101954 green).
- Proposal (tier high), four delta specs (user-accounts, authentication, user-administration, api-error-format 422 shape), design with applicability table, 8 task blocks. `lexik/jwt-authentication-bundle` 3.2 verified compatible with Symfony 8 on Packagist. Gate 1 requested.

## Next step
User runs Codex for Gate 1, then `scripts/gate-run.sh add-users-and-security 1 record`. On approval: `/opsx:apply add-users-and-security` (block 1 first). Security-relevant surface: firewalls, JWT, password hashing, blocking, rate limiting, admin boundary — flagged per AGENTS.md.

## Blockers
None.
