# Handoff — add-async-click-logging

**Updated:** 2026-09-10 · claude
**State:** proposing
**Branch:** change/add-async-click-logging

## Done this session
- Change started after the `add-routing-rules` archive (`main` f40a07c; merge commit run 34477153396 green). Branch and scaffold created; roadmap row 7 (Stage 2) already lists the change.

## Next step
`/opsx:propose add-async-click-logging` — Messenger `ClickRecorded` on the Redis Streams transport; the handler derives the click row (hashing, detection facts, `is_bot`) and increments `click_count` in one transaction, idempotent per `click_id`; retries (3, exponential) then the `failed` Doctrine transport; messages for deleted links discarded without retry; the Redis click counter (one `EVAL`, seeded from `click_count`) becomes the limit authority — 503 for limited links while Redis is down, unlimited links unaffected; the redirect never waits for or fails on logging and performs no SQL write (roadmap row 7, requirements §2.4 FR-RED-2/3/4, §2.5).

## Blockers
None.
