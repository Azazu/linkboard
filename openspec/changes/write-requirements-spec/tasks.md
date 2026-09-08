## 1. Specification

- [x] 1.1 Write `docs/explanation/requirements.md` as the full technical specification (sections 0–9 plus Non-goals per the agreed structure), in English, recording every row D1–D20 of the Decision record in `proposal.md` as a normative statement; verify by re-reading the whole file once after the last edit, by a traceability table D1–D20 → section number recorded in the commit body, and by `rg -n 'G5|g5\.com|Link Tools' docs/` returning nothing.
- [x] 1.2 Verify internal consistency of the specification: every capability named in section 2 has a table in section 3 (or an explicit "no persistence" note), an endpoint group in section 4 and a stage in section 7; verify by a checklist pass recorded in the commit body.
- [x] 1.3 Verify the specification against the repository rules it must not contradict: domain rules and layout in `AGENTS.md` (Stack section), conventions in `openspec/config.yaml`; verify by `rg` for each term the spec fixes (`302`, `410`, `visitor_hash`, `is_bot`, `device-detector`, `GeoLite2`, `JWT`) across `AGENTS.md`, `openspec/`, `docs/` so no sibling artifact states an older rule.

## 2. Roadmap reconciliation

- [x] 2.1 Update `openspec/ROADMAP.md` to match section 7 of the specification: add web UI/dashboard changes, adjust scope summaries and tiers where the decisions changed them, keep ids stable otherwise; verify by a side-by-side read of section 7 and the roadmap (same change ids, same order, same tiers).
- [x] 2.2 Update the `context` block of `openspec/config.yaml` if the stack list there omits a component the specification now fixes (web UI, JWT, device detection, geo resolution); verify with `openspec validate write-requirements-spec --strict`.

## 3. Wrap-up

- [x] 3.1 Commit per logical block (`docs:` for the specification, `docs(roadmap):` for the roadmap) with the agent trailer; verify with `git log --oneline main..HEAD`.
- [x] 3.2 Run `openspec validate write-requirements-spec --strict` and `scripts/workflow-verify.sh merge write-requirements-spec`; both must pass before the handoff reads `ready-to-merge` (low tier: no gate).
