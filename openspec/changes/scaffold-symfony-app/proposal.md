# Proposal — scaffold-symfony-app

**Risk-Tier:** medium

## Why

The repository has a working Docker stack and a green CI, but no application: nginx answers 404, `make init` fails at `composer install`, and every following change (users, links, redirect) needs a kernel, an ORM, an API layer and a test harness to build on. This change puts the Symfony application in place with the three behaviors the specification requires from the very first stage: a health endpoint, one error format for the API, and generated API documentation under `/api/v1`.

**Stack decision (user, 2026-09-08):** PHP 8.4 + Symfony 8.1 instead of the PHP 8.3 + Symfony 7 the specification currently names. Reason: on Packagist today Symfony 8.1, doctrine/doctrine-bundle 3, doctrine/doctrine-migrations-bundle 4 and PHPUnit 13 all require PHP ≥ 8.4; staying on 8.3 would pin the project to previous majors from day one, which contradicts the portfolio goal (a current stack). API Platform 4.3 (`^7.0 || ^8.0`), Doctrine ORM 3.7, PHPStan 2.2 with the Symfony/Doctrine extensions, PHP-CS-Fixer 3.95, dama/doctrine-test-bundle 8.6 and zenstruck/foundry 2.12 all support Symfony 8 / PHP 8.4 (verified against Packagist metadata). The specification, `AGENTS.md`, `openspec/config.yaml`, README, CI and the php image are updated in this change so that no artifact still says 8.3 / 7.

## What Changes

1. **Application skeleton.** `symfony/skeleton:8.1.*` created via Composer inside the php container and merged into the repository root (`composer.json`, `bin/console`, `config/`, `public/index.php`, `src/Kernel.php`, `.env` additions from Flex recipes reviewed and merged into the committed `.env`). `composer.json` pins `"php": ">=8.4"`, `symfony.require: "8.1.*"`, and the platform config.
2. **Persistence and messaging wiring.** Doctrine ORM 3 + DBAL 4 + `doctrine/doctrine-bundle` 3 + `doctrine/doctrine-migrations-bundle` 4 configured for PostgreSQL 16 from `DATABASE_URL`; `symfony/uid` for UUID v7 ids; Messenger installed with the `async` (Redis Streams) and `failed` (Doctrine) transports declared, retry strategy per FR-CLK-5, no message routed yet; `symfony/monolog-bundle` with JSON lines to stderr outside `dev`.
3. **API layer.** `api-platform/core` 4.3 (`api-platform/symfony`) mounted under `/api/v1` with JSON only (JSON-LD/Hydra and GraphQL disabled), Swagger UI at `/api/docs`, OpenAPI document at `/api/docs.json`, RFC 9457 problem details for every error, no stack traces outside `dev` (the 422 `violations[]` shape is specified and tested with the first validated resource in `add-link-crud`, since no input exists yet). No resource yet: the entrypoint and docs are empty until `add-link-crud`.
4. **Health endpoint.** `GET /health` returning `{"status":"ok"}`; `GET /health?deep=1` also checks PostgreSQL and Redis connectivity (admin-only in prod arrives with `add-users-and-security`; until then the deep probe is available without authentication in `dev`/`test` and refused with 404 in `prod`).
5. **Quality tooling wired into `make check`.** PHPUnit 13 with `symfony/test-pack`, `dama/doctrine-test-bundle` (per-test transactions), `zenstruck/foundry` (factories); PHPStan 2 level 8 with `phpstan-symfony` and `phpstan-doctrine`; PHP-CS-Fixer 3 with `@Symfony` + `@Symfony:risky` + `declare(strict_types=1)`; `.env.test` committed with the test database. One sample test per layer (`tests/Unit`, `tests/Integration`, `tests/Api`) so every suite is exercised.
6. **Bounded-context layout.** `src/` directories per `AGENTS.md` (`Link/ Redirect/ Click/ Analytics/ Auth/ Shared/ Web/`) with `.gitkeep`, plus `src/Shared/Health/` for the health controller and `src/Shared/Http/` for the problem-details customization. Autowiring excludes for entities/DTOs.
7. **Stack bump.** php image base → `php:8.4.25-fpm-alpine@sha256:49734670eccf414af884c2a0c2e558401e228615f8028f1c9fca30a0d4fb1bc2`; CI `php-version: "8.4"`; specification §0/§5, `AGENTS.md` (header, Stack), `openspec/config.yaml`, README, `.claude/rules/php.md` and `docs/how-to/local-development.md` updated from 8.3/7 to 8.4/8.
8. **Docs.** `docs/how-to/local-development.md`: `make init` is the first-run command again, URLs for health and docs; `docs/reference/commands.md` gains `make test-db` (creates and migrates the test database; `make init` calls it); ADR-001 "Symfony 8.1 on PHP 8.4 instead of 7.4 LTS" recording the trade-off (current majors vs LTS horizon).

