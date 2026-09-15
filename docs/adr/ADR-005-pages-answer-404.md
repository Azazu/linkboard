# ADR-005: the pages answer 404 where the API answers 403

**Date:** 2026-09-15
**Status:** accepted
**Related:** decided in the archived change `add-web-ui` (Gate 1, round 1, finding 4), recorded here by `harden-quality-and-docs`

## Context

One authorization rule governs a link: its owner, or an administrator, may see
it. Two surfaces enforce it — the JSON API and the server-rendered pages — and
they have different readers.

The API's contract was fixed first and is asserted by its own capability specs:
a stranger asking for a link receives **403**, which tells an integrator plainly
that their credential is valid and insufficient. That is the right answer for a
program: it distinguishes "you are not allowed" from "it is not there", which a
client needs in order to decide whether to re-authenticate, escalate or give up.

A browser is a different reader. `GET /links/{id}` with someone else's
identifier answering 403 confirms that the identifier exists — an enumeration
oracle that costs nothing to exploit and tells an attacker which links are real.

## Decision

The pages answer **404** for a link the signed-in user may not see, exactly as
they answer for an identifier no link has. The API keeps **403**.

The decision is asked of one mechanism in both places: `LinkVoter` votes on the
`LinkResource`, and only the *rendering* of a denial differs —
`LinkPages::findGranted()` turns a denied vote into a `NotFoundHttpException`.
Nothing about who may do what is restated.

**What this does not guarantee.** A 404 is not secrecy: timing, rate-limit
headers or a slug that redirects publicly can still reveal that a link exists.
This removes the cheapest oracle, not every one. And the asymmetry is a real
cost — a reader comparing the two surfaces sees the same request answered two
ways, which is why it is written down here rather than left to be discovered.

## Alternatives considered

- **403 on the pages too**, for consistency with the API. Rejected: consistency
  between surfaces is worth less than not confirming the existence of another
  owner's resource to a browser.
- **404 on the API too.** Rejected: it would change a published contract that
  the `links` and `qr-codes` capabilities specify and their suites assert, and
  it would take from integrators the distinction they actually use.
- **A softer page — "you do not have access" without a status change.**
  Rejected: it is a 403 wearing a different shirt; the oracle is the same.

## Consequences

- An enumerating browser learns nothing from the status: another owner's link
  and a nonexistent one answer identically, which the web suite asserts with
  both cases side by side.
- The two surfaces answer the same question differently, and every place that
  matters — the capability specs, the API documentation and this record — says
  so.
- New link pages inherit the behaviour by calling `findGranted()`; a page that
  forgets is caught by the access test that plants a stranger's request.

## Supersedes

No ADR clause.
