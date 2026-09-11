# Handoff — add-analytics-read-model

**Updated:** 2026-09-11 · claude
**State:** implementing
**Branch:** change/add-analytics-read-model

## Done this session
- Branch created from `main` (`7feefb4`), change scaffolded.
- `/opsx:propose`: `proposal.md` (tier medium — analytics queries, no auth/firewall/migration change; the user may raise it), delta specs `analytics` (new: parameters and period, SQL read model, authorization boundary, six reports, bots toggle, cache, admin statistics), `demo-data` (new: `app:demo:seed`, guards, reset), `links` (modified: update and delete invalidate cached reports), `design.md` (12 decisions: DBAL-only read model, one statement per report with a compile-time bot fragment, declared query parameters + provider cross-checks, clock-derived time, the existing voter through the provider, one resource class per report, `cache.reports` tag-aware pool behind a fail-open gate, invalidation in the link processors, the 1 M-click `EXPLAIN`/p95 measurement, the SQL-driven seed with generated passwords, tests, logging), `tasks.md` (5 blocks, 13 tasks). `openspec validate --strict` and `scripts/pregate-verify.sh gate1` pass.

## Next step
Medium tier: no Gate 1. On the user's go: `/opsx:apply add-analytics-read-model` (`scripts/workflow-verify.sh apply add-analytics-read-model` first), starting with block 1 (read model) — see `tasks.md`.

## Blockers
None.
