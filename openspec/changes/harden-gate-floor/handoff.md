# Handoff — harden-gate-floor

**Updated:** 2026-09-16 · claude
**State:** proposing
**Branch:** change/harden-gate-floor

## Done this session
- Artifacts written and `openspec validate --strict` passes: proposal (tier `high` argued from the two files it edits, with the three user decisions recorded), design (six decisions plus the applicability table), tasks (29 across nine sections, Gate 1 first and the Gate 2 invocation as an un-checkboxed lifecycle section). **`.openspec.yaml` declares `skip_specs: true`**: no capability's behaviour changes — the statements this change alters live in `docs/explanation/requirements.md`, and they are corrected there as tasks.
- **Measured before proposing, not assumed** (2026-09-16): PHPStan level 9 reports **361 errors — 67 in `src`, 294 in `tests` across 42 files**; 54 of the 67 sit in the five `src/Analytics/Query/` classes casting DBAL row values. All five migrations have a `down()`; `Version20260910142101` deliberately keeps `messenger_messages` (the failed transport parks messages concurrently). `doctrine:schema:validate` fails today for two reasons unrelated to reversibility — the partial index `idx_clicks_link_occurred_human` and the Messenger transport's expected index name — measured with `doctrine:schema:update --dump-sql`, which is why the round trip compares fingerprints instead.
- **The round trip was rehearsed before the design claimed it works**: on a scratch database, up → down to `first` → up again left the schema fingerprint identical (`987a2130…` both times) and only `doctrine_migration_versions` and `messenger_messages` standing after the full down.
- **User decisions (2026-09-16)**: level 9 over the whole project including `tests`, not `src` alone (the two narrower options are recorded in the proposal with why they lost); and the stale `Stage: scaffold` line in `openspec/config.yaml` is corrected inside this change rather than by a direct commit on `main`.
- Branch `change/harden-gate-floor` created from `main` (`3e7e400`, the archive of `harden-quality-and-docs`); change scaffolded with `openspec new change`.
- Scope, from roadmap row 13a: **PHPStan level 9 over `src`**, and a **migration down/up job in CI**. Both were split out of row 13 by the user on 2026-09-15 for the same reason: they change the gate floor and the verifier, which AGENTS.md makes a `high` trigger, while row 13's documentation and architecture tests did not.
- Tier is expected to be `high` and argued in the proposal rather than inherited: the change edits `phpstan.dist.neon` and `.github/workflows/ci.yml`, the two files every later change is judged by, and `harden-quality-and-docs` stated both as untouched precisely because they belong here.

## Next step
Gate 1: `scripts/gate-run.sh harden-gate-floor 1 full` — tier `high`, so the artifacts are reviewed before any implementation. `scripts/pregate-verify.sh gate1` passes.

## Blockers
None.
