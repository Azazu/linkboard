# Repository Guidelines — Linkboard

Single source of truth for how AI agents (Claude Code, Codex) work in
this repository. Both agents load this file automatically; when in
doubt it wins over default agent behavior. Process record:
`docs/adr/ADR-000-agent-workflow.md`.

Short-link service with smart routing (device/geo rules, A/B) and click analytics, built on Symfony 7, API Platform, Doctrine and Messenger over PostgreSQL and Redis.

**Review mode:** manual

## Agent Roles

| Agent | Role |
|---|---|
| Claude Code | Executor: explores, authors OpenSpec proposals/designs/tasks, implements, runs checks |
| Codex | Independent reviewer at the gates a change's risk tier requires; does not implement |
| User | Final arbiter; the only party who merges to `main` and pushes; in `manual` review mode also the party who runs Codex |

The `**Review mode:**` line above is read by `scripts/gate-run.sh`
(`auto` when absent). `auto`: the runner invokes Codex itself.
`manual`: the executor stops at every gate with a printed review
request, the user runs Codex by hand, and the executor records the
result next session. Switching modes is editing that one line.

## Change Lifecycle

Unit of work: one OpenSpec change on branch `change/<id>`. Every
multi-task effort — feature, refactor, tooling, docs — is a change; no
work happens outside one. Plans live in the change's `tasks.md` and in
`openspec/ROADMAP.md`, never only in agent memory.

```text
Claude  /workflow:start <id>      → branch change/<id>, handoff: proposing
Claude  /opsx:propose             → proposal, specs delta, design, tasks
        (high tier)  /gate-review <id> 1   → Codex Gate 1 on the artifacts
Claude  /opsx:apply               → implement, commit per logical block
Claude  make check                → lint + static analysis + tests, all green
        (medium/high) /gate-review <id> 2  → Codex Gate 2 on the code diff
Claude  /workflow:fix-findings    → fix, update Status, re-review (confirm)
        (manual mode) every /gate-review is two steps: `request` — the
        executor stops and reports "review needed"; the user runs Codex;
        `record` — the executor verifies and commits the record
User    /git:merge <id>           → merge --no-ff into main (verifier passes)
Claude  /opsx:archive             → specs synced, change archived
```

### Risk tiers (declared in `proposal.md` as `**Risk-Tier:** low|medium|high`)

| Tier | Covers | Review required |
|---|---|---|
| `low` | docs, comments, config wiring, tests-only, refactor with no behavior change | none — self-check + CI |
| `medium` | ordinary feature or behavior change | Gate 2 (code review) |
| `high` | auth/authorization, money and rounding, payments/webhooks, stock or any concurrency, deletion or irreversible migrations, external side effects, security-sensitive input handling, verifier/CI infrastructure | Gate 1 + Gate 2, plus a demonstrated failing input for every new check |

The user may raise a tier at any time. A reviewer who thinks a tier is
too low says so as a `major` finding.

- **Gate 1** — after proposal/design/spec/tasks exist, before implementation.
- **Gate 2** — after implementation, before merge and archive.
- A gate passes when the LAST decision record for that gate in
  `review.md` reads `approved`, `confirmed`, or `waived`.
- Gate 2 freshness: at merge time, nothing but `review.md`,
  `handoff.md` and `tasks.md` may differ between the Gate 2
  `Reviewed-Commit` and the branch head (`scripts/workflow-verify.sh
  merge <id>` checks this). Gate 1 has no freshness rule: design/task
  edits that merely describe the implementation do not reopen it;
  only a change of scope, requirements or architecture does — then
  the executor requests Gate 1 again.
- A user may waive a gate verdict (block format below). Waivers never
  cover task completion, strict validation, green checks or branch
  hygiene — those are fixed, not waived.

## Review Protocol

Review state lives in `openspec/changes/<id>/review.md`, append-only.
Three record kinds, one ordered stream per gate:

```markdown
# Review — <change-id>

## Round <n> · Gate <1|2>
**Reviewer:** codex
**Date:** YYYY-MM-DD
**Reviewed-Commit:** <sha of the change branch HEAD under review>
**Verdict:** approved | changes-requested

### Findings
| # | Severity | Location | Finding | Status |
|---|----------|----------|---------|--------|
| 1 | blocker | file/section | description | open |

## Confirmation <n> · Gate <1|2> · Round <source-round>
**Reviewer:** codex
**Date:** YYYY-MM-DD
**Reviewed-Commit:** <HEAD reviewed>
**Verdict:** confirmed | changes-requested

### Findings
| # | Resolution |
|---|------------|
| 1 | confirmed |

## Waiver · Gate <1|2>
**Granted-by:** user
**Date:** YYYY-MM-DD
**Commit:** <sha of the change branch HEAD being waived>
**Verdict:** waived
```

