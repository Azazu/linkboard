# Design — stretch-public-hosting

## Context

See `proposal.md` — Why. Four facts of the current repository shape everything
below, each read from the source before this was written:

- **The only image is a development image.** `.docker/php/Dockerfile` is used
  with `volumes: [.:/app]`, creates its user from `HOST_UID`/`HOST_GID` so the
  mounted tree stays writable, and installs no application code. There is no
  `--no-dev` install, no compiled asset map, no production PHP settings. There
  is also **no `.dockerignore`**.
- **The only compose stack is a development stack.** Ports bind to
  `${BIND_ADDRESS:-127.0.0.1}`, every service reads `env_file: .env` — the
  committed file of local defaults — and the worker is a `--profile worker`
  opt-in rather than a service that is always running.
- **`StartupChecks` runs on every kernel boot**, from `Kernel::boot()`, for the
  web process, the console, the worker and the test kernel alike. That is the
  seam this change's setting check belongs in — and it is also the reason no
  build step may boot the kernel (decision 2).
- **`config/jwt/` is gitignored** and the keypair is generated per environment.
  A clean host has none, so something has to make one, and it must not be the
  image (decision 3).

## Goals / Non-Goals

**Goals:**

- One image and one compose file that a reviewer can read top to bottom and see
  the whole contour — no step that lives only in somebody's shell history.
- Every switch this change introduces defaults to today's behaviour, so the
  existing suite is the regression test for "nothing changed for development".
- The decisions that are security-shaped — registration, the required settings,
  the destructive seed guard, the client's address — are each one mechanism with
  one test, not a rule restated per surface.

**Non-Goals (design level):**

- Zero-downtime deploys, blue/green, or a rollout that does not stop the
  container. A demo instance may be down for the seconds a restart takes.
- Configuration management (Ansible and friends). The deploy document is a
  sequence of commands, because one host is not a fleet.
- Making the development stack production-like. They stay two contours on
  purpose; what they share is the Dockerfile, not the behaviour.

## Decisions

### 1. Two stages in the existing Dockerfile, built from the repository root

`.docker/php/Dockerfile` gains a production stage on top of the current one
rather than a `Dockerfile.prod` beside it: the PHP version, the extension set
and every pinned digest are then written once. A second file would repeat them
and rot the moment one is bumped — the "same rule, two implementations" defect
this repository has already paid for twice.

The production stage copies the application in, so **the build context is the
repository root** and the Dockerfile is named explicitly:

```bash
docker build -f .docker/php/Dockerfile --target prod -t linkboard-prod .
```

A context of `.docker/php` cannot reach the application at all (Gate 1 round 1,
finding 8). Because the context is now the whole repository, a `.dockerignore`
is part of this change rather than an afterthought: `var/`, `vendor/`,
`node_modules/`, `.git/`, `config/jwt/` and the env files never enter the build
context — which also means a stray `.env.local` on a developer's machine cannot
be copied into an image.

*What it does not guarantee.* The two stages differ in ways a reader must see
rather than infer: the user, the settings and whether the source is inside. The
stage names say so and the deploy document names them.

### 2. The build boots nothing; the container provisions itself at start

This is the decision the rest hangs on, and it exists because two guarantees
collided (Gate 1 round 1, finding 2): the build must need no secret, and a
missing setting must stop the boot. `cache:warmup` and `asset-map:compile` are
console commands, so both boot the kernel, so both would run the check — and the
only ways to keep a build-time warm are to hand the build real secrets or to
exempt the build from the check. The first puts secrets in a build log, the
second is a bypass that will be used by accident.

So the build does the work that needs no kernel — install without development
packages, copy the source — and **the rest happens at start, after the settings
have been accepted**. Which process does which part is decision 3's subject and
is not a detail: the shared artefacts (the compiled assets and the signing
keypair) are written once by a one-shot init service, and each application
container's own entrypoint warms only its private cache. The check is not
weakened anywhere, and the build genuinely has nothing to bypass.

*What it does not guarantee.* The first start is slower than a start from a
pre-warmed image, and every container of the stack pays it. That is the price of
a check that cannot be bypassed; the deploy document states it rather than
letting an operator discover it.

