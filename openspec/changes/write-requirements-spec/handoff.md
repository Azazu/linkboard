# Handoff — write-requirements-spec

**Updated:** 2026-09-08 · claude
**State:** ready-to-merge
**Branch:** change/write-requirements-spec

## Done this session
- Gate 1: Round 1 changes-requested (incomplete decision record) → fixed → Confirmation 1 confirmed.
- `docs/explanation/requirements.md` rewritten as the full technical specification (FR-*/NFR-* ids, data model, API table, stages 1–4 + stretch, Appendix A traceability D1–D20).
- `AGENTS.md` layout and redirect rule synced (src/Web, templates, assets, tests/Web; "302 (never 301)").
- `openspec/ROADMAP.md` reconciled with §7; `openspec/config.yaml` context updated.
- User review of the specification: two findings fixed — click limit under Redis outage (FR-RED-3: 503 for limited links, counter is the authority, honest guarantee boundary) and delete-vs-queued-message race (FR-CLK-5/FR-LNK-10: discard without retry, required test).
- Third user finding fixed: FR-RED-2/3 name the mechanism — one atomic Lua script (single EVAL) seeds, checks and increments the click counter, so the one-round-trip budget and concurrency safety coincide. Fourth finding: plain EVAL every time (no EVALSHA/NOSCRIPT fallback), keeping the budget strict after Redis restarts.
- Low tier: no Gate 2 required.

## Next step
User reviews the roadmap delta (API Platform in scaffold, `add-web-ui`, `polish-api-and-openapi`, stretch rows 15–16), then `/git:merge write-requirements-spec`, then `/opsx:archive write-requirements-spec`.

## Blockers
None.
