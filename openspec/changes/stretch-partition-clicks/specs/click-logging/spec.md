# Delta — click-logging

## MODIFIED Requirements

### Requirement: Click messages are handled idempotently
Every `ClickRecorded` message SHALL carry a unique `click_id` and a fixed
`occurred_at`, and the pair SHALL be the click record's primary key. Handling a
message SHALL insert the record and increment the link's `clickCount` in one
transaction. A message delivered more than once (a redelivery after a lost
acknowledgement, a manual retry from the failed transport) SHALL be acknowledged
without a second record and without a second increment.

#### Scenario: Redelivery
- **WHEN** the same `ClickRecorded` message is handled twice
- **THEN** exactly one click record with that `click_id` exists, the link's `clickCount` grew by exactly one, and the second handling completed without an error

#### Scenario: Redelivery of a message whose month is not the current one
- **WHEN** a `ClickRecorded` message whose `occurred_at` falls in an earlier month is handled twice
- **THEN** exactly one record exists, in the partition of that month, and the second handling completed without an error

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

### Requirement: The partition horizon is maintained ahead of the clicks
A click whose month has no partition cannot be stored, so the schema SHALL
always carry partitions for the current month and for a configured number of
months ahead. A maintenance command SHALL create the missing partitions, SHALL
be safe to run repeatedly, and SHALL report what it created.

#### Scenario: The command creates what is missing
- **WHEN** the maintenance command runs with a horizon of three months and only the current month exists
- **THEN** the three following months exist afterwards, and running it again creates nothing and reports nothing created

#### Scenario: A click with no partition fails loudly rather than silently
- **WHEN** a click whose month has no partition is handled
- **THEN** the insert fails, the link's `clickCount` is unchanged, and the failure propagates to the transport's retry policy rather than being swallowed as a duplicate or a missing link

### Requirement: Retention drops whole months, and only when asked
Click records older than a configured retention window SHALL be removable by
dropping whole partitions, through the same maintenance command. Nothing in the
application SHALL drop click data on its own: a partition SHALL be dropped only
by an explicit run of that command. The command SHALL name every partition it
drops and SHALL refuse to drop one that is not entirely outside the window.

#### Scenario: A month entirely outside the window is dropped
- **WHEN** the retention window is 13 months and the command runs with data in a partition whose last day is older than that
- **THEN** that partition is gone, its rows with it, the command names it, and no partition inside the window is touched

#### Scenario: A month that straddles the boundary is kept
- **WHEN** a partition holds days both inside and outside the window
- **THEN** the command keeps it and says so

#### Scenario: Nothing is dropped without the command
- **WHEN** the application runs, records clicks and serves reports without the command being run
- **THEN** every partition that ever held a click still exists
