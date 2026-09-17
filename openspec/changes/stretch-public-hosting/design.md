# Design — stretch-public-hosting

## Context

See `proposal.md` — Why. Three facts of the current repository shape everything
below, each read from the source before this was written:

- **The only image is a development image.** `.docker/php/Dockerfile` bind-mounts
  nothing itself but is used with `volumes: [.:/app]`, creates its user from
  `HOST_UID`/`HOST_GID` so the mounted tree stays writable, and installs no
  application code. There is no `--no-dev` install, no compiled asset map, no
  production PHP settings.
- **The only compose stack is a development stack.** Ports bind to
  `${BIND_ADDRESS:-127.0.0.1}`, every service reads `env_file: .env` — the
  committed file of local defaults — and the worker exists as a `--profile
  worker` opt-in rather than as a service that is always running.
- **The application already has the seams this needs.** `StartupChecks` runs
  every service tagged `app.startup_check` from `Kernel::boot()`, so "a
  misconfiguration fails before the first request" is an existing mechanism with
  two existing users (`ClickRetention`, `ChainCountryResolver`). `/health` exists
  and is outside the API contour. `app:click:partitions` and `app:demo:seed`
  exist as commands. The API's path rules are already parameters in
  `config/services.yaml` precisely so that two readers cannot drift apart.

## Goals / Non-Goals

**Goals:**

- One image and one compose file that a reviewer can read top to bottom and see
  the whole contour — no step that lives only in somebody's shell history.
- Every switch this change introduces defaults to today's behaviour, so the
  existing suite is the regression test for "nothing changed for development".
- The decisions that are security-shaped — registration, secrets, the destructive
  seed guard, the client's address — are each one mechanism with one test, not a
  rule restated per surface.

**Non-Goals (design level):**

- Zero-downtime deploys, blue/green, or a rollout that does not stop the
  container. A demo instance may be down for the seconds a restart takes.
- Configuration management (Ansible and friends). The deploy document is a
  sequence of commands, because one host is not a fleet.
- Making the development stack production-like. They stay two contours on
  purpose; the shared thing is the image's *build context*, not its behaviour.

## Decisions

### 1. Two stages in the existing Dockerfile, not a second Dockerfile

`.docker/php/Dockerfile` gains a production stage on top of the current one
rather than a `Dockerfile.prod` beside it. The extension set, the PHP version
and every pinned digest are then written once; a second file would have to
repeat them and would rot the moment one is bumped — which is exactly the
"same rule, two implementations" defect this repository has already paid for
twice.

*What it does not guarantee.* The two stages still differ in ways a reader must
see rather than infer: the user, the settings and whether the source is inside.
The stage names say so and the deploy document names them.

*Alternative considered.* A separate file, rejected above. A single stage
switched by a build argument, rejected because a build argument that changes
who the user is and whether development dependencies exist is a footgun with no
compile-time check.

### 2. Caddy, not nginx plus certbot

TLS has to be terminated and renewed by something. Caddy does both from a
configuration of a few lines, in one container, with no renewal timer and no
reload hook. The nginx already in the development stack would need certbot
beside it, a timer to renew, and a hook to reload — three moving parts where the
demo needs one, and three more things a reviewer has to check are actually
wired. The development stack keeps its nginx: it serves plain HTTP on loopback
and has no certificate to manage.

*What it does not guarantee.* The certificate still depends on the host name
resolving to the instance and on ports 80 and 443 being reachable; that is the
user's manual step, and the deploy document says so rather than pretending
otherwise.

*Alternatives considered.* A PaaS (managed TLS, but Postgres and Redis become
vendor services and the compose stack stops being the contour); Kubernetes with
cert-manager (an ingress controller, a certificate CRD and a chart for one
container — the anti-overengineering rule refuses it, and the ADR records the
refusal rather than leaving it implicit).

### 3. One listener decides registration, not one check per surface

Registration has two entry points — a Twig controller at `/register` and an API
Platform `Post` at `/api/v1/auth/register` — and the capability requires **both**
to answer 404 when the switch is off. Putting a check in each is the shape of
the bug: somebody adds a third entry point, or changes one and not the other,
and the switch silently half-works.

So the decision is a single `kernel.request` listener that refuses both paths
when the switch is off, reading its paths from a parameter in
`config/services.yaml` beside the API path rules that are already there for this
reason. It throws the framework's not-found exception, so the API path is
rendered as problem details by the error handling that already exists and the
web path by the existing 404 page — no second rendering either.

*What it does not guarantee.* A listener cannot stop a *new* registration entry
point from being added at a path nobody listed. The guard against that is the
test, which asserts the closed instance from both surfaces and is the thing a
third surface would have to be added to.

