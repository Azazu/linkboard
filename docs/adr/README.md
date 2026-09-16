# Architecture Decision Records

Append-only log of significant decisions. Superseding a decision means a
new ADR, never editing an old one (AGENTS.md). Copy
[`ADR-TEMPLATE.md`](ADR-TEMPLATE.md) for a new record and add its row
here.

| ADR | Decision in one line |
|---|---|
| [ADR-000](ADR-000-agent-workflow.md) | Agent workflow: Claude executes, Codex reviews at risk-tiered gates, user merges (process, precedes the series) |
| [ADR-001](ADR-001-symfony-8-on-php-8.4.md) | Symfony 8.1 on PHP 8.4 instead of 7.4 LTS on 8.3: current majors over the LTS horizon |
| [ADR-002](ADR-002-cqrs-lite-click-and-analytics.md) | The click write path and the analytics read model share no entity (CQRS-lite), enforced by an architecture test |
| [ADR-003](ADR-003-deterministic-ab-bucket.md) | The A/B bucket is `crc32(link ‖ address ‖ agent) mod 100` — deterministic per visitor, no cookie and no stored assignment |
| [ADR-004](ADR-004-report-cache-staleness.md) | Reports are cached 300 s with tag invalidation on link writes, and the staleness is published rather than hidden |
| [ADR-005](ADR-005-pages-answer-404.md) | The pages answer 404 where the API answers 403: one voter, two renderings of a denial |
