# Delta — analytics

## MODIFIED Requirements

### Requirement: Summary report
`GET /api/v1/links/{id}/stats/summary` SHALL return `linkId`; `totalClicks`, `uniqueVisitors` (distinct `visitor_hash`), `firstClickAt` and `lastClickAt` over all of the link's **retained** clicks regardless of the period (null timestamps when the link has none retained); `clicksToday` (clicks of the current UTC day); `clicksInPeriod` (clicks with `from` ≤ `occurred_at` < `to`); `clicksInPreviousPeriod` (clicks in the period of the same length ending at `from`); and `deltaPercent`, the change from the previous period to the period as a percentage rounded to one decimal, null when the previous period has no clicks. Bots are excluded from every number unless `includeBots` is true.

Retained means: within the configured retention window (`click-logging`,
"Retention drops whole months"). While no partition has been dropped these
figures are over every click the link ever received; once one has been, they are
over what survives, and a visitor whose only earlier clicks were dropped counts
as new when they return. A period that reaches outside the retained history is
answered from what remains rather than refused.

#### Scenario: Numbers
- **WHEN** a link has 4 clicks (2 distinct visitors) between 2026-09-01 and 2026-09-08 UTC, 2 clicks (1 visitor) between 2026-08-25 and 2026-09-01, 1 click on 2026-07-01 and 1 click today, and the owner requests `.../stats/summary?from=2026-09-01T00:00:00Z&to=2026-09-08T00:00:00Z`
- **THEN** `clicksInPeriod` is 4, `clicksInPreviousPeriod` is 2, `deltaPercent` is 100.0, `clicksToday` is 1, `totalClicks` is 8, `uniqueVisitors` is the number of distinct hashes among the 8, `firstClickAt` is the 2026-07-01 click and `lastClickAt` is today's click

#### Scenario: No previous period
- **WHEN** the link has clicks in the period and none in the previous period
- **THEN** `deltaPercent` is null and `clicksInPreviousPeriod` is 0

#### Scenario: Link without clicks
- **WHEN** the owner requests the summary of a link that was never redirected through
- **THEN** the response status is 200 with every count 0, `firstClickAt`, `lastClickAt` and `deltaPercent` null

#### Scenario: After a month has been dropped by retention
- **WHEN** a link's oldest clicks were in a partition that retention has dropped, and the owner requests the summary
- **THEN** the response status is 200, `totalClicks` and `uniqueVisitors` count only the retained clicks, and `firstClickAt` is the oldest retained click rather than the link's first ever
