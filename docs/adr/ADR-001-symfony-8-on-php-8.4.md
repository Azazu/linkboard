# ADR-001: Symfony 8.1 on PHP 8.4 instead of Symfony 7.4 LTS on PHP 8.3

**Date:** 2026-09-08
**Status:** accepted
**Related:** OpenSpec change `scaffold-symfony-app`; specification `docs/explanation/requirements.md` §0, §5

## Context

The brief and the first version of the specification named PHP 8.3 and
Symfony 7. When the application scaffold was proposed (2026-09-08) the
Packagist metadata showed that the current majors of the packages the
specification requires had moved on:

| Package | Current major | Requires |
|---|---|---|
| symfony/framework-bundle 8.1 | 8 | PHP ≥ 8.4.1 |
| doctrine/doctrine-bundle 3.3 | 3 | PHP ^8.4 |
| doctrine/doctrine-migrations-bundle 4.0 | 4 | PHP ^8.4 |
| phpunit/phpunit 13 | 13 | PHP ≥ 8.4.1 |

Staying on PHP 8.3 would have meant Symfony 7.4 LTS with the previous
majors of the Doctrine bundles (2.x, 3.x) and PHPUnit 12 from the first
commit. The project is a portfolio piece whose stated purpose is to show a
current stack; a stack one major behind on day one weakens that claim.
API Platform 4.3 (`symfony/http-kernel ^6.4.13 || ^7.0 || ^8.0`), Doctrine
ORM 3.7 / DBAL 4.4, PHPStan 2.2 with the Symfony and Doctrine extensions,
PHP-CS-Fixer 3.95, dama/doctrine-test-bundle 8.6 and zenstruck/foundry
2.12 all declare support for Symfony 8 and PHP 8.4.

## Decision

The application runs on **PHP 8.4** (image `php:8.4.x-fpm-alpine`, CI
`php-version: "8.4"`, `composer.json` `"php": ">=8.4"`) and **Symfony
8.1** (`extra.symfony.require: "8.1.*"`). Symfony minors are followed as
they are released (`composer update "symfony/*"` inside the pinned
minor, then a reviewed bump of `symfony.require`); the project does not
wait for an LTS. PHP 8.4 language features (property hooks, asymmetric
visibility, `new` without parentheses) are allowed where they make code
clearer. This ADR does not guarantee compatibility with PHP 8.5: that is
a separate bump with its own verification.

## Alternatives considered

- **Symfony 7.4 LTS on PHP 8.3** — longest support horizon (until
  November 2028), but pins doctrine-bundle 2.x, migrations-bundle 3.x and
  PHPUnit 12; contradicts the portfolio goal. Lost.
- **Symfony 8.1 on PHP 8.5** — newest PHP, but support in the Doctrine
  and PHPStan ecosystem was not verified on 2026-09-08 and the extension
  images were not checked. Deferred, not rejected.

## Consequences

- Positive: current majors everywhere; one upgrade path (`8.*`);
  PHP 8.4 features available; the stack line in the README is true.
- Negative, accepted: non-LTS minors need a bump roughly twice a year;
  each bump is a reviewed change (Conventional Commit `chore(deps):`).
- Every artifact that names the stack (`AGENTS.md`, `openspec/config.yaml`,
  README, CI, Dockerfile, specification) was updated in the same change,
  so no file still says 8.3 / 7.

## Supersedes

No ADR clause. Supersedes the stack line of the original brief as
recorded in the specification's provenance note.
