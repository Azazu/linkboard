# Analytics

## Purpose
Reports over the recorded clicks — per link for its owner, global for administrators — computed by the database, served through a short-lived cache, and never touching the click write model. This is the read side of the click pipeline: what a dashboard charts and what an integrator polls.

## Requirements

### Requirement: Report parameters and period
Every per-link report under `GET /api/v1/links/{id}/stats/{report}` and every global report under `GET /api/v1/admin/stats/{report}` except `admin/stats/summary` SHALL accept the query parameters `from` and `to` (RFC 3339 timestamps; the period is half-open, `from` inclusive and `to` exclusive, and is evaluated in UTC) and `includeBots` (`true` or `false`, default `false`). Without `from` and `to` the period SHALL be the current UTC day and the 29 UTC days before it (`to` = the start of the next UTC day, `from` = `to` minus 30 days); when only `to` is given, `from` SHALL be 30 days before that `to`; when only `from` is given, `to` SHALL keep its default. `admin/stats/summary` reports all-time totals and today only: it accepts `includeBots` alone, declares no period parameters, and ignores `from` and `to`. `from` MUST be before `to` and the period MUST NOT exceed 366 days. `timeseries` reports SHALL accept `granularity` `hour` or `day` (default `day`); `hour` MUST NOT be combined with a period longer than 14 days. `countries`, `referrers` and `top-links` reports SHALL accept `limit`, an integer from 1 to 50 (default 10). A parameter that is malformed, out of range or inconsistent with another SHALL answer 422 `application/problem+json` with a `violations` array carrying one element per offending parameter whose `propertyPath` is the parameter name; unknown query parameters are ignored. Every report SHALL echo the effective `from`, `to` (except `admin/stats/summary`, which has none) and `includeBots` (and `granularity` or `limit` where they apply) and SHALL carry `generatedAt`, the RFC 3339 UTC time at which its numbers were computed.

#### Scenario: Defaults
- **WHEN** the owner requests `GET /api/v1/links/{id}/stats/summary` without query parameters at 2026-09-11T14:05:00Z
- **THEN** the response status is 200, `from` is `2026-08-13T00:00:00+00:00`, `to` is `2026-09-12T00:00:00+00:00`, `includeBots` is false and `generatedAt` is an RFC 3339 UTC timestamp

#### Scenario: One bound given
- **WHEN** the owner requests `.../stats/summary?to=2026-09-08T00:00:00Z` and then `.../stats/summary?from=2026-09-01T00:00:00Z` at 2026-09-11T14:05:00Z
- **THEN** the first response echoes `from` `2026-08-09T00:00:00+00:00` and `to` `2026-09-08T00:00:00+00:00`, the second `from` `2026-09-01T00:00:00+00:00` and `to` `2026-09-12T00:00:00+00:00`

#### Scenario: Explicit period
- **WHEN** the owner requests `.../stats/timeseries?from=2026-09-01T00:00:00Z&to=2026-09-08T00:00:00Z&granularity=hour&includeBots=true`
- **THEN** the response status is 200 and echoes `from` `2026-09-01T00:00:00+00:00`, `to` `2026-09-08T00:00:00+00:00`, `granularity` `hour` and `includeBots` true

#### Scenario: Malformed and out-of-range parameters
- **WHEN** the owner requests `.../stats/summary?from=yesterday`, `.../stats/timeseries?granularity=week`, `.../stats/countries?limit=0`, `.../stats/countries?limit=51` and `.../stats/summary?includeBots=maybe`
- **THEN** each response status is 422 `application/problem+json` with exactly one violation whose `propertyPath` is `from`, `granularity`, `limit`, `limit` and `includeBots` respectively

#### Scenario: Inconsistent parameters
- **WHEN** the owner requests `.../stats/summary?from=2026-09-08T00:00:00Z&to=2026-09-01T00:00:00Z`, `.../stats/summary?from=2025-01-01T00:00:00Z&to=2026-03-01T00:00:00Z` and `.../stats/timeseries?from=2026-08-01T00:00:00Z&to=2026-09-01T00:00:00Z&granularity=hour`
- **THEN** the responses are 422 with one violation on `from` (order), one violation on `to` (period longer than 366 days) and one violation on `granularity` (hourly buckets over more than 14 days)

