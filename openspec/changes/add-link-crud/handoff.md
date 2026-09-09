# Handoff — add-link-crud

**Updated:** 2026-09-09 · claude
**State:** proposing
**Branch:** change/add-link-crud

## Done this session
- Change started: branch and scaffold created (after the `add-users-and-security` archive; `main` run 34330518338 green).

## Next step
`/opsx:propose add-link-crud` — `links` entity and migration, slug generation and custom aliases with the reserved list, target URL policy (http/https only, no loopback/link-local/private hosts), UTM, `expires_at`/`max_clicks`, `is_active`, API Platform resource with owner voters (`LinkVoter`), `shortUrl`, hard delete with cascade; specification §2.2 FR-LNK-1…11, §3.3, §4, D6, D8, D9. Tier high (URL validation = open-redirect/SSRF class, deletion, voters): Gate 1 before implementation, green branch run before Gate 2 (auto review mode).

## Blockers
None.
