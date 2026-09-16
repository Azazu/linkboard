# ADR-003: the A/B bucket is a hash of link, address and agent — no cookie, no state

**Date:** 2026-09-15
**Status:** accepted
**Related:** decided in the archived change `add-routing-rules`, recorded here by `harden-quality-and-docs`

## Context

A link may carry A/B variants with weights summing to 100, and the same visitor
should keep landing on the same variant — otherwise the split measures nothing.
The redirect that has to make this choice is the hot path: one lookup, one
dispatch, 302 (FR-RED-2), and it is answered for anonymous visitors who have no
account and no session.

The obvious mechanisms carry costs the redirect cannot pay: a cookie needs a
round trip to be set and a consent story to be told, and a stored assignment
needs a read and a write per visitor on the path whose whole point is not to
wait for writes.

## Decision

The variant is a pure function of what the request already carries:

```
bucket  = crc32(link_id ‖ NUL ‖ client_ip ‖ NUL ‖ user_agent) mod 100
variant = first variant whose cumulative weight exceeds the bucket
```

in document order (`src/Redirect/Rules/VariantPicker.php`, FR-RUL-5). No
cookie, no session, no row: the same visitor on the same link gets the same
variant for as long as their address and agent hold.

CRC32 is chosen as a *distribution* function, not a security primitive. The
identity stored with a click stays the salted SHA-256 `visitor_hash`, which is
what the privacy requirements are about; nothing about the bucket is secret and
nothing depends on it being unguessable.

**What this does not guarantee.** A visitor behind a changing address — mobile
roaming, a carrier NAT pool — can move between variants, and two visitors behind
one NAT with the same browser share a bucket. The split is stable per
(address, agent), not per person. For a link's own experiment that is the
accepted approximation; for anything that must be per person, this is the wrong
mechanism and the ADR to supersede.

## Alternatives considered

- **A cookie holding the assignment.** Rejected: it needs a response header on
  a redirect, a consent conversation in some jurisdictions, and it still fails
  for the first request, which is the one being measured.
- **A stored assignment per visitor.** Rejected: a read and a write on the hot
  path, a table that grows per visitor per link, and a cleanup policy — for a
  choice that a hash makes in microseconds.
- **A random pick per request.** Rejected: it is not an A/B test; the same
  visitor would see different targets on a reload and the weights would describe
  traffic rather than audience.
- **SHA-256 instead of CRC32** for the bucket. Rejected as cost without benefit:
  the output is reduced to one of a hundred buckets and nothing depends on the
  function being one-way. Where a one-way function is needed — the stored
  visitor identity — SHA-256 with a salt is what is used.

## Consequences

- The redirect stays stateless: no cookie to set, nothing to read, nothing to
  clean up.
- The split is reproducible in a test by construction — the picker is a pure
  function, so its distribution is asserted directly rather than sampled.
- Visitors on changing addresses are counted where they land, and the
  documentation says so rather than implying per-person stability.

## Supersedes

No ADR clause.
