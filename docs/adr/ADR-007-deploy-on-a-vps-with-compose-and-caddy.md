# ADR-007 — Deploy on a VPS with compose and Caddy

**Status:** accepted · **Date:** 2026-09-19 · **Change:** `stretch-public-hosting`

## Context

Roadmap row 16 asks for a public demo instance with HTTPS. Everything built
before it assumes a laptop: one image used with the working tree mounted over
it, one compose stack bound to loopback reading committed defaults from `.env`,
no TLS, no path for a real secret, no supervision of the worker and no schedule
for the maintenance the service already needs.

The user's decision of 2026-09-17 bounds the change: it ships a reviewed,
buildable deployment contour and its documentation; provisioning the host, the
domain and the certificate stays their manual step.

## Decision

A single VPS running `docker-compose.prod.yml` — Caddy, a one-shot init
service, the application, a Messenger worker, a scheduler, PostgreSQL and Redis
— with Caddy terminating TLS and renewing the certificate itself.

## Why, and what was refused

**A PaaS (Fly.io, Render, Railway).** Managed TLS and one command to deploy,
but PostgreSQL and Redis become vendor services and the compose stack stops
being the contour: what a reader can see in the repository shrinks to a
manifest, and the local stack no longer mirrors anything. For a portfolio
project whose point is that the infrastructure is legible, that is the wrong
trade.

**Kubernetes with cert-manager.** A Deployment, a Service, an Ingress, a
CronJob, a Secret and a chart — for one demo instance with one of everything.
The repository's own anti-overengineering rule asks what need an existing
component cannot cover, and there is none here. Refused, and recorded rather
than left implicit.

**nginx plus certbot**, reusing the proxy the development stack already has.
TLS has to be terminated and renewed by something; nginx needs certbot beside
it, a timer to renew and a hook to reload — three moving parts where Caddy
needs one, and three more things a reviewer has to check are actually wired.
The development stack keeps its nginx: it serves plain HTTP on loopback and has
no certificate to manage.

## Consequences

- The whole contour is one file a reviewer can read top to bottom, and it is
  not an override of the development file: Compose **appends** volume lists
  when files are merged, so an override would have kept `.:/app` and the
  deployment would have run with the working tree over the image it was built
  to carry.
- Every invocation names its interpolation source
  (`--env-file .env.local`), because Compose reads the shell, the project
  `.env` or that flag — never a service's `env_file:`. Without it the derived
  connection strings render from the repository's committed defaults.
- One authoritative credential per store, with every connection string derived
  from it, so the server and the application cannot hold different passwords.
- The instance is a single point of failure with no redundancy and no backup
  beyond what an operator adds. For a demo whose data is regenerated on a
  schedule that is the intended trade; `docs/how-to/deploy.md` says what a real
  operator would add and why it is not here.
