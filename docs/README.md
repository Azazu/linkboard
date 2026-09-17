# Documentation index

Four Diátaxis quadrants plus the decision log. The object-to-location
routing is owned by AGENTS.md ("Documentation Layout"): tutorials,
how-to and reference describe only what exists and works; explanation
may also describe decided design, citing the ADR that decided it.

## Explanation (understanding-oriented)

- [`explanation/architecture.md`](explanation/architecture.md) — the
  contexts, the click write path and the analytics read path, and where
  the boundaries between them are enforced
- [`explanation/requirements.md`](explanation/requirements.md) — the
  original brief with its requirement ids

## How-to (task recipes)

- [`how-to/local-development.md`](how-to/local-development.md) — run the
  project locally (setup, daily commands, reset, troubleshooting)
- [`how-to/local-mcp.md`](how-to/local-mcp.md) — wire the IDE's MCP
  endpoint for Claude Code
- [`how-to/benchmarks.md`](how-to/benchmarks.md) — the three performance
  targets, the commands that measure them and what they measured
- [`how-to/graphql.md`](how-to/graphql.md) — the read-only GraphQL endpoint:
  a worked query, what a document costs, and what the schema will not do

## Reference (facts)

- [`reference/commands.md`](reference/commands.md) — contributor
  command surface: make targets, scripts, agent slash commands
- [`reference/api-errors.md`](reference/api-errors.md) — every error
  type the API can produce, what it means and how a client recovers

## Tutorials (learning-oriented)

Empty by design at this stage.

## Decisions (append-only log)

- [`adr/README.md`](adr/README.md) — index of all ADRs with one-line
  decisions