*Alternatives considered.* Building with placeholder values (rejected: they end
up in a layer and in a log, and a placeholder that happens to work is worse than
none); a build-time flag the check honours (rejected: that is the bypass);
splitting Symfony's build directory from its cache directory so part of the warm
needs no environment (rejected as a partial answer — the asset compile and the
key generation still boot, so the entrypoint is needed anyway, and having two
mechanisms would be worse than one).

### 3. One volume carries the assets to the proxy, another carries the keys — written by a single provisioner

PHP-FPM cannot serve static files and the proxy has no copy of the application
(Gate 1 round 1, finding 3). The init service writes the compiled assets at start
into a **named volume**, which the proxy mounts read-only and serves directly;
the proxy passes everything else to the application. One producer, one consumer,
no bind mount of the working tree and no second image to keep in step.

The JWT keypair gets a volume of its own for a different reason: it must
**survive** a container being replaced, or every restart would invalidate every
token in the wild. The init service generates it only when absent. The private key
is therefore in a volume on the host and in no image layer, no repository and no
log (Gate 1 round 1, finding 4).

*What it does not guarantee.* A volume is host state: it is not backed up here,
and losing it means re-issuing tokens. The deploy document says which volumes
matter and why, and that backing them up is the operator's step.

*One writer, by construction.* Three containers run from this image — the web
application, the worker and the scheduler — and on a clean host they start at the
same moment against the same empty volumes. `lexik:jwt:generate-keypair
--skip-if-exists` builds a candidate pair **before** it checks whether the files
exist and writes the private and public keys separately, so concurrent first
starts can leave one run's private key beside another run's public key: an
instance that signs tokens nothing can verify (Gate 1 confirmation 1, finding 4).
So provisioning is not in the application containers' entrypoint at all. A
one-shot **init service** compiles the assets and generates the keypair, and the
three long-running services declare `depends_on: { init: { condition:
service_completed_successfully } }`. There is then exactly one writer and the
ordering is visible in the compose file rather than buried in a shell script; the
application containers warm only their own cache, which is private to each of
them. `--skip-if-exists` still covers the ordinary restart, where the keys are
already there.

*And a half-written pair is never served, because one writer is not enough.*
`--skip-if-exists` treats **either** file existing as "already provisioned", and
the generator writes the private and the public key as two separate files — so a
crash between the two writes leaves half a pair that every later start then
accepts, and dependants are allowed to serve with a key nothing can verify (Gate
1 confirmation 2, finding 4). Ordering does not help here: there is only ever one
writer and it still dies mid-way. Two file moves are two operations, so nothing
here claims atomic publication — a symlink switch could provide it, and is
deliberately not used because it buys nothing the ordering already gives. The
guarantee is the one the stack really provides, stated as such: provisioning
generates into a temporary directory **inside the same volume** and moves the two
files into place; if it dies anywhere, the init service exits non-zero, so
`service_completed_successfully` is false and **no dependant starts** — the
partial state exists on disk but nothing serves with it. And before deciding
anything, the next run repairs what it finds: a target holding only one of the
two files, or a pair whose public key is not the one derived from its private
key, is removed and regenerated rather than trusted (Gate 1 confirmation 3,
finding 4).

*What that does not guarantee.* Two production *stacks* brought up against one
volume, racing on the same host, are outside this: compose serialises the
services of one project, not two projects. The deploy document says the stack is
started once. And a keypair that is complete and self-
consistent but *older* than the tokens in flight is indistinguishable from the
right one; that is what keeping the volume is for.

*Alternatives considered.* A `flock` around the provisioning step inside each
entrypoint (works, since the containers share the volume's filesystem, but hides
the ordering in a script where the next reader will not look for it); copying the
public tree into a second image built for the proxy — rejected because two images
must then be built and deployed from one commit, and a mismatch between them is a
class of bug that is invisible until a page renders unstyled.

### 4. Caddy, not nginx plus certbot

TLS has to be terminated and renewed by something. Caddy does both from a few
lines of configuration, in one container, with no renewal timer and no reload
hook. The nginx already in the development stack would need certbot beside it, a
timer to renew and a hook to reload — three moving parts where the demo needs
one, and three more things a reviewer must check are actually wired. The
development stack keeps its nginx: it serves plain HTTP on loopback and has no
certificate to manage.

