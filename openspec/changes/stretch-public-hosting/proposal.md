# Proposal — stretch-public-hosting

**Risk-Tier:** high

## Why

Roadmap row 16 is the last one, and it is the row that lets someone *try* the
service instead of reading it. Everything built so far assumes a laptop: the only
image is used with the working tree mounted over it and runs as the host user,
the only compose stack binds to loopback and reads committed defaults out of
`.env`, there is no TLS, no path for a real secret, no signing keypair on a clean
host, no supervision of the worker and no schedule for the maintenance the
service already needs. So "put it online" is not a README line — it is a second,
deliberately different contour, and the interesting part is that this one is
exposed.

The user's decision of 2026-09-17 bounds it: the change ships that contour,
reviewed and documented; **provisioning the host, the domain and the certificate
stays the user's single manual step**, and nothing here performs an externally
visible action.

## What Changes

- **A production stage in the existing `.docker/php/Dockerfile`**, built from the
  repository root with a `.dockerignore` that this change adds: dependencies
  installed with `--no-dev`, the source copied in, a fixed non-root user,
  production PHP settings with no timestamp validation in the opcode cache.
- **The build boots nothing, and the container provisions itself at start.**
  Warming the cache, compiling the asset map and generating the JWT keypair are
  all kernel boots, and the boot is where the new setting check lives — so doing
  them at build time would mean handing the build real secrets or exempting the
  build from the check. They move to the entrypoint, which runs after the
  settings have been accepted. The build therefore requires no secret by
  construction and bakes no placeholder.
- **Two named volumes carry what the image deliberately does not, and one
  service writes them.** The compiled assets, served directly by the proxy —
  PHP-FPM cannot serve static files and the proxy has no copy of the application.
  And the JWT keypair, generated only when absent, so that replacing a container
  does not invalidate every token in the wild; the private key stays out of every
  image layer and out of the repository. Both are written by a **one-shot init
  service** the other containers wait for, because generating a keypair is not
  atomic and three containers starting together on a clean host could otherwise
  leave one run's private key beside another's public key.
- **A production compose profile** (`docker-compose.prod.yml`): Caddy terminating
  TLS, the application, the Messenger worker as a supervised service, a scheduler
  for the periodic commands, Postgres and Redis. The development stack is
  untouched and stays what `make up` starts.
- **HTTPS with automatic certificates** through Caddy, HTTP permanently
  redirected, HSTS, and `/health` as the proxy's upstream check.
- **The trusted-proxy contract.** Two requirements the service already makes —
  the per-IP redirect limit and the per-IP auth limit — say the client IP is the
  one derived through the trusted-proxy configuration, and the country resolver
  reads a forwarded header. Deployed with the shipped `TRUSTED_PROXIES=127.0.0.1`
  every visitor would share one bucket and the demo would rate-limit itself. The
  deployment names the proxy and the configuration is verified with a forwarded
  request through the real stack.
- **A required-settings contract, enforced at boot, enumerated by consumer.**
  `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT`, `DATABASE_URL`,
  `REDIS_URL`, `LOCK_DSN` and `MESSENGER_TRANSPORT_DSN` — the last three because
  Redis is reached through three independent settings and checking one would
  leave two carrying committed values. The production compose takes **one
  authoritative credential per store** and derives those connection strings from
  it, so the database server and the application cannot end up holding different
  passwords. Each effective setting must be set, non-empty, and **not equal to
  the value committed in `.env`**; otherwise the boot fails naming the
  setting, in the web process, the worker and the scheduler alike. It reuses the
  existing `app.startup_check` tag rather than adding a mechanism.
- **Registration closed on a public instance.** One configuration switch,
  honoured by **both** authenticated surfaces through one listener: the web form
  at `/register` and `POST /api/v1/auth/register`. Off, both answer 404 and the
  web UI stops linking to the form; on, behaviour is exactly today's. The switch
  defaults to on, so development and the suite are unchanged.
- **Seeded data on a `prod` instance, deliberately and safely.** `app:demo:seed`
  refuses `prod` today — a guard so nobody wipes a real database. A demo instance
  is the case that guard did not anticipate, so the refusal gains an explicit
  opt-in that is a **setting of the instance**, never an option of the command.
  Two further changes make a *scheduled* seed safe: the command takes a
  non-blocking lock, because it takes none today and a slow reload would meet the
  next one deleting the accounts it is recreating; and the demo password comes
  from a setting instead of being regenerated per run, because otherwise the
  first scheduled reload invalidates the credential a visitor was given. That
  password is published on the demo instance's own sign-in page and therefore
  never in the repository.
