# Handoff — add-redirect-with-sync-logging

**Updated:** 2026-09-09 · claude
**State:** awaiting-gate-1
**Branch:** change/add-redirect-with-sync-logging

## Done this session
- Change started after the `add-link-crud` archive (`main` 26a39c2; merge commit run 34348604541 green, archive-commit run 34349174608 in progress at branch creation).
- Branch and scaffold created; roadmap row 5 already lists the change.
- Proposal (tier medium per roadmap; security-relevant parts flagged for the reviewer), delta specs `redirect` (7 requirements) and `click-logging` (5 requirements), design (12 decisions: lowest-priority route under the lazy firewall, resolver + `ClickRecorderInterface` seam, exact limit via one conditional UPDATE, DBAL write path with a read-only `Click` entity, degradation 302/503, UTM composition, salted visitor hash, `redirect_ip` limiter, problem-details helper moved to Shared), tasks (5 blocks, 15 tasks). `openspec validate --strict` and `pregate-verify.sh gate1` pass. Tier raised to high by the user → applicability table added to design, Gate 1 task 0.1 added, roadmap/§7 row 5 updated.
- Gate 1 Round 1 (6e59b75): changes-requested, 6 majors, all addressed in the artifacts: (1) UTM composed from raw `key=value` pairs, never `parse_str` (repeated/dotted/bracketed/encoded keys preserved; spec scenario + unit matrix); (2) **security configuration**: `api` firewall pattern narrowed to `^/api(/|$)` so `api-promo` is public, `/api/v1/me` stays 401 (spec scenario, tests both ways); (3) rate limiter is the only Redis use on the hot path and fails open with a `warning`, guarantee boundary stated; (4) `referer_host` validated against its column (≤255 bytes, UTF-8, no controls → null), hostile-referer scenario on limited and unlimited links; (5) contracts reconciled: "one click per 302" holds while the click store is reachable, the write-failure 302 is the stated exception, HEAD answers from link state alone, crash window stated; (6) lookup failure is a separate policy (503 for every slug, decision 13), write-failure scenarios bounded to "link resolved". Statuses → fixed. Confirmation 1: #1, #2, #4, #5, #6 confirmed; #3 changes-requested (coverage must include lock/storage failures inside `consume()`, not only a throwing factory) → decision 9 and task 3.3(c) now specify three stubs (factory throws; lock store throws inside consume; storage throws inside consume) and the failing input (consume outside the catch).

## Next step
`scripts/gate-run.sh add-redirect-with-sync-logging 1 confirm 1` (auto mode; second confirmation of round 1 — stop and ask the user if #3 fails again). On confirmed: `/opsx:apply add-redirect-with-sync-logging`; on changes-requested: `/workflow:fix-findings`, then `gate-run … 1 confirm <round>`; stop after two failed confirmations on one finding.

## Blockers
None.