*What it does not guarantee.* The certificate still depends on the host name
resolving to the instance and on ports 80 and 443 being reachable; that is the
user's manual step, and the deploy document says so rather than pretending
otherwise.

*Alternatives considered.* A PaaS (managed TLS, but Postgres and Redis become
vendor services and the compose stack stops being the contour); Kubernetes with
cert-manager (an ingress controller, a certificate resource and a chart for one
container — the anti-overengineering rule refuses it, and the ADR records the
refusal rather than leaving it implicit).

### 5. One listener decides registration, not one check per surface

Registration has two entry points — a Twig controller at `/register` and an API
Platform `Post` at `/api/v1/auth/register` — and the capability requires **both**
to answer 404 when the switch is off. A check in each is the shape of the bug:
somebody adds a third, or changes one and not the other, and the switch silently
half-works.

So a single `kernel.request` listener refuses both paths when the switch is off,
reading them from a parameter in `config/services.yaml` beside the API path
rules that are already there for this reason. It throws the framework's
not-found exception, so the API path is rendered as problem details by the error
handling that already exists and the web path by the existing 404 page — no
second rendering either.

*What it does not guarantee.* A listener cannot stop a *new* registration entry
point from being added at a path nobody listed. The guard against that is the
test, which asserts the closed instance from both surfaces and is what a third
surface would have to be added to.

