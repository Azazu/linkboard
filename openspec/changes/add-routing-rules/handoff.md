# Handoff — add-routing-rules

**Updated:** 2026-09-10 · claude
**State:** awaiting-gate-1
**Branch:** change/add-routing-rules

## Done this session
- Change started after the `add-redirect-with-sync-logging` archive (`main` 5210088; merge commit run 34448509736 green). Branch and scaffold created; roadmap row 6 (Stage 2) already lists the change.
- Proposal (tier high per roadmap and NFR-SEC-7: rule evaluation over untrusted hot-path input, target policy on rule/variant targets, two new dependencies, IP used for geo and the A/B hash), delta specs: new `routing-rules` (9 requirements, 23 scenarios: document shape and violation paths, schema parity, fixed matching order, skipped dimensions, detection vocabularies, country chain with the trusted-proxy header rule, language, deterministic A/B, degradation guard), MODIFIED `links` (create/update with `rules`, merge-patch null contract), `redirect` ("Destination with UTM appended" over the resolved destination, HEAD identical), `click-logging` ("Click record contents" populated). Design: 12 decisions (one parser for validation and evaluation; `mixed $rules` + constraint; JSON Schema file with a parity test; `VisitFactory` owns bounds and the trusted-proxy decision; profile → evaluate → facts inside one guard; anomalous documents; device-detector with a filesystem PSR-6 cache; tagged country-resolver chain selected by `COUNTRY_RESOLVERS`; `AcceptHeader`-based language; crc32 variant picker; `ClickFacts` through the recorder seam; config and dependency pins) plus the high-tier applicability table. Tasks: 6 blocks, 19 tasks, each with a verification and the demonstrated failing input for every guard.
- `openspec validate add-routing-rules --strict` and `scripts/pregate-verify.sh gate1 add-routing-rules` pass.
- **Security-sensitive, flagged for the reviewer:** new dependencies `matomo/device-detector` and `geoip2/geoip2` parsing attacker-controlled bytes (bounded first; `composer audit` in task 1.1); rule evaluation over untrusted headers behind a catch-all guard; the proxy country header honoured only from `TRUSTED_PROXIES`; rule and variant targets under `TargetUrlPolicy`.
- Assumptions recorded in the artifacts: `rules` list needs ≥ 1 entry and a document needs `rules` or `variants`; weights are integers ≥ 1; variant names `^[A-Za-z0-9_-]{1,16}$`; value arrays ≤ 64 distinct entries; unknown/garbage inputs are skipped dimensions without a log record, only exceptions log a `notice`; phablets count as smartphones; bots are evaluated like other visitors; `XX`/`T1` from Cloudflare mean unknown; NUL separators in the crc32 input; empty `{}`/`[]` is one violation on `rules`.

## Next step
`scripts/gate-run.sh add-routing-rules 1 full` (auto mode). On changes-requested: `/workflow:fix-findings`, then `scripts/gate-run.sh add-routing-rules 1 confirm <round>`; stop after two failed confirmations on one finding. On approval: tick task 0.1, `/opsx:apply` starting with block 1 (dependencies — verify the vendor API before writing wrappers, task 1.1).

## Blockers
None.
