# Deploying a public instance

What this document covers is a **reviewed deployment configuration**, exercised
locally, not a running instance. Provisioning the host, pointing a domain at it
and letting Caddy obtain a real certificate are yours to do; every command
below was run in the form it is printed, except the three marked as needing a
public host name, which are marked because they were not.

The contour and the alternatives refused are
[ADR-007](../adr/ADR-007-deploy-on-a-vps-with-compose-and-caddy.md).

## What runs

`docker-compose.prod.yml` is self-contained — it is **not** an override of
`docker-compose.yml`, because Compose appends volume lists when files are
merged and the development stack's `.:/app` would have survived into the
deployment, mounting the working tree over the image it was built to carry.

| Service | What it is |
|---|---|
| `caddy` | TLS, HTTP→HTTPS, HSTS, and the two generated asset trees served directly |
| `init` | one shot: the signing keypair, the migrations, the compiled assets. The only writer of those volumes |
| `php` | PHP-FPM behind Caddy |
| `worker` | `messenger:consume async`, restarted automatically |
| `scheduler` | the demo reload and the partition maintenance, on their cadences |
| `postgres`, `redis` | the stores, reachable only on the compose network |

Only `caddy` publishes ports. Nothing else is reachable from outside the host,
and that is what makes the client address the proxy reports trustworthy.

## The host

A small VPS with Docker and the compose plugin, ports 80 and 443 reachable, and
a DNS record pointing at it. Nothing else is required.

## The settings

Every credential lives in `.env.local` on the host. It is gitignored, no value
of it is in this repository, and Compose reads it only when the invocation says
so:

```bash
docker compose --env-file .env.local -f docker-compose.prod.yml <command>
```

Use that form for **every** command against the stack, `exec` and `ps`
included. Without `--env-file` Compose falls back to the committed `.env` and
renders the derived connection strings with the repository's development
password — the instance would then refuse to boot, which is the second guard
rather than the first.

Generate the file on the host. These commands print nothing you have to copy by
hand, and no value of them belongs anywhere else:

```bash
umask 077
{
  echo "PUBLIC_HOST=links.example.com"
  echo "TLS_EMAIL=you@example.com"
  echo "APP_SECRET=$(openssl rand -hex 32)"
  echo "JWT_PASSPHRASE=$(openssl rand -hex 24)"
  echo "VISITOR_HASH_SALT=$(openssl rand -hex 24)"
  echo "DB_PASSWORD=$(openssl rand -hex 24)"
  echo "REDIS_PASSWORD=$(openssl rand -hex 24)"
  echo "DEMO_PASSWORD=$(openssl rand -hex 12)"
} > .env.local
```

`DATABASE_URL`, `REDIS_URL`, `LOCK_DSN` and `MESSENGER_TRANSPORT_DSN` are
**derived** from those two store credentials by the compose file. Do not set
them by hand: the point of deriving them is that the server and the application
cannot end up holding different passwords.

Every one of them is checked at boot. Unset, empty, or still equal to the value
committed in `.env` stops the process — the web application, the worker and the
scheduler alike — with a message naming the setting and never its value.

## Starting it

```bash
make image
docker compose --env-file .env.local -f docker-compose.prod.yml up -d
```

`make image` is the same command continuous integration runs, which is why
there is one of it rather than two that can drift.

The first start is slower than later ones: the image deliberately boots nothing
at build time, so the cache, the compiled assets and the signing keypair are
produced when the containers start. The init service does the shared work and
the others wait for it to finish — that ordering is what keeps three processes
from generating three different keypairs on an empty volume.

**Needs a public host name, and was not run here:** Caddy obtains the
certificate on that first start. Locally the stack is exercised with
`PUBLIC_HOST=<something>.localhost`, for which Caddy uses its own internal
authority and never contacts Let's Encrypt.

## The demo instance

