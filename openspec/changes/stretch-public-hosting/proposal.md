# Proposal — stretch-public-hosting

**Risk-Tier:** high

## Why

Roadmap row 16 is the last one, and it is the row that lets someone *try*
the service instead of reading it. Everything built so far assumes a
laptop: the only image bind-mounts the working tree and runs as the host
user, the only compose stack binds to loopback and reads committed
defaults out of `.env`, there is no TLS, no path for a real secret, no
supervision of the worker and no schedule for the maintenance the
service already needs. So "put it online" is not a README line — it is a
second, deliberately different contour, and the interesting part is that
this one is exposed.

The user's decision of 2026-09-17 bounds it: the change ships that
contour, reviewed and documented; **provisioning the host, the domain
and the certificate stays the user's single manual step**, and nothing
here performs an externally visible action.

## What Changes

- **A production image**, separate from the development one and built
  from the same `.docker/php` directory: a multi-stage build that
  installs dependencies with `--no-dev`, compiles the asset map, warms
  the cache, ships the source **inside** the image instead of mounting
  it, runs as a fixed non-root user, and carries production PHP settings
  (`opcache.validate_timestamps=0`, no development extensions).
- **A production compose profile** (`docker-compose.prod.yml`): Caddy
  terminating TLS, the application, the Messenger worker as a supervised
  service, a scheduler for the periodic commands, Postgres and Redis.
  The development stack is untouched and stays the one `make up` starts.
- **HTTPS with automatic certificates** through Caddy, HTTP redirected
  to HTTPS, HSTS, and the health endpoint used as the proxy's upstream
  check.
- **The trusted-proxy contract.** Behind a reverse proxy the client IP
  arrives in a forwarded header. Two requirements the service already
  makes — the per-IP redirect limit and the per-IP auth limit — say the
  client IP is the one derived through the trusted-proxy configuration,
  and the country resolver reads a forwarded header too. Deployed with
  the shipped default (`TRUSTED_PROXIES=127.0.0.1`) every visitor would
  share one bucket. The deployment names the proxy and the shipped
  configuration is verified against a forwarded request.
- **A secret contract, and a boot that enforces it.** `APP_SECRET`,
  `JWT_PASSPHRASE`, `VISITOR_HASH_SALT`, the database password and the
  Redis credentials are named, generated **on the host** into a
  gitignored `.env.local`, and never enter code, configuration, docs,
  commits or chat. A startup check — the existing `app.startup_check`
  tag, not a new mechanism — fails the boot when one of them is unset or
  still carries the committed local default, so a misconfigured instance
  refuses to start instead of running with a predictable key.
- **Registration closed on a public instance.** A configuration switch,
  honoured by **both** authenticated surfaces: the web form at
  `/register` and `POST /api/v1/auth/register`. Off, both answer 404 and
  the web UI stops linking to the form; on, behaviour is exactly what it
  is today. The switch defaults to *on*, so development and the test
  suite are unchanged.
- **Seeded data on a `prod` instance, deliberately.** `app:demo:seed`
  currently refuses to run in `prod` — a guard that exists so nobody
  wipes a real database by accident. A demo instance is the case that
  guard did not anticipate, so the refusal gains an explicit, named
  opt-in that only the demo host sets; without it the refusal is exactly
  what it is today.
- **A schedule**: the demo dataset reloaded on a cadence (`--reset`),
  and `app:click:partitions` run so the partition horizon keeps moving.
  Both already exist as commands; this change gives them a runner.
- **Documentation**: `docs/how-to/deploy.md` with the commands in the
  exact form they were run, a README section with the demo link and how
  to sign in, and an ADR recording why a VPS with compose and Caddy
  rather than a PaaS or Kubernetes.
- **CI builds the production image** (build only, no registry push), so
  the deployment path cannot rot unnoticed while nobody deploys.

## Capabilities

### New Capabilities
- `deployment`: what a production deployment of this service is — the
  image, the process set, TLS and the proxy contract, the secrets it
  requires and the boot that refuses without them, the schedule it runs,
  and what the public demo instance specifically exposes.

### Modified Capabilities
- `demo-data`: the `prod` refusal becomes conditional on an explicit,
  named opt-in instead of absolute, so a demo instance can seed itself
  while an ordinary production instance still cannot.
- `user-accounts`: self-registration becomes configurable, and both the
  web and the API surface SHALL honour the same switch.

## Impact

- **New**: a production stage in `.docker/php/Dockerfile`, a Caddy
  configuration, `docker-compose.prod.yml`, a startup check for required
  secrets, a registration switch read by the web controller and the API
  processor, `docs/how-to/deploy.md`, an ADR, a CI job.
- **Changed**: `.env` gains the new settings with development defaults;
  `config/packages/framework.yaml` for the proxy; `DemoSeedCommand`'s
  environment guard; the security configuration and the registration
  routes; README.
- **Unchanged and guarded**: the development stack, `make` targets and
  the whole test suite — the switches default to today's behaviour, so a
  green suite with no edits is part of the evidence.
- **New dependency**: one image, `caddy`, and no PHP package. Justified
  against the anti-overengineering rule: TLS has to be terminated and
  the certificate renewed by *something*; Caddy does both from a
  six-line configuration in one container, where the nginx already in
  the stack would need certbot beside it plus a renewal timer and a
  reload hook — three moving parts instead of one, for the same result.
  Kubernetes and a PaaS were considered and rejected in the ADR.

## Non-goals

- **Deploying.** No host is provisioned, no domain is registered, no
  certificate is issued, nothing is pushed anywhere. The change is
  reviewed configuration and its documentation.
- **Continuous deployment.** CI builds the image; it does not deploy it,
  hold a registry credential or touch a host.
- **Open registration, e-mail, or password reset** on the public
  instance — the demo is one shared account.
- **Backups, monitoring, alerting, multi-region, autoscaling,
  Kubernetes.** A single demo instance names no need an existing
  component cannot cover; the deploy document says what a real operator
  would add and why it is not here.
- **Changing the application's behaviour when the new switches are off.**
  Every default reproduces today's behaviour exactly.

## Why `high` rather than the roadmap's `medium`

The roadmap's tier is the minimum, and three of this change's decisions
are on AGENTS.md's `high` list rather than beside it: it puts a switch
in front of an **authorization surface** (registration, on two
independent entry points, where "off" must not be bypassable through the
one nobody remembered); it decides how **secrets** reach a running
instance and makes a missing one fatal at boot; and it relaxes a
**guard on a destructive command** (`--reset` deletes the demo accounts
and everything that hangs off them) so it can run in `prod`. Each wants
a demonstrated failing input rather than a claim.