*Alternatives considered.* A routing `condition` on each route (Symfony resolves
condition functions registered with `routing.condition_service`, and API
Platform's `HttpOperation` does carry a `condition`) — rejected because it is
two declarations again, one of them inside an attribute where the next reader
will not look. Removing the routes from the container when the switch is off —
rejected because the switch would then be a build-time property of the image,
and the same image has to be able to run as a demo and as an ordinary instance.

### 4. The secret contract is a startup check, and it knows the committed defaults

`APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT` and the store credentials are
required. A check tagged `app.startup_check` fails the boot when one is unset,
empty, **or still equal to the value committed in `.env` for local development** —
the last clause matters more than the first two, because an operator who copies
`.env` to the host gets a working instance with a published salt and a published
database password, and nothing would otherwise complain. It runs from
`Kernel::boot()`, so the worker and the scheduler refuse for the same reason the
web application does, rather than each needing to remember.

The message names the **setting**, never the value found. The values themselves
are generated on the host into a gitignored `.env.local`; the deploy document
gives the generating commands and names the variables, and no value enters the
repository, an image layer, a log or this conversation.

*What it does not guarantee.* It cannot tell a weak secret from a strong one —
only a missing one from a present one, and the committed defaults from anything
else. Nor does it protect the host's own file permissions.

### 5. The demo guard is an instance property, not a command flag

`app:demo:seed` refuses `prod` today. A flag that lifted it (`--force`) would
travel in somebody's shell history straight onto a real instance, which is what
the guard is for. So the opt-in is a **setting of the instance** — off
everywhere, set only in the demo host's `.env.local` — and the command reads it.
No option of the command can lift the refusal, and the capability says so with
its own scenario.

The scheduled reload then runs the command that already exists, with `--reset`,
whose all-or-nothing transaction is already specified and tested.

*What it does not guarantee.* Someone who can set that variable on a real
production host can seed it — but that person can already read the database. The
guard is against accident, not against the operator.

### 6. The client's address, and why this is a correctness decision rather than a setting

Behind Caddy every request arrives from the proxy. The service already
guarantees, in two capabilities, that the per-IP limits count the *client* and
that a forwarded header from an untrusted peer creates no bucket — and
`framework.yaml` already reads `TRUSTED_PROXIES`. Deployed with the shipped
`127.0.0.1` the proxy is not trusted, so every visitor is counted as the proxy,
and the redirect limiter becomes a single global bucket of 60 per minute: the
demo would rate-limit itself. The deployment sets the proxy's address, and the
verification is a forwarded request through the real stack, not a claim in a
document.

## Applicability

| Question | Answer |
|---|---|
| Authorization boundary | Registration is a public surface being closed on two entry points; decision 3 makes them one mechanism, and the failing input is reaching the surface the fix did not cover. The demo account is an ordinary `ROLE_USER`; nothing here grants a role. |
| Deletion/expiry | The scheduled reload runs `--reset`, which **deletes** the demo accounts and everything owned by them. Its all-or-nothing transaction is already specified and tested (capability `demo-data`); what is new is that it can now run in `prod`, which is why decision 5 makes the opt-in an instance property rather than a flag. |
| Empty/zero/null inputs | The secret check treats unset, empty **and the committed default** alike (decision 4) — the third is the one a naive check misses, and it is the one that actually happens. |
| Crash before/after an external effect | The scheduled reload is the only scheduled write. A crash mid-reload leaves the former dataset intact by the existing transaction guarantee; a crash between "deleted" and "seeded" is precisely the case that guarantee's own scenario covers. Certificate issuance is external but idempotent and owned by Caddy. |
| Idempotency of retries | A repeated reload is a further `--reset`: it converges on the seeded dataset rather than accumulating. A repeated partition run is already idempotent (capability `click-logging`). |
| Concurrent writers | n/a — one instance, one worker; the scheduler runs commands that already take their own locks. |
| Money rounding | n/a. |

## Risks / Trade-offs

- **The deployment cannot be proven end to end without deploying** → the failing
  inputs are chosen to be provable locally: the production image is built and run
  in CI and locally without a mount; the secret check is asserted by booting with
  each variable unset and with each committed default; the proxy contract is
  asserted by a forwarded request through the prod-like stack. What remains
  unproven is the certificate, which needs a public host name — and the document
  says that plainly rather than implying it was tested.
- **A prod-like stack on the developer's machine is another thing to keep green**
  → it is the same image CI builds, and the deploy document's commands are run in
  their exact printed form, which is the repository's existing rule for how-to
  documents.
- **Closing registration changes a public surface** → the switch defaults to on,
  so every existing test keeps its meaning, and the new behaviour has its own
  tests from both entry points.
- **A demo instance is a URL shortener open to the internet** → redirect targets
  are already validated against the open-redirect and SSRF classes, links belong
  to one account that cannot be created by strangers, and the dataset is wiped on
  a cadence. What this does not do is moderate content that the demo account's
  own visitors create between reloads; the deploy document names that as the
  reason the reload cadence is short.

## Migration Plan

Nothing to migrate: the change adds files and settings whose defaults reproduce
current behaviour. The deploy document is the forward path, and the rollback for
the demo instance is `docker compose down` plus the host — there is no data in it
that is not regenerated by the seed.

## Open Questions

None. The three the parked handoff carried — where, what public exposure changes,
how secrets are set — are decided above and in `handoff.md`'s user-decision log.