### Requirement: Reports are computed by the database from the click records
Every number in a report SHALL be computed by SQL over the click records (aggregates and window functions) and returned as an immutable report; the analytics code MUST NOT load click or link entities, iterate click rows in application code or reference the object-relational mapping, so the read model shares nothing with the write model but the table. Untrusted input (query parameters) MUST reach SQL only as bound parameters.

#### Scenario: Architecture check
- **WHEN** the analytics source directory is scanned
- **THEN** no file references the click entity class, the link entity class or the ORM namespace, and a test fails if one does

#### Scenario: Numbers match the rows
- **WHEN** a link has 7 non-bot click rows in the period from 3 distinct `visitor_hash` values and the owner requests `.../stats/summary` for that period
- **THEN** `clicksInPeriod` is 7 and, with the rows being the link's only clicks, `totalClicks` is 7 and `uniqueVisitors` is 3

### Requirement: Authorization boundary of link reports
Every `GET /api/v1/links/{id}/stats/{report}` SHALL be served to the link's owner and to users with `ROLE_ADMIN`; any other authenticated user receives 403 `application/problem+json`, an anonymous caller 401, and an unknown or malformed `id` 404 (before any authorization check, so an unknown id looks the same to everyone). Reports of an inactive link SHALL still be served (deactivation keeps a link's analytics). There is no public report endpoint.

#### Scenario: Owner, admin, stranger, anonymous
- **WHEN** user A owns a link and A, an admin, user B and an anonymous client each request every one of the six reports of that link
- **THEN** A's and the admin's responses are 200, B's are 403 `application/problem+json` and the anonymous ones are 401

#### Scenario: Unknown link
- **WHEN** an authenticated user requests `GET /api/v1/links/not-a-uuid/stats/summary` and `GET /api/v1/links/{random uuid}/stats/summary`
- **THEN** each response status is 404 `application/problem+json`

#### Scenario: Inactive link keeps its reports
- **WHEN** the owner deactivates a link that has clicks and requests `.../stats/summary`
- **THEN** the response status is 200 and `totalClicks` counts the existing clicks

### Requirement: Summary report
`GET /api/v1/links/{id}/stats/summary` SHALL return `linkId`; `totalClicks`, `uniqueVisitors` (distinct `visitor_hash`), `firstClickAt` and `lastClickAt` over all of the link's clicks regardless of the period (null timestamps when the link has none); `clicksToday` (clicks of the current UTC day); `clicksInPeriod` (clicks with `from` ≤ `occurred_at` < `to`); `clicksInPreviousPeriod` (clicks in the period of the same length ending at `from`); and `deltaPercent`, the change from the previous period to the period as a percentage rounded to one decimal, null when the previous period has no clicks. Bots are excluded from every number unless `includeBots` is true.

#### Scenario: Numbers
- **WHEN** a link has 4 clicks (2 distinct visitors) between 2026-09-01 and 2026-09-08 UTC, 2 clicks (1 visitor) between 2026-08-25 and 2026-09-01, 1 click on 2026-07-01 and 1 click today, and the owner requests `.../stats/summary?from=2026-09-01T00:00:00Z&to=2026-09-08T00:00:00Z`
- **THEN** `clicksInPeriod` is 4, `clicksInPreviousPeriod` is 2, `deltaPercent` is 100.0, `clicksToday` is 1, `totalClicks` is 8, `uniqueVisitors` is the number of distinct hashes among the 8, `firstClickAt` is the 2026-07-01 click and `lastClickAt` is today's click

#### Scenario: No previous period
- **WHEN** the link has clicks in the period and none in the previous period
- **THEN** `deltaPercent` is null and `clicksInPreviousPeriod` is 0

#### Scenario: Link without clicks
- **WHEN** the owner requests the summary of a link that was never redirected through
- **THEN** the response status is 200 with every count 0, `firstClickAt`, `lastClickAt` and `deltaPercent` null

