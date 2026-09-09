# Handoff — add-redirect-with-sync-logging

**Updated:** 2026-09-09 · claude
**State:** proposing
**Branch:** change/add-redirect-with-sync-logging

## Done this session
- Change started after the `add-link-crud` archive (`main` 26a39c2; merge commit run 34348604541 green, archive-commit run 34349174608 in progress at branch creation).
- Branch and scaffold created; roadmap row 5 already lists the change.
- Proposal (tier medium per roadmap; security-relevant parts flagged for the reviewer), delta specs `redirect` (7 requirements) and `click-logging` (5 requirements), design (12 decisions: lowest-priority route under the lazy firewall, resolver + `ClickRecorderInterface` seam, exact limit via one conditional UPDATE, DBAL write path with a read-only `Click` entity, degradation 302/503, UTM composition, salted visitor hash, `redirect_ip` limiter, problem-details helper moved to Shared), tasks (5 blocks, 15 tasks). `openspec validate --strict` and `pregate-verify.sh gate1` pass. No Gate 1 for medium tier.

## Next step
`/opsx:apply add-redirect-with-sync-logging` — implement per tasks.md (block 1 click write model → 2 redirect → 3 web tests → 4 docs → 5 wrap-up), commit per block, `make check`, then the user pushes the branch for a green run, then `scripts/gate-run.sh add-redirect-with-sync-logging 2 full`.

## Blockers
None.
