# Handoff — scaffold-symfony-app

**Updated:** 2026-09-08 · claude
**State:** proposing
**Branch:** change/scaffold-symfony-app

## Done this session
- Change started: branch and scaffold created.
- Stack decision with the user: PHP 8.4 + Symfony 8.1 (Packagist: current majors of Doctrine bundles and PHPUnit require PHP ≥ 8.4). Proposal, three delta specs (health-check, api-error-format, api-docs), design, tasks written; tier medium (Gate 2 only).

## Next step
`/opsx:apply scaffold-symfony-app` — task 1.1 (php 8.4 image + CI) first, then the skeleton. Config keys for API Platform and the dama extension are discovered from the installed packages during apply (design decisions 3, 7).

## Blockers
None.