Severities: `blocker` — must be fixed before the gate passes; `major` —
fix, or obtain explicit user acceptance; `minor` — executor's
discretion. The executor updates finding Status (`open` → `fixed` or
`wont-fix (+reason)`) and never edits Finding text. If the reviewer
maintains an objection against a `wont-fix`, the user decides.

A **confirmation** reviews only the diff since the source round and the
named findings; it introduces no unrelated minor findings. After two
failed confirmations on the same finding, stop: split or reduce the
change, or ask the user to arbitrate — do not loop.

Fix the CLAIM, not the line: when a finding is fixed, `rg` the whole
repository for the term or rule it touched (spec, design, tasks, docs,
tests) so the old statement does not survive in a sibling artifact.
This was the single most expensive defect class in the process this
workflow descends from.

## Review Invocation

`scripts/gate-run.sh` IS the invocation; `/gate-review` is a thin
wrapper. It reads the review mode from this file and refuses the
operations of the other mode.

| Mode | Operations | What the runner does |
|---|---|---|
| `auto` | `<id> <gate> full` · `<id> <gate> confirm <round>` | lock, repository validation, the mechanical floor (`scripts/pregate-verify.sh`), the bounded hermetic Codex call, verification of the reviewer's output, commit of `review.md` with a `Co-Authored-By: Codex` trailer |
| `manual` | `<id> <gate> request [<round>]` | lock, validation, the floor, then PRINTS the prompt (identifiers only) and stops; nothing is written or committed. The executor reports "review needed: gate N, commit X" to the user and ends the turn |
| `manual` | `<id> <gate> record` | after the user ran Codex: verifies that only `review.md` changed, that the last record is a Round/Confirmation for the gate bound to the current HEAD with a valid verdict, then commits it with the Codex trailer |

Between `request` and `record` nothing may be committed on the branch:
the record must bind to the commit that was requested. The user hands
Codex the printed prompt verbatim (interactive `codex` in the
repository, or the printed `codex exec` one-liner) and lets it write
`review.md` only.

- **Pull, not push.** The prompts are constants inside the runner and
  carry identifiers only. Codex gathers context itself: this file, the
  change artifacts, `openspec/config.yaml`, and for Gate 2
  `git diff main...change/<id>`. No summary, hint or focus area reaches
  the prompt — that is the independence guarantee of the review. In
  manual mode the same holds: the user passes the prompt unchanged.
- **Sandbox cannot commit — the runner does.** Codex runs with
  `-s workspace-write --ignore-user-config`; it writes only `review.md`.
- **A confirmation is refused while any finding of its source round is
  still `open`** — disposition every row first (`/workflow:fix-findings`).
- **Failure is fail-closed.** Timeout, non-zero exit, an unexpected
  modified file, or a record not bound to the reviewed commit → the gate
  is NOT passed, nothing is committed, `review.md` is left modified for
  inspection. The executor never completes or edits a reviewer's record.
- Quick, record-less alternative for a sanity pass (not a gate):
  `codex exec review --base main`.

## Handoff Protocol

`openspec/changes/<id>/handoff.md`, overwritten at each session end:

```markdown
# Handoff — <change-id>

**Updated:** YYYY-MM-DD · <agent>
**State:** proposing | awaiting-gate-1 | fixing-g1 | implementing |
awaiting-gate-2 | fixing-g2 | ready-to-merge | merged | archived | blocked
**Branch:** change/<id>

## Done this session
## Next step
## Blockers
```

`blocked` is legal from any state and must name the blocker. In manual
review mode `awaiting-gate-1/2` also means "review requested, the user
runs Codex next"; the handoff's Next step names the exact
`scripts/gate-run.sh <id> <gate> record` call.

## Definition of Ready

A gate is requested only when the executor's own checks are done — the
reviewer's job is unknown weaknesses, not the executor's missing steps.

- **Before Gate 1:** `proposal.md` has `**Risk-Tier:**` and a
  `## Non-goals` section; every scope claim has an implementation task
  AND a verification task in `tasks.md`; each task is feasible at its
  lifecycle point. For `high` tier, `design.md` carries a short
  applicability table covering only the triggered questions: crash
  before/after an external effect, concurrent writers, money rounding,
  empty/zero/null inputs, authorization boundary, deletion/expiry,
  idempotency of retries. Non-applicable rows get one `n/a` line.
- **Before Gate 2:** `make check` green; every checked task has evidence
  (test, diff, or rendered output); every documented command was run in
  its exact form; every changed document re-read whole after the last
  edit; for `high` tier every new or changed check has a demonstrated
  failing input (a test that fails when the guard is removed).
- **Mechanical floor:** `scripts/pregate-verify.sh <gate1|gate2> <id>`
  — run by `/gate-review` before the reviewer (FAIL aborts) and by
  `/workflow:handoff` when targeting `awaiting-gate-1/2`.

