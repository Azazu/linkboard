# Design — scaffold-symfony-app

## Context

See `proposal.md` — Why. State: Docker stack from `bootstrap-dev-environment` (php 8.3 image, nginx, postgres, redis, worker profile), `Makefile` with a SKIP guard that turns off once `composer.json` exists, CI with a `detect` job. Constraints: everything PHP runs in the container (`make composer`, `make console`); `.env` and `.gitignore` already exist and must be merged with, not overwritten by, Flex recipes; the specification's stack facts move from 8.3/7 to 8.4/8 in this change.

## Goals / Non-Goals

**Goals:** a bootable Symfony 8.1 application with the three specified behaviors (health, error format, docs), the quality tooling that makes `make check` a real floor, and a repository whose stack facts are consistent again.

**Non-Goals:** any domain code; security configuration; web UI; performance tuning.

## Decisions

1. **Symfony 8.1 on PHP 8.4, not 7.4 LTS.** User decision; rationale in the proposal; recorded as ADR-001. Trade-off accepted: 8.1 is a non-LTS minor (maintenance until the next minor + 8 months) — upgrades are `composer update "symfony/*"` within `8.*`, no code change expected. Alternative rejected: 7.4 LTS on PHP 8.3 (forces previous majors of Doctrine bundles and PHPUnit).
2. **Skeleton created in the container, then merged.** `composer create-project` refuses a non-empty directory, so: `make composer ARGS='create-project symfony/skeleton:"8.1.*" /tmp/skeleton --no-interaction'`, then copy everything except `.env`, `.gitignore`, `README.md` into `/app`; the generated `.env` lines are merged by hand into the committed `.env` (which already carries the compose variables), the generated `.gitignore` block is compared with ours. Reviewed line by line; recorded in the commit body.
3. **API Platform mounted at `/api/v1`, docs at `/api/docs`.** The spec fixes the behavior (`/api/v1` for operations, `/api/docs` and `/api/docs.json` for documentation); the configuration keys that produce it are discovered from the installed 4.3 package (`bin/console config:dump-reference api_platform`) during apply, not recalled from memory — the prefix handling changed across API Platform majors. Formats: `json` only plus `jsonproblem`; `enable_swagger_ui: true`, `enable_re_doc: false`, `graphql` not installed. `info.title: Linkboard API`.
4. **Problem details.** API Platform 4 emits RFC 7807/9457 problem details natively for its own routes and converts framework exceptions under `/api`. `framework.exceptions` maps unexpected exceptions to 500 with a generic title; `api_platform.show_webby: false`; `APP_DEBUG=0` in `test` so the "no trace" scenario is tested against the real behavior.
5. **Health controller in `src/Shared/Health/`**, plain Symfony controller outside API Platform (spec: not documented, no auth). Deep probe: Doctrine `SELECT 1` via DBAL with a 2 s connect timeout (`connect_timeout` DSN option), Redis `PING` through a `\Redis` client from `REDIS_URL` with 2 s timeout; `prod` → 404 by an early environment check (`kernel.environment` parameter), replaced by a voter in change 3.
6. **Messenger declared, nothing routed.** `messenger.yaml` with `async: '%env(MESSENGER_TRANSPORT_DSN)%'` (Redis Streams, `stream: messages`, `group: linkboard`, `consumer: '%env(HOSTNAME)%'`), `failed: 'doctrine://default?queue_name=failed'`, `failure_transport: failed`, retry `max_retries: 3, delay: 1000, multiplier: 2`. `.env.test` sets `MESSENGER_TRANSPORT_DSN=in-memory://`. The `messenger_messages` table migration is generated when the first message arrives (change 5), not now — Non-goal "no migration".
7. **Test harness.** PHPUnit 13 (`phpunit.dist.xml`, `KERNEL_CLASS`), `dama/doctrine-test-bundle` enabled as a PHPUnit extension; `.env.test` with `DATABASE_URL` pointing at the compose postgres, database `linkboard_test`. One new Makefile target `test-db` (`doctrine:database:create --if-not-exists --env=test` + `doctrine:migrations:migrate --env=test`), called by `make init` after `migrate`, so the first run leaves a usable test database; CI runs `make test-db EXEC=` before `make check EXEC=` against its service container. No throwaway sample tests: the health controller gets a Unit test of the probe result mapping, an Integration test of the deep probe against real postgres/redis, an Api test of `/health`, `/api/docs.json`, 404/405/406 problem details, and the 500 no-trace scenario through a `test`-only throwing route (`config/routes/test/boom.yaml` → `tests/Api/Fixture/BoomController`).
8. **PHPStan level 8** with `phpstan-symfony` (container XML from `var/cache/dev`) and `phpstan-doctrine` (object manager loader `tests/object-manager.php`); `treatPhpDocTypesAsCertain: false`. **PHP-CS-Fixer** `@Symfony`, `@Symfony:risky`, `declare_strict_types`, `native_function_invocation`, `final_class` is NOT a fixer rule (enforced by review, not tooling).
9. **Autowiring layout.** `services.yaml` excludes `src/*/Entity/`, `src/*/Dto/`, `src/*/Message/` from resource loading; contexts get `.gitkeep` files now so the layout is visible in the tree.
10. **Stack-fact sweep.** Every file that names 8.3 or Symfony 7 (list in tasks 6.x from `rg`) is updated in one commit with the Dockerfile bump, so `main` is never in a mixed state. The php base image digest was resolved from Docker Hub on 2026-09-08.

## Risks / Trade-offs

- [Flex recipes append to `.env` and may add files we already have] → merge by hand, `git diff` reviewed before commit; `symfony.lock` committed.
- [API Platform config keys differ from memory] → decision 3: discover with `config:dump-reference`; the spec fixes behavior, not keys.
- [dama + PHPUnit 13 extension registration changed across majors] → follow the bundle's README of the installed version (`vendor/dama/doctrine-test-bundle/README.md`).
- [Non-LTS Symfony minor] → ADR-001 records the upgrade cadence; `composer.json` uses `8.1.*` so `composer update` never jumps a minor silently.
- [`make check` becomes real and CI needs a database] → CI already provides postgres/redis services; `make test-db EXEC=` added before `make check EXEC=`.
- [Redis PING probe adds `ext-redis` dependency in code] → extension already in the image (`redis`); `composer.json` declares `ext-redis`.

## Migration Plan

Additive; no data. Rollback: revert the merge. After merge the user pushes; CI `php` job runs for the first time (detect → true).

## Open Questions

None that change the specs or tasks. Config key names (API Platform prefix, dama extension) are resolved during apply from the installed packages.
