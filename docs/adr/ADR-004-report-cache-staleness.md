# ADR-004: reports are cached for 300 seconds, and the staleness is published

**Date:** 2026-09-15
**Status:** accepted
**Related:** [ADR-002](ADR-002-cqrs-lite-click-and-analytics.md); decided in the archived change `add-analytics-read-model`, recorded here by `harden-quality-and-docs`

## Context

Every report is a grouped scan over the click rows. Measured on a million rows
in this project's own container, an uncached report costs between 38 ms and
303 ms (`docs/how-to/benchmarks.md`). A dashboard that draws several of them per
page view, refreshed by a reader who presses reload, would spend that on every
view — for numbers that change by a click or two in the meantime.

Meanwhile the write path is asynchronous by design ([ADR-002](ADR-002-cqrs-lite-click-and-analytics.md)),
so a click is already a moment behind. The question is not whether the numbers
lag, but by how much and whether the reader is told.

## Decision

Reports are served through a tag-aware Redis cache with a time-to-live of 300
seconds. The key is the report, the link (for a link report) and every effective
parameter; the entry is tagged with the link, and a link write — update,
deactivation, deletion — invalidates that tag. Deleting a link also invalidates
the `global` tag, because the top-links report names links.

Two properties are deliberate:

- **The cache is never a dependency.** If Redis is unavailable the report is
  computed from PostgreSQL and answered 200 with a warning logged; a failed
  invalidation never fails the write that triggered it.
- **The staleness is published.** Every report carries `generatedAt`, and the
  pages say the figures may be up to five minutes old. A number that might be
  stale and says so is honest; the same number presented as live is not.

One entry serves both consumers: the API and the pages call the same service, so
a page and `GET /api/v1/links/{id}/stats/...` answering the same parameters
return the same numbers and the same `generatedAt`.

**What this does not guarantee.** Clicks arriving between two computations are
invisible until the entry expires — up to 300 seconds, plus the queue's own
latency. Nothing here makes a report real-time, and the invalidation covers link
writes, not new clicks: a click does not invalidate anything, by design.

## Alternatives considered

- **No cache.** Rejected on the measured cost: the dashboard would pay the
  uncached price on every view, and the global top-links report is the one
  already closest to its latency target.
- **Invalidate on every click.** Rejected: it would invalidate constantly on
  exactly the links that matter, turning the cache into overhead, and it would
  put a cache concern on the hot write path.
- **A shorter or longer time-to-live.** 300 s is the specification's figure and
  it matches what the UI promises. Shorter buys freshness nobody asked for at a
  cost measured above; longer starts to feel wrong to a reader watching their
  own clicks arrive.
- **A materialised aggregate refreshed by the worker.** Deferred, not rejected —
  the stretch answer if a report ever fails its latency target for real.

## Consequences

- A second identical request costs a Redis round trip, and the suite asserts it
  executes no query over the click records.
- A link write makes its reports recompute, so an owner who changes a link sees
  the effect immediately rather than five minutes later.
- The staleness is a documented property of the product, visible on the page and
  in `generatedAt`, rather than a surprise.

## Supersedes

No ADR clause.