## Git Conventions

- Branch per change: `change/<id>`; never implement on `main`.
- Direct commits to `main`: only baseline/doc fixes with explicit user
  consent, plus the user-confirmed post-merge archive commit.
- Conventional Commits, English, imperative mood (`feat:`, `fix:`,
  `docs:`, `test:`, `refactor:`, `chore:`, `ci:`, `review:`).
- Commit per logical task block; small commits — the history is read.
- Agent-authored commits end with the agent's `Co-Authored-By` trailer.
- Merging into `main` is the user's action (`/git:merge` executes it on
  the user's behalf after the verifier passes).
- **Agents never push, force-push, rebase shared history, or delete
  files/branches without explicit confirmation.**

## Session-End Protocol (strict)

Before ending any working session:

1. Update `tasks.md` checkboxes to actual state.
2. Run `openspec validate <id> --strict` — must pass.
3. Commit all change-scoped work to the change branch. No uncommitted
   work is left between sessions. Unrelated files are not swept in.
4. Update `handoff.md` (state, done this session, next step, blockers).
5. Reconcile `openspec/ROADMAP.md` if the plan changed.
6. If handing off into `awaiting-gate-1/2`: `scripts/pregate-verify.sh`
   passes for the target gate.

If completing the protocol is impossible, a `blocked` handoff naming
the blocker is the legal fallback — never silently abandon a session.
`/workflow:handoff` executes this protocol as one flow.

## Verification Rules (all agents)

Verify against the current source before stating facts: file contents
→ read the file; CLI behavior → `--help` of the exact subcommand; git
state → `git`; library versions and APIs → the installed package or
first-party docs. Verify before implementing, not after an error.
Earlier scans of the repository are not current: re-read a file at the
moment a claim depends on it.

## Security-Sensitive Code

Agent-written code is a draft a named developer owns. Any change
touching authentication, authorization, cryptography, input handling,
money, payments, file uploads, or dependencies is flagged as such in
the commit body and in `handoff.md`, and is `high` tier. Secrets never
enter code, config, docs, commits or chat — reference them by name from
`.env` (gitignored) or CI variables. If a secret appears in output, say
so and advise rotating it; do not repeat it.

## Language Policy

English for all code, comments, documentation, commit messages and
repository artifacts. Russian for conversation with the user.

## Documentation Layout

Three coexisting systems store different objects. Put content where
its object belongs; do not duplicate across systems — prefer one
authority plus references.

| Object | Location |
|---|---|
| What the system must do (living requirements per capability) | `openspec/specs/<capability>/spec.md` |
| Work in flight (proposal, design, tasks, review, handoff) | `openspec/changes/<id>/` — archived after merge |
| Why a decision was made (append-only log) | `docs/adr/ADR-NNN-*.md` — superseding means a new ADR, never editing the old one |
| How to use / understand (Diátaxis) | `docs/tutorials/`, `docs/how-to/`, `docs/reference/` describe only what WORKS; `docs/explanation/` may describe decided design citing its ADR |
| Cross-change roadmap | `openspec/ROADMAP.md` — updated within changes |
| Agent process rules (auto-loaded) | `AGENTS.md` (+ `.claude/rules/`) |
| Stack facts agents need every session | the Stack section below |

## Changing This Process

The process grows only against evidence. A new rule, check or ADR is
adopted only when the same defect appeared in several distinct changes
(not one incident) AND the proposal names what manual step it retires.
Prefer, in order: making the defect impossible by construction; an
existing check; a new check; an executor procedure; a review
obligation. One-off incidents are fixed, not legislated.

### Claude Code-Specific Notes

- `.claude/rules/*.md` are auto-loaded (terse behavior; verify-don't-
  assume; language). `.claude/settings.json` holds the shared permission
  allow/deny list; personal overrides go to the gitignored
  `.claude/settings.local.json`.
- `.claude/commands/`: `/workflow:start`, `/workflow:status`,
  `/workflow:handoff`, `/workflow:fix-findings`, `/gate-review`,
  `/git:commit`, `/git:merge`. `/opsx:*` commands and the
  `openspec-*` skills are generated by `openspec init --tools
  claude,codex`; refresh them with `openspec update`, never edit by hand.
- IDE MCP endpoint (`.mcp.json`) is machine-local and gitignored — see
  `docs/how-to/local-mcp.md`.

### Codex-Specific Notes

- Reads this file natively. Invoked for gate reviews either headless by
  `scripts/gate-run.sh` (`auto`) or interactively by the user with the
  prompt the runner printed (`manual`); writes only `review.md`, runs
  no git write commands.
- Uses the `.agents/skills/openspec-*` skills generated by `openspec init`.
- Repo-local `.codex/config.toml` is untracked and machine-specific.