### Requirement: Timeseries report
`GET /api/v1/links/{id}/stats/timeseries` SHALL return `granularity` and `buckets`, one element per UTC bucket that intersects the period, in ascending order — the first and last bucket may be partial when `from` or `to` is not aligned to the granularity, every intersecting bucket present, buckets without clicks carrying zeros, and a period shorter than one bucket still yielding the bucket it lies in (two when it straddles a bucket boundary) — each with `bucket` (the RFC 3339 UTC start of the bucket), `clicks`, `uniqueVisitors` (distinct `visitor_hash` within the bucket) and `cumulativeClicks` (the running total of `clicks` from the first bucket). Only clicks inside the half-open period count, whatever their bucket: a partial first bucket holds the clicks from `from` onwards, a partial last one those before `to`. Consequently a 366-day period may span 367 day buckets and a 14-day period 337 hourly ones. Bucketing MUST be done in UTC regardless of the server's or the client's time zone: a click at `2026-03-29T00:30:00+02:00` belongs to the day bucket `2026-03-28`.

#### Scenario: Gaps are filled
- **WHEN** a link has 3 clicks on 2026-09-02 and 1 click on 2026-09-05 (UTC) and the owner requests `.../stats/timeseries?from=2026-09-01T00:00:00Z&to=2026-09-08T00:00:00Z`
- **THEN** `buckets` has exactly 7 elements starting at `2026-09-01T00:00:00+00:00`, their `clicks` are `[0, 3, 0, 0, 1, 0, 0]` and their `cumulativeClicks` are `[0, 3, 3, 3, 4, 4, 4]`

#### Scenario: Unaligned bounds give partial buckets
- **WHEN** a link has clicks at `2026-09-01T10:00:00Z`, `2026-09-01T15:00:00Z`, `2026-09-02T11:00:00Z` and `2026-09-02T13:00:00Z` and the owner requests `.../stats/timeseries?from=2026-09-01T12:00:00Z&to=2026-09-02T12:00:00Z`
- **THEN** `buckets` has exactly 2 elements, `2026-09-01T00:00:00+00:00` with 1 click and `2026-09-02T00:00:00+00:00` with 1 click (the 10:00 and 13:00 clicks are outside the period); `.../stats/timeseries?from=2026-09-01T10:30:00Z&to=2026-09-01T11:30:00Z` has exactly 1 bucket, `2026-09-01T00:00:00+00:00`, with 0 clicks; the global timeseries behaves the same

#### Scenario: UTC bucketing across a DST change
- **WHEN** a link has one click at `2026-03-29T00:30:00+02:00` and one at `2026-03-29T03:30:00+02:00` and the owner requests `.../stats/timeseries?from=2026-03-28T00:00:00Z&to=2026-03-30T00:00:00Z`
- **THEN** the `2026-03-28` bucket has 1 click and the `2026-03-29` bucket has 1 click

#### Scenario: Hourly buckets
- **WHEN** the owner requests `.../stats/timeseries?from=2026-09-01T00:00:00Z&to=2026-09-02T00:00:00Z&granularity=hour` for a link with 2 clicks at 09:15 UTC and 1 at 23:59 UTC on 2026-09-01
- **THEN** `buckets` has 24 elements, the `2026-09-01T09:00:00+00:00` bucket has 2 clicks, the `2026-09-01T23:00:00+00:00` bucket has 1 and every other bucket 0

### Requirement: Countries report
`GET /api/v1/links/{id}/stats/countries` SHALL return `total` (clicks in the period) and `items`, the `limit` countries with the most clicks in the period in descending order, each with `country` (ISO 3166-1 alpha-2, or null for clicks whose country is unknown — unknown is one group like any other), `clicks`, `share` (percentage of `total`, rounded to one decimal) and `rank` (1 for the most clicked; equal counts share a rank).