- **A schedule**: the demo dataset reloaded on a cadence (`--reset`), and
  `app:click:partitions` run so the partition horizon keeps moving. Both commands
  already exist; this change gives them a runner.
- **Documentation**: `docs/how-to/deploy.md` with the commands in the exact form
  they were run, a README demo section, and an ADR for why a VPS with compose and
  Caddy rather than a PaaS or Kubernetes.
- **CI builds the production image** with the same command the deploy document
  gives (build only, no push, no credential), so neither can drift from the other
  and the deployment path cannot rot unnoticed while nobody deploys.

## Capabilities

### New Capabilities
- `deployment`: what a production deployment of this service is — the image and
  what it deliberately does not do at build time, the start-time provisioning of
  assets and signing keys, the process set, TLS and the proxy contract, the
  settings it requires and the boot that refuses without them, the schedule, what
  the public demo instance exposes, and the CI build that deploys nothing.

### Modified Capabilities
- `demo-data`: the `prod` refusal becomes conditional on an instance setting; the
  command takes a non-blocking lock so two runs cannot overlap; the demo password
  may come from the instance so that a published credential survives a re-seed.
- `user-accounts`: self-registration becomes configurable, and both the web and
  the API surface SHALL honour the same switch.

## Impact

- **New**: a production stage in `.docker/php/Dockerfile`, a `.dockerignore`, an
  entrypoint, a Caddy configuration, `docker-compose.prod.yml`, a startup check
  for the required settings, a registration listener, `docs/how-to/deploy.md`, an
  ADR, a CI job.
- **Changed**: `.env` gains the new settings with development defaults;
  `framework.yaml` for the proxy; `DemoSeedCommand` (environment guard, lock,
  password source); the sign-in template; README; `openspec/ROADMAP.md`.
- **Unchanged and guarded**: the development stack, the `make` targets and the
  whole suite — the switches default to today's behaviour, so a green suite with
  no edits is part of the evidence.
- **New dependency**: one image, `caddy`, and no PHP package. Justified against
  the anti-overengineering rule: TLS must be terminated and renewed by
  *something*; Caddy does both from a few lines in one container, where the nginx
  already in the stack would need certbot beside it plus a renewal timer and a
  reload hook — three moving parts instead of one, for the same result.
  Kubernetes and a PaaS were considered and are refused in the ADR.

## Non-goals

- **Deploying.** No host is provisioned, no domain registered, no certificate
  issued, nothing pushed anywhere. The change is reviewed configuration and its
  documentation.
- **Publishing the demo URL.** Roadmap row 16 as written also asks for the live
  instance and its link in the README. This change cannot produce a URL nobody
  can visit, so it **splits the row**: row 16 is the deployment configuration,
  and a new row records publishing the instance and adding its link — the user's
  step, because only the user has the host and the domain. The README's demo
  section is written with the link absent and says where it comes from, and §9 of
  the requirements is **not** marked delivered by this change.
- **Continuous deployment.** CI builds the image; it does not deploy it, hold a
  registry credential or touch a host.
- **Open registration, e-mail, or password reset** on the public instance — the
  demo is one shared account.
- **Backups, monitoring, alerting, multi-region, autoscaling, Kubernetes.** A
  single demo instance names no need an existing component cannot cover; the
  deploy document says what a real operator would add and why it is not here.
- **Changing behaviour when the new switches are off.** Every default reproduces
  today's behaviour exactly.

## Why `high` rather than the roadmap's `medium`

The roadmap's tier is the minimum, and four of this change's decisions are on
AGENTS.md's `high` list rather than beside it: a switch in front of an
**authorization surface** with two independent entry points, where "off" must not
be bypassable through the one nobody remembered; how **secrets** reach a running
instance, and a boot that refuses without them; relaxing the guard on a
**destructive command** (`--reset` deletes the demo accounts and everything that
hangs off them) so it can run in `prod`, plus the **concurrency** that a schedule
introduces around it; and a change to how the **client's address** is derived,
which is what two existing rate limits count. Each wants a demonstrated failing
input rather than a claim.