<!-- STACK:BEGIN -->
## Stack

PHP 8.3 · Symfony 7 · API Platform · Doctrine ORM · Messenger ·
PostgreSQL 16 · Redis 7 · Docker Compose. Full requirements:
`docs/explanation/requirements.md` (the original brief); ordered plan:
`openspec/ROADMAP.md`.

### Commands (all through `make`; PHP runs inside the `php` container)

| Command | Purpose |
|---|---|
| `make init` | first run: build, up, `composer install`, migrate |
| `make up` / `make down` | start / stop (DB data lives in a named volume) |
| `make sh` | shell in the php container |
| `make composer ARGS='…'` / `make console ARGS='…'` | composer / `bin/console` |
| `make migrate` / `make migration` | apply migrations / generate a diff migration |
| `make worker` | `messenger:consume async` in the foreground (stop with Ctrl-C) |
| `make test` / `make stan` / `make cs` / `make cs-fix` | PHPUnit / PHPStan / style check / style fix |
| `make check` | **the gate floor**: `cs` + `stan` + `test` — must be green before Gate 2 and merge |

In CI the same targets run natively: `make check EXEC=`.

### Layout (symfony/skeleton + flex; `src/` split by bounded context, not by Symfony layer)

```
config/          bundles, packages/*.yaml, routes, services.yaml
src/
  Link/          entity, repository interface + Doctrine repository, DTOs, API resource, routing rules
  Redirect/      the public GET /{slug} controller, rule matcher, device/geo resolvers
  Click/         ClickRecorded message + handler (write model), click entity/partition
  Analytics/     read model: query services over window functions, cached report DTOs
  Auth/          users, API keys, security voters
  Shared/        kernel-level: value objects, exceptions, problem-details normalizer, clock
migrations/      doctrine/migrations classes (reviewed, reversible)
public/          docroot (index.php)
tests/           Unit/ (pure PHP), Integration/ (Kernel + DB), Api/ (ApiTestCase)
var/             cache, logs (gitignored)
```

### Code style

- `declare(strict_types=1)` in every file; `final` classes by default;
  constructor promotion; `readonly` value objects; typed everything;
  PHP-CS-Fixer `@Symfony` + `@Symfony:risky`.
- Doctrine as **DataMapper**: entities carry no persistence logic and no
  framework base class; repositories are interfaces in the domain
  directory with a Doctrine implementation beside them; no lazy-loading
  surprises in loops (explicit joins / fetch modes).
- Controllers and API Platform state processors/providers are thin;
  use cases live in application services; Messenger messages are
  immutable DTOs.
- **CQRS-lite**: the click write path (Messenger handler → INSERT) and the
  analytics read path (query services → cached DTOs) never share
  entities.
- Configuration through env vars + `config/packages/*.yaml`. Symfony
  convention for env files: `.env` and `.env.test` are committed and hold
  local defaults only; real secrets live in `.env.local` (gitignored) and
  are referenced by name — never in yaml, never in `.env`.
- One error format for the API: RFC 9457 problem details (API Platform
  default), no stack traces outside `dev`.

### Domain rules (from the brief — interviewers look for these)

- `slug` unique (case-sensitive, reserved words blocked); `expires_at`
  and `max_clicks` enforced at redirect time, not by a cron.
- Routing rules are JSONB, validated by a schema on write; matching
  order is explicit (device → country → language → default), A/B split
  is deterministic per visitor where possible.
- The redirect never waits for the database write of the click: it
  dispatches `ClickRecorded` to the async transport and responds 302 /
  307. Failure to log never turns into a failed redirect.
- Analytics aggregates come from SQL (window functions, `date_trunc`),
  not from PHP loops; hot reports cached in Redis with explicit TTL and
  invalidation on link change.
- API keys are hashed at rest; rate limits are per key and per IP for
  anonymous redirects.

### Risk-tier triggers specific to this project

`high`: security firewall/voters/API keys, redirect target validation
(open-redirect and SSRF classes), rule evaluation for untrusted input,
any migration that partitions or drops `clicks`, Messenger retry/failure
transport changes. `medium`: resources, analytics queries, rules
features. `low`: docs, config wiring, QR rendering tweaks.

### Testing

- Critical paths always tested: slug uniqueness, expiry/limit at
  redirect, rule matching matrix (device × country × default), async
  dispatch on redirect (Messenger in-memory transport), analytics
  window-function queries against real PostgreSQL, voter boundaries
  (owner vs stranger vs admin), API key auth and rate limit.
- `Unit/` runs without the kernel; `Integration/` and `Api/` use the
  test database (`.env.test`, schema from migrations, `dama/doctrine-
  test-bundle` for per-test transactions).
<!-- STACK:END -->
