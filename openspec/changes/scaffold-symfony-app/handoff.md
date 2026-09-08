# Handoff — scaffold-symfony-app

**Updated:** 2026-09-08 · claude
**State:** awaiting-gate-2
**Branch:** change/scaffold-symfony-app

## Done this session
- Stack decision with the user: PHP 8.4 + Symfony 8.1 (ADR-001); every stack fact swept (commit 0a1f49a).
- Skeleton merged (5502e42), Doctrine/Messenger/Monolog (c613d13), API Platform 4.3 under /api/v1 with JSON-only formats and problem details (8680ea3), health endpoint (a690019), PHPUnit 13 / PHPStan 8 / php-cs-fixer wired into `make check` with 19 tests covering every spec scenario (76dd339), docs (1949712).
- Deviations from the plan, all recorded in proposal/design: api-platform/doctrine-orm and symfony/twig-bundle (Swagger UI) and symfony/doctrine-messenger added; JSON-LD entrypoint disabled, /api/v1 serves the documentation instead (spec api-docs amended); pdo_pgsql timeout via PDO::ATTR_TIMEOUT; PHPUnit env forced in $_ENV too. The doctrine `failed` transport auto-created `messenger_messages` in the dev database (auto_setup); its migration is owned by the async click logging change.
- `make check` green through the real tools; Gate 2 requested.
- Gate 2 Round 1: changes-requested, two blockers, both fixed: (1) server-side `statement_timeout=2000` on the probe connection plus an integration test cancelling `pg_sleep(10)` within the budget; (2) prod deep probe answers an explicit problem+json 404 with no-store, tested by driving the prod kernel directly (fresh `var/cache/prod`, non-empty secret injected). Design decision 5 rewritten to match. 21 tests / 83 assertions, cs and stan clean. Confirmation requested.

## Next step
User runs Codex for Gate 2 (`git diff main...change/scaffold-symfony-app`), then `scripts/gate-run.sh scaffold-symfony-app 2 record`. On approval: `/git:merge scaffold-symfony-app`, `/opsx:archive`, push; the CI `php` job runs for the first time (detect → true). Security-relevant surface for the reviewer: error rendering (`handle_symfony_errors`, APP_DEBUG=0 in test), health probe building raw PDO/Redis clients from env DSNs, test-only route registered under `when@test` only.

## Blockers
None.
