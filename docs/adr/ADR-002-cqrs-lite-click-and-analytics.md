# ADR-002: the click write path and the analytics read model share no entity

**Date:** 2026-09-15
**Status:** accepted
**Related:** [ADR-004](ADR-004-report-cache-staleness.md); decided in the archived changes `add-click-logging` and `add-analytics-read-model`, recorded here by `harden-quality-and-docs`

## Context

A short-link service writes one row per redirect and reads aggregates over
millions of them. The two are opposite workloads: the write is a single insert
on the hot path of a request whose whole job is to answer 302 quickly; the read
is a grouped scan over a window, wanted at report latency, not redirect latency.

The specification fixes both ends: the redirect must not wait for the click to
be written (FR-RED-2), and every report is computed by SQL with window
functions rather than by loops in PHP, with a p95 of 300 ms uncached over a
million rows (FR-ANL-1, NFR-PERF-2).

Doctrine makes it easy to do the obvious thing — load `Click` entities and
aggregate them — and that obvious thing is what fails first: hydrating a
hundred thousand objects to count them is both the slowest and the most
memory-hungry way to reach a number PostgreSQL already knows.

## Decision

The click write path and the analytics read path share no model.

- Writing: the redirect dispatches a `ClickRecorded` message to the async
  transport and returns 302 without waiting. A handler persists a `Click`
  entity. That entity exists for the write and nowhere else.
- Reading: `src/Analytics/` computes every figure in SQL — `date_trunc`,
  `generate_series`, window functions — and returns immutable DTOs. It never
  names a `Click` entity, never loads one, and never asks a repository for one.

The mechanism that keeps it true is a test, not a convention:
`tests/Unit/Architecture/AnalyticsKeepsItsDistanceTest.php` fails when a file
under `src/Analytics/` names the entity.

**What this does not guarantee.** The two paths still meet at the `clicks`
table, so a schema change touches both, and the rule is a text scan: a class
named through a variable passes it. The boundary is a design that is checked,
not a compiler.

## Alternatives considered

- **One model for both** — read the same entities the handler writes. Rejected:
  it is the shape that cannot meet NFR-PERF-2, and it invites lazy-loading in a
  loop, which the layout rules forbid for exactly this reason.
- **A separate read database** — a replica or a projection store. Rejected as
  premature: it buys isolation this project does not yet need and costs an
  infrastructure component, a replication lag to explain and a second failure
  mode, against the anti-overengineering rule.
- **A materialised `click_daily` aggregate refreshed by the worker.** Not
  rejected — deferred. It is the answer if report latency ever fails its target
  on a larger dataset, and it is written down as a stretch item rather than
  built on speculation.

## Consequences

- A report is one statement the planner can use an index for, and the redirect's
  own work stays a lookup and a dispatch.
- A click is visible in the reports a moment after it happened — the queue's
  latency plus the report cache's staleness ([ADR-004](ADR-004-report-cache-staleness.md)).
- A failure to log a click never becomes a failed redirect; it becomes a message
  that waits, and a retry the transport owns.
- Someone adding a figure to a report has to write SQL rather than reach for an
  entity, which is more work per figure and the reason the figures are fast.

## Supersedes

No ADR clause.
