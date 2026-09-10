# Handoff — add-routing-rules

**Updated:** 2026-09-10 · claude
**State:** proposing
**Branch:** change/add-routing-rules

## Done this session
- Change started after the `add-redirect-with-sync-logging` archive (`main` 5210088; merge commit run 34448509736 green). Branch and scaffold created; roadmap row 6 (Stage 2) already lists the change.

## Next step
`/opsx:propose add-routing-rules` — JSONB `rules` with schema validation; device/OS, country (`CountryResolver`) and language matching in the fixed order device → country → language → default; deterministic A/B split per visitor (roadmap row 6, requirements §7).

## Blockers
None.