*Alternatives considered.* A routing `condition` on each route (Symfony resolves
condition functions registered with `routing.condition_service`, and API
Platform's `HttpOperation` does carry a `condition`) — rejected because it is two
declarations again, one of them inside an attribute where the next reader will
not look. Removing the routes from the container when the switch is off —
rejected because the switch would then be a build-time property of the image, and
the same image has to run as a demo and as an ordinary instance.

### 6. The required settings are the ones the application actually reads

Naming "the database and Redis credentials" is not a specification, because this
application reaches Postgres through `DATABASE_URL` and Redis through **three**
independent settings — `REDIS_URL`, `LOCK_DSN` and `MESSENGER_TRANSPORT_DSN`.
Checking a parallel `DB_PASSWORD` would pass an instance whose `DATABASE_URL`
still carried the committed password, and checking one Redis setting would leave
two (Gate 1 round 1, finding 5). The set is therefore enumerated by consumer:

`APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT`, `DATABASE_URL`,
`REDIS_URL`, `LOCK_DSN`, `MESSENGER_TRANSPORT_DSN`.

For each, the check refuses unset, empty, **and equal to the value committed in
`.env`** — the third clause being the one that matters, because an operator who
copies `.env` to the host otherwise gets a working instance with a published
salt and a published database password and no complaint from anything. The
committed values are read from `.env` itself rather than copied into PHP, so the
check cannot fall out of step with the file it is about.

**One credential per store, and the connection strings derived from it.** Naming
the settings is not enough on its own, because two of them can disagree: the
Postgres *server* takes its password from `DB_PASSWORD` while Doctrine connects
with `DATABASE_URL`, so an operator who changes only the second passes the check
while the server still holds the committed password — and an operator who changes
both, differently, gets a stack that starts and then cannot query (Gate 1
confirmation 1, finding 5). Redis has the same shape three times over.

So the production compose takes **one** authoritative input per store —
`DB_PASSWORD` and `REDIS_PASSWORD` — and *derives* every connection string from
it by interpolation, rather than letting a second literal exist:
`DATABASE_URL` from the user, password and database name; `REDIS_URL`, `LOCK_DSN`
and `MESSENGER_TRANSPORT_DSN` from the Redis password. A mismatch is then not
something an operator can introduce through configuration, and the check still
examines the effective connection strings, so a stack that somehow has a
committed one still refuses to boot.

**And the derivation has exactly one input file, named on every invocation.**
Compose interpolation does not read `.env.local`: it reads the shell, the
project's own `.env`, or the file `--env-file` names. A service-level
`env_file: .env.local` populates a container that has already been created and
does nothing for `${DB_PASSWORD}` in the compose file itself — so the derived
connection strings and the Postgres and Redis server settings would have resolved
from the **committed** defaults while the check passed on values nobody used
(Gate 1 confirmation 2, finding 5). Every invocation of the production stack is
therefore written, everywhere it appears, as

```bash
docker compose --env-file .env.local -f docker-compose.prod.yml <command>
```

— in the deploy document, in every task that brings the stack up, and in
anything CI runs.

*And it is one file, not the development file with an override on top.*
Measured rather than assumed: `docker compose -f a.yml -f b.yml config` **appends**
volume lists rather than replacing them, so overlaying the production file on
`docker-compose.yml` keeps its `.:/app` bind mount — the production stack would
silently run with the working tree mounted over the image it was built to carry,
defeating the self-contained image entirely. Compose's `!reset` tag can undo an
inherited list, but hiding a guarantee that large inside tag syntax is worse than
one self-contained `docker-compose.prod.yml` that a reviewer can read top to
bottom. The development stack and the deployment are two contours by decision;
this is where that stops being a slogan. The verification is `config` rendering the authoritative values
into both the server settings and every derived connection string, so that "the
interpolation source is the one we meant" is checked rather than assumed.

*What it does not guarantee.* It cannot tell a weak value from a strong one, only
a changed one from an unchanged one; it says nothing about the host's file
permissions; and someone who overrides a derived connection string by hand, or
omits `--env-file`, is outside the contract. Neither case is silent: the boot
check refuses a committed default, and the deep dependency probe reports a store
unreachable, which is what the deploy document tells an operator to look at.

### 7. The demo guard is an instance property; the seed locks; the password is the instance's

Three decisions about the demo dataset, each answering a way the obvious version
breaks:

- **The `prod` opt-in is a setting of the instance, not an option of the
  command.** A `--force` flag would travel in somebody's shell history straight
  onto a real host, which is what the guard exists to prevent.
- **The command takes a non-blocking lock** (Gate 1 round 1, finding 6). It takes
  none today, and a scheduled reload that outruns its interval would otherwise
  meet the next one deleting the accounts it is recreating. Non-blocking rather
  than waiting: a waiting run would still be there when the third one starts.
  The service already has a lock connection configured (`LOCK_DSN`).
- **The demo password comes from a setting of the instance** (Gate 1 round 1,
  finding 1). Today the seed generates a fresh random password per run and prints
  it to the operator's console — so the first scheduled reload would invalidate
  whatever a visitor had been given, and nothing would publish the replacement.
  With the setting, the credential is stable across reloads. It is published on
  **the demo instance's own sign-in page**, which keeps it out of the repository
  entirely: the README says the page states it, rather than stating it.

*What it does not guarantee.* Someone who can set these variables on a real host
can seed it, and anyone at all can read the demo password — which is the point of
a demo account, and why it owns nothing but regenerated data. An instance that
has not declared itself a demo publishes nothing.

### 8. The client's address is a correctness decision, not a setting

Behind Caddy every request arrives from the proxy. The service already
guarantees, in two capabilities, that the per-IP limits count the *client* and
that a forwarded header from an untrusted peer creates no bucket — and
`framework.yaml` already reads `TRUSTED_PROXIES`. Deployed with the shipped
`127.0.0.1` the proxy is not trusted, so every visitor is counted as the proxy
and the redirect limiter becomes one global bucket of 60 per minute: the demo
would rate-limit itself. The deployment sets the proxy's address, and the
verification is a forwarded request through the real stack rather than a claim in
a document.

### 9. What this change can deliver of roadmap row 16, and what it cannot

Row 16 reads "deploy to a public host with HTTPS, demo instance link in the
README", and the user's decision of 2026-09-17 is deployment-**ready**, not
deployed. Those cannot both be satisfied here, and a task that writes a URL
nobody can visit would be the artifact lying (Gate 1 round 1, finding 7).

So the row is **split in the roadmap by this change**: row 16 becomes the
deployment configuration, which is this change and ends at a reviewed, buildable,
locally exercised contour; a new row records publishing the instance and putting
its URL in the README, which only the user can do because only the user has the
host and the domain. This change writes the README's demo section with the link
absent and says where it will come from; it does not mark the public-hosting
item delivered in `docs/explanation/requirements.md` §9.

## Applicability

| Question | Answer |
|---|---|
| Authorization boundary | Registration is a public surface being closed on two entry points; decision 5 makes them one mechanism, and the failing input is reaching the surface the fix did not cover. The demo account is an ordinary `ROLE_USER`; nothing here grants a role. |
| Deletion/expiry | The scheduled reload runs `--reset`, which **deletes** the demo accounts and everything owned by them. Its all-or-nothing transaction is already specified and tested (capability `demo-data`); what is new is that it can run in `prod`, which is why decision 7 makes the opt-in an instance property. |
| Concurrent writers | **Applicable twice, and it was wrong to call it n/a.** First, `app:demo:seed` takes no lock today, and one host does not prevent a slow scheduled reload from overlapping the next: two runs would delete and recreate the same accounts. Decision 7 gives the command a non-blocking lock, and the capability carries a scenario for the second run being refused rather than queued. Second, the three containers of one stack start together against the same empty volumes on a clean host, and the keypair generator is not atomic — decision 3 makes provisioning a single one-shot service the others wait for, and the capability carries a scenario for a concurrent clean start producing one matching pair. |
| Empty/zero/null inputs | The setting check treats unset, empty **and the committed default** alike (decision 6) — the third is the one a naive check misses and the one that actually happens. |
| Crash before/after an external effect | Provisioning is idempotent: the keypair is generated only when a usable one is absent, so a crash between generating and serving leaves the next start correct rather than issuing a second keypair that would invalidate live tokens. A crash **between the two key files** is a different case and the single writer does not address it — ordering prevents two generators, not one generator dying mid-way. What addresses it is the fail/repair mechanism of decision 3: the init service exits non-zero, `service_completed_successfully` is false so no dependant starts, and the next run discards the partial or mismatched pair and regenerates before anything serves. Task 2.3 verifies those four states rather than only that a pair matches. The scheduled reload's crash behaviour is the existing transaction guarantee. Certificate issuance is external, idempotent and owned by Caddy. |
| Idempotency of retries | A repeated reload is a further `--reset`: it converges on the seeded dataset rather than accumulating. A repeated partition run is already idempotent (capability `click-logging`). A repeated container start recompiles the assets and keeps the keys. |
| Money rounding | n/a. |

## Risks / Trade-offs

- **The deployment cannot be proven end to end without deploying** → the failing
  inputs are chosen to be provable locally: the production image is built and run
  without a mount, the whole stack is brought up locally with an internal host
  name, the setting check is asserted by booting with each setting unset and each
  left at its committed default, tokens are issued on a clean stack, an asset is
  fetched through the proxy, and the proxy contract is asserted by a forwarded
  request. What remains unproven is the publicly trusted certificate, which needs
  a public host name — and the document says that plainly rather than implying it
  was tested.
- **A second stack started against the same volumes is out of contract** → the
  deploy document says the stack is started once, and the single writer covers
  the services of that one stack rather than two racing projects.
- **Start-time provisioning makes the first request of a cold container slow** →
  measured and recorded rather than estimated; the trade-off is decision 2's, and
  the alternative was a bypassable check.
- **Closing registration changes a public surface** → the switch defaults to on,
  so every existing test keeps its meaning, and the new behaviour has its own
  tests from both entry points.
- **A demo instance is a URL shortener open to the internet** → redirect targets
  are already validated against the open-redirect and SSRF classes, links belong
  to one account that cannot be created by strangers, and the dataset is wiped on
  a cadence. What this does not do is moderate what the demo account's visitors
  create between reloads; the deploy document names that as the reason the
  cadence is short.

## Migration Plan

Nothing to migrate: the change adds files and settings whose defaults reproduce
current behaviour. The deploy document is the forward path; the rollback for the
demo instance is the documented invocation's own teardown —
`docker compose --env-file .env.local -f docker-compose.prod.yml down` —
plus the host, since it holds no data that the seed does not regenerate, except
the JWT volume, whose loss only means re-issuing tokens. The flag matters for a
teardown exactly as it does for a start: without it compose resolves a different
project and interpolation source from the one that was brought up (decision 6).

## Open Questions

None. Where, exposure and secrets are decided in `handoff.md`'s user-decision log
and in decisions 4, 6 and 7; the scope question Gate 1 raised is decided in
decision 9.
