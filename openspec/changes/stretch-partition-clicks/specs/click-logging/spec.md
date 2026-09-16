# Delta — click-logging

## MODIFIED Requirements

### Requirement: Click messages are handled idempotently
Every `ClickRecorded` message SHALL carry a unique `click_id` and a fixed
`occurred_at`, and the pair SHALL be the click record's primary key. Handling a
message SHALL insert the record and increment the link's `clickCount` in one
transaction. A message delivered more than once (a redelivery after a lost
acknowledgement, a manual retry from the failed transport) SHALL be acknowledged
without a second record and without a second increment — including after the
month of the original record has been dropped by retention, where the message is
acknowledged as too old (see "Clicks older than the retention window are
discarded") rather than re-recorded.

#### Scenario: Redelivery
- **WHEN** the same `ClickRecorded` message is handled twice
- **THEN** exactly one click record with that `click_id` exists, the link's `clickCount` grew by exactly one, and the second handling completed without an error

#### Scenario: Redelivery of a message whose month is not the current one
- **WHEN** a `ClickRecorded` message whose `occurred_at` falls in an earlier month is handled twice
- **THEN** exactly one record exists, in the partition of that month, and the second handling completed without an error

#### Scenario: Redelivery after the record's month was dropped
- **WHEN** a message is handled, its month is later dropped by retention, and the message is then redelivered
- **THEN** the second handling is acknowledged without an error, no record is written, and the link's `clickCount` is unchanged by it

#### Scenario: Record and increment are one transaction
- **WHEN** the record insert fails for a reason other than a duplicate or a missing link
- **THEN** the link's `clickCount` is unchanged and the failure propagates to the transport's retry policy

## ADDED Requirements

### Requirement: Click records are stored in monthly partitions
Click records SHALL be stored in a table partitioned by calendar month on
`occurred_at` in UTC, one partition per month. The partitioning SHALL be
invisible to every reader: reports, the deletion cascade and the demo dataset
SHALL behave exactly as before.

#### Scenario: A click is stored in the partition of its month
- **WHEN** a click with `occurred_at` in 2026-08 and a click with `occurred_at` in 2026-09 are recorded
- **THEN** each row is in the partition of its own month, and a query over the table returns both

#### Scenario: Readers are unchanged
- **WHEN** any report is requested over a period that spans two months
- **THEN** the numbers are the same as before the table was partitioned

#### Scenario: Deleting a link still deletes its clicks
- **WHEN** a link with clicks in two different months is deleted
- **THEN** no click record of that link remains in either month

### Requirement: Every month a click may legitimately fall in has a partition
A click whose month has no partition cannot be stored, so the schema SHALL carry
a partition for every month of the retention window and for a configured number
of months ahead — from the first month of the window to `now + horizon` — and
additionally for every month in which a record already exists. A maintenance
command SHALL create the missing partitions, SHALL be safe to run repeatedly,
and SHALL report what it created.

The range is what makes the rest of the system work unchanged: the demo dataset
writes clicks over the preceding days, and test fixtures write clicks months
into the past. Any click inside the retention window SHALL be storable on a
freshly migrated database without further preparation.

#### Scenario: A freshly migrated database accepts historical clicks
- **WHEN** the migrations are applied to an empty database and a click dated six months ago, within the retention window, is recorded
- **THEN** the record is written to that month's partition

#### Scenario: The demo dataset and the test fixtures need no preparation
- **WHEN** the demo dataset is seeded on a freshly migrated database
- **THEN** every click it writes is stored, whatever day of the preceding period it falls on

#### Scenario: The command creates what is missing
- **WHEN** the maintenance command runs with a horizon of three months and only the current month exists
- **THEN** every month from the start of the retention window to three months ahead exists afterwards, and running it again creates nothing and reports nothing created

#### Scenario: A click inside the window with no partition fails loudly rather than silently
- **WHEN** a click whose `occurred_at` is inside the retention window is handled and its month has no partition
- **THEN** the insert fails, the link's `clickCount` is unchanged, and the failure propagates to the transport's retry policy rather than being swallowed as a duplicate or a missing link

### Requirement: Clicks older than the retention window are discarded
A message whose `occurred_at` is older than the retention window SHALL be
acknowledged without a record and without an increment, and the discard SHALL be
logged with the link id and the click id. This covers the three ways such a
message occurs: a redelivery after its month was dropped, a first delivery
delayed past the window, and a manual retry from the failed transport long after
the fact. A message is never parked for being too old, and a dropped month is
never recreated to absorb one.

#### Scenario: A first delivery that arrives after its month expired
- **WHEN** a message whose `occurred_at` is older than the retention window is handled for the first time
- **THEN** it is acknowledged, no record is written, the link's `clickCount` is unchanged, and the discard is logged

#### Scenario: Too old is not the same as no horizon
- **WHEN** one message is older than the window and another is inside the window but in a month with no partition
- **THEN** the first is acknowledged and discarded, and the second fails and is retried

### Requirement: Retention drops whole months, and only when asked
Click records older than a configured retention window SHALL be removable by
dropping whole partitions, through the same maintenance command. Nothing in the
application SHALL drop click data on its own: a partition SHALL be dropped only
by an explicit run of that command. The command SHALL name every partition it
drops and SHALL refuse to drop one that is not entirely outside the window.

The retention window and the horizon are configuration, and the command SHALL
validate both **before issuing any statement that changes the schema**: each is a
whole number of months of at least one, and anything else — zero, negative,
empty, or not a number — SHALL make the command fail with a message naming the
setting, having created and dropped nothing.

#### Scenario: A window that is not a positive whole number changes nothing
- **WHEN** the command is run with a retention window of `0`, of `-1`, of an empty value or of a value that is not a number
- **THEN** the command fails naming that setting, and every partition and every row is exactly as it was

#### Scenario: A horizon that is not a positive whole number changes nothing
- **WHEN** the command is run with a horizon of `0`, of `-1`, of an empty value or of a value that is not a number
- **THEN** the command fails naming that setting, and every partition and every row is exactly as it was

#### Scenario: A month entirely outside the window is dropped
- **WHEN** the retention window is 13 months and the command runs with data in a partition whose last day is older than that
- **THEN** that partition is gone, its rows with it, the command names it, and no partition inside the window is touched

#### Scenario: A month that straddles the boundary is kept
- **WHEN** a partition holds days both inside and outside the window
- **THEN** the command keeps it and says so

#### Scenario: Nothing is dropped without the command
- **WHEN** the application runs, records clicks and serves reports without the command being run
- **THEN** every partition that ever held a click still exists