With `DEMO_INSTANCE=true` (the default in this file) the scheduler seeds the
dataset and reloads it on its cadence, and the sign-in page states the demo
account and the password you generated — that page is the only place it is
stated, which is how it stays out of this repository. Registration is closed:
both `/register` and `POST /api/v1/auth/register` answer 404.

The administrator account's password is **not** the one you published. It is
generated per run, because the administrator's address is a constant of this
repository and a shared password would mean the sign-in page publishes
administrator access.

## Country routing needs a source you provision

The routing rules can match on country, and on a directly exposed instance the
only way to know one is a **GeoLite2 database**. Put
`GeoLite2-Country.mmdb` in `var/geoip/` on the host (or point `GEOIP_DIR` at
wherever you keep it) and the `geolite2` resolver picks it up. Obtaining it
needs a free MaxMind account and a licence key, which is why it is a step here
rather than something the stack does for you.

**Without it the instance still works**: the resolver logs a warning once per
process and every visit's country is unknown, so country rules fall through to
the default. That is the honest state, and it is why the production default is
`geolite2` rather than `header`.

**`header` cannot work on a directly exposed instance**, and this is worth
stating because it looks like it should. The application reads the country
header only for a request that came from a trusted proxy — and with a FastCGI
upstream the address it sees is the *visitor's*, not Caddy's, so no request
qualifies. Caddy also strips whatever a client sends under that header, so a
visitor cannot supply its own: measured, three requests carrying
`CF-IPCountry: DE` were recorded with no country at all.

**Behind an edge that resolves the country** — Cloudflare and the like — it
does work, and the configuration is: set `COUNTRY_RESOLVERS=header`, set
`TRUSTED_PROXIES` to that edge's ranges rather than the compose network, and
remove the `header_up -{$COUNTRY_HEADER}` line from the Caddyfile so the edge's
value reaches the application. **Not exercised here:** this deployment is the
directly exposed one.

The mount and the lookup *are* exercised. `tests/Fixture/build-country-mmdb.php`
builds a small database in MaxMind's own format — not the licensed GeoLite2
dataset, which needs an account — and with one mounted at `var/geoip/` the
stack was measured end to end: the file is readable inside the php container, a
redirect for a link whose rules target Germany answered
`302 https://example.de/` rather than its default, and the click was recorded
with country `DE`. The same request carrying a forged `CF-IPCountry: FR` still
went to `https://example.de/` — that link has a French rule, so a believed
header would have sent it to `https://example.fr/`.

## Looking after it

```bash
docker compose --env-file .env.local -f docker-compose.prod.yml logs -f init
docker compose --env-file .env.local -f docker-compose.prod.yml ps
```

A crash restarts the worker and the scheduler by itself. `docker kill` does
**not**: Docker records an API kill as a manual stop and `unless-stopped`
deliberately leaves it stopped — measured, and worth knowing before you use it
to test a restart.

Two volumes matter. `linkboard-prod_jwt` holds the signing keypair: losing it
invalidates every token in the wild but nothing else. `linkboard-prod_pgdata`
holds the database, which on a demo instance is regenerated by the next
scheduled reload.

## What a real operator would add, and why it is not here

- **Backups.** A demo's data is regenerated on a schedule; a service with real
  users needs `pg_dump` on a timer and somewhere off the host to put it.
- **Monitoring and alerting.** `/health` exists and the proxy uses it to decide
  the upstream is up, but nothing pages anyone.
- **A second instance, and a way to roll between them.** This stack stops the
  containers to replace them.
- **Separating migrations from the rollout.** Provisioning migrates on every
  start, which is right for one instance with reviewed, reversible migrations
  and wrong for a fleet, where it lets a half-rolled-out image meet a
  half-migrated schema.

## Rolling back

```bash
docker compose --env-file .env.local -f docker-compose.prod.yml down
```

The flag matters for a teardown exactly as it does for a start: without it
Compose resolves a different project than the one you brought up.