#### Scenario: Top countries with share and rank
- **WHEN** a link's period has 5 clicks from `DE`, 3 from `US`, 2 with unknown country and the owner requests `.../stats/countries?limit=2` for that period
- **THEN** `total` is 10 and `items` is `[{country: "DE", clicks: 5, share: 50.0, rank: 1}, {country: "US", clicks: 3, share: 30.0, rank: 2}]`

#### Scenario: Unknown country is a group
- **WHEN** the same owner requests `.../stats/countries` (default limit)
- **THEN** `items` has 3 elements and the third is `{country: null, clicks: 2, share: 20.0, rank: 3}`

### Requirement: Devices report
`GET /api/v1/links/{id}/stats/devices` SHALL return `total` and two breakdowns of the period's clicks — `byDeviceType` (grouped by `device_type`) and `byOs` (grouped by `os`) — each a list in descending order of `clicks` with `deviceType` or `os` (null when detection did not recognise it), `clicks` and `share` (percentage of `total`, rounded to one decimal).

#### Scenario: Two breakdowns
- **WHEN** a link's period has 6 smartphone clicks (4 iOS, 2 Android), 3 desktop clicks (Windows) and 1 click with unrecognised device and OS
- **THEN** `total` is 10, `byDeviceType` is `[{deviceType: "smartphone", clicks: 6, share: 60.0}, {deviceType: "desktop", clicks: 3, share: 30.0}, {deviceType: null, clicks: 1, share: 10.0}]` and `byOs` lists iOS 4 (40.0), Windows 3 (30.0), Android 2 (20.0), null 1 (10.0)

### Requirement: Referrers report
`GET /api/v1/links/{id}/stats/referrers` SHALL return `total` and `items`, the `limit` referrer hosts with the most clicks in the period in descending order, each with `host`, `clicks`, `share` and `rank` as in the countries report; clicks without a referrer host form one group whose `host` is the string `direct`.

#### Scenario: Direct traffic is a group
- **WHEN** a link's period has 4 clicks from `news.example.org`, 1 from `t.co` and 5 without a referrer
- **THEN** `items` is `[{host: "direct", clicks: 5, share: 50.0, rank: 1}, {host: "news.example.org", clicks: 4, share: 40.0, rank: 2}, {host: "t.co", clicks: 1, share: 10.0, rank: 3}]`

### Requirement: Variants report
`GET /api/v1/links/{id}/stats/variants` SHALL return `total` — the period's clicks that were resolved by an A/B variant — and `items`, one per variant name in descending order of `clicks`, each with `variant`, `clicks`, `uniqueVisitors` (distinct `visitor_hash` within the variant) and `share` (percentage of `total`, rounded to one decimal). Clicks resolved by a rule or the default target are not part of this report.

#### Scenario: Variants with uniques
- **WHEN** a link's period has 6 clicks with variant `A` from 4 distinct visitors, 4 clicks with variant `B` from 4 distinct visitors and 5 clicks resolved by a device rule
- **THEN** `total` is 10 and `items` is `[{variant: "A", clicks: 6, uniqueVisitors: 4, share: 60.0}, {variant: "B", clicks: 4, uniqueVisitors: 4, share: 40.0}]`

#### Scenario: Link without variants
- **WHEN** the owner requests `.../stats/variants` for a link whose clicks were all resolved by rules or the default target
- **THEN** the response status is 200, `total` is 0 and `items` is empty

### Requirement: Bots are excluded unless asked for
Every report SHALL exclude click records with `is_bot` true from every number unless `includeBots=true` is given, in which case bot clicks count like any other.

#### Scenario: Toggle
- **WHEN** a link's period has 8 human clicks and 2 bot clicks and the owner requests `.../stats/summary` with and without `includeBots=true`
- **THEN** `clicksInPeriod` is 10 with the flag and 8 without, and `.../stats/timeseries` buckets sum to 10 and 8 respectively