## Capabilities

### New Capabilities

- `health-check`: liveness endpoint and optional deep dependency probe with fixed response shapes and codes.
- `api-error-format`: every API error is an RFC 9457 problem-details document; validation errors carry `violations`; no internal details leak outside `dev`.
- `api-docs`: OpenAPI 3.1 document and Swagger UI served under `/api`, versioned base path `/api/v1`, JSON-only content negotiation.

### Modified Capabilities

None.

## Non-goals

- No domain entity, resource, migration or Messenger message: `users` (change 3), `links` (change 4), `clicks` (change 5) come later. `doctrine:migrations:migrate` runs on an empty migrations set (`--allow-no-migration`).
- No security firewall beyond the framework default (`security.yaml` arrives with change 3); the deep health probe is therefore unauthenticated in `dev`/`test` and disabled in `prod` for now.
- No web UI, Twig or AssetMapper (change 11); no Symfony UX packages.
- No PHP 8.5 (`php:8.5` images exist, but doctrine/PHPStan support is not verified; a later bump).
- No upgrade of the ai-kit stack templates.

## Impact

- New: `composer.json`, `composer.lock`, `symfony.lock`, `bin/console`, `public/index.php`, `src/Kernel.php`, `config/**`, `src/<contexts>/.gitkeep`, `src/Shared/Health/*`, `src/Shared/Http/*`, `tests/**`, `phpunit.dist.xml`, `phpstan.dist.neon`, `.php-cs-fixer.dist.php`, `.env.test`, `docs/adr/ADR-001-symfony-8-on-php-8.4.md`.
- Modified: `.env` (Flex additions, reviewed), `Makefile` (`test-db` target, `init` calls it), `.docker/php/Dockerfile` (base image), `.github/workflows/ci.yml` (php-version), `AGENTS.md`, `openspec/config.yaml`, `README.md`, `.claude/rules/php.md`, `docs/how-to/local-development.md`, `docs/explanation/requirements.md` (§0, §5, §7 row 2), `openspec/ROADMAP.md` (row 2 wording).
- New dependencies (every one required by the specification §5, versions verified on Packagist 2026-09-08): `symfony/skeleton` 8.1, `api-platform/symfony` 4.3 with `api-platform/doctrine-orm` 4.3 (the 4.x split requires it once Doctrine is present) and `symfony/twig-bundle` 8.1 (API Platform renders Swagger UI through Twig; Twig is in §5 anyway for the web UI), `symfony/doctrine-messenger` 8.1 (the `failed` transport), `doctrine/orm` 3.7 / `doctrine/dbal` 4.4 / `doctrine/doctrine-bundle` 3.3 / `doctrine/doctrine-migrations-bundle` 4.0, `symfony/messenger` + `symfony/redis-messenger` 8.1, `symfony/uid` 8.1, `symfony/monolog-bundle` 4.0; dev: `phpunit/phpunit` 13, `symfony/test-pack` 1.2, `dama/doctrine-test-bundle` 8.6, `zenstruck/foundry` 2.12, `phpstan/phpstan` 2.2 + `phpstan-symfony` 2.0 + `phpstan-doctrine` 2.0, `friendsofphp/php-cs-fixer` 3.95. Nothing outside §5; Foundry replaces hand-written fixture builders (anti-overengineering: one factory library instead of per-test setup code, already listed in §5).