### Requirement: Report cache
Reports SHALL be served through a cache with a time-to-live of 300 seconds whose entries are keyed by the report, the link (for link reports) and every effective parameter, so two requests with the same effective parameters within the time-to-live SHALL return the same numbers and the same `generatedAt` and the second SHALL NOT query the click records, while requests differing in any parameter are computed separately. Cache entries of a link SHALL be invalidated when the link is updated (any successful `PATCH`, including deactivation and reactivation) or deleted; global entries SHALL be invalidated when a link is deleted (capability `links`). The cache is an optimisation, never a dependency: when the cache store is unavailable a report SHALL still be computed from the database and answered 200 with a record at level `warning` naming the failure class, and a failure to invalidate MUST NOT fail the update or deletion (a `warning` record names the link id). Clicks recorded between two computations become visible when the entry expires — a staleness of at most 300 seconds is accepted and `generatedAt` states it.

#### Scenario: Second request is served from the cache
- **WHEN** the owner requests `.../stats/summary` twice within 300 seconds with the same parameters
- **THEN** both responses are 200 with identical bodies including `generatedAt`, and no query over the click records is executed for the second request

#### Scenario: Different parameters are different entries
- **WHEN** the owner requests `.../stats/summary` and then `.../stats/summary?includeBots=true`
- **THEN** the second request is computed (a query over the click records is executed) and its numbers include bots

#### Scenario: Invalidated on update
- **WHEN** the owner requests `.../stats/summary`, a click is recorded, the owner patches the link with `{"isActive":false}` and requests the summary again
- **THEN** the second summary is computed anew and its counts include the new click; without the patch the second summary would have been the cached one

#### Scenario: Cache store unavailable
- **WHEN** the cache store refuses connections and the owner requests `.../stats/summary`
- **THEN** the response status is 200 with numbers computed from the database and a `warning` record names the failure class

### Requirement: Global statistics for administrators
The system SHALL serve, for callers with `ROLE_ADMIN` only (403 `application/problem+json` for any other authenticated user, 401 anonymous): `GET /api/v1/admin/stats/summary` with `totalUsers`, `totalLinks`, `activeLinks`, `totalClicks` and `clicksToday` (current UTC day) — the click numbers honouring `includeBots`; it has no period (`from`/`to` are ignored and not echoed); `GET /api/v1/admin/stats/timeseries` with the same parameters and rules as the per-link timeseries, over the clicks of every link, its buckets carrying `bucket`, `clicks` and `cumulativeClicks` (no unique visitors: the global report is bounded by no link, and the distinct count over every link's rows is what would put it over the performance target); and `GET /api/v1/admin/stats/top-links` with `total` (clicks in the period over every link) and `items`, the `limit` links with the most clicks in the period in descending order, each with `linkId`, `slug`, `ownerId`, `clicks`, `uniqueVisitors` (distinct `visitor_hash` among the link's clicks in the period) and `rank`. Global reports use the same cache, time-to-live and degradation as link reports and are invalidated when a link is deleted.

#### Scenario: Admin summary and top links
- **WHEN** 3 users exist, user A owns 2 links (one inactive) with 5 and 3 clicks in the period and user B owns 1 link with 4 clicks, and an admin requests `/api/v1/admin/stats/summary` and `/api/v1/admin/stats/top-links?limit=2` for that period
- **THEN** the summary has `totalUsers` 3, `totalLinks` 3, `activeLinks` 2, `totalClicks` 12 (and answers the same with `?from=yesterday`, which it ignores), and the top links are A's 5-click link (rank 1, `uniqueVisitors` 1 — all five from one visitor) and B's 4-click link (rank 2, `uniqueVisitors` 4), each with its `slug`, `ownerId` and `clicks`

#### Scenario: Global timeseries
- **WHEN** clicks of several links fall on 2026-09-02 and 2026-09-05 and an admin requests `/api/v1/admin/stats/timeseries?from=2026-09-01T00:00:00Z&to=2026-09-08T00:00:00Z`
- **THEN** `buckets` has 7 elements — each exactly `bucket`, `clicks`, `cumulativeClicks` — whose `clicks` sum to the total of every link's clicks in the period, with zeros on the days without clicks

#### Scenario: Not an admin
- **WHEN** a regular user requests each of the three global reports, and an anonymous client requests one
- **THEN** the user's responses are 403 `application/problem+json` and the anonymous one is 401
