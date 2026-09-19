# Delta — demo-data

## MODIFIED Requirements

### Requirement: Demo dataset
The console command `app:demo:seed` SHALL create, in one transaction, two accounts — a regular user and an administrator, with fixed demo e-mail addresses and passwords generated at random for the run, except that the regular user's password SHALL be the one the instance supplies through a setting made for that purpose when it supplies one, so that a published demo credential survives a re-seed. The administrator's password SHALL always be generated, never the supplied one: the administrator's address is a constant of this repository, so a shared password would mean that publishing the demo credential publishes administrator access. Neither password is ever taken from the code or the repository — ten links owned by the regular user, every one carrying a valid routing-rules document (device, country or language rules, A/B variants, or a combination) and some with a click limit, an expiry or a UTM set, and synthetic click records for those links spread over the last `--days` days (default 60), `--clicks` records in total (default 50 000), with realistic distributions: several countries and an unknown share, device types with matching operating systems and browsers, referrer hosts with a direct share, variants only on links that have them with `resolved_by` set accordingly, a small bot share, and visitor hashes drawn from a pool so visitors repeat. The click records SHALL have the same shape as records written by the click handler (no raw IP or user agent anywhere), every link's `clickCount` SHALL equal the number of its seeded records, and the command SHALL print the two e-mail addresses, the passwords in force and the counts once, on the console only.

#### Scenario: Default seed
- **WHEN** `app:demo:seed` runs on an instance without demo accounts
- **THEN** it exits 0; two accounts exist, one with `ROLE_ADMIN`; ten links with routing documents belong to the regular user; 50 000 click records spread over 60 days exist for them; each link's `clickCount` equals its record count; the output names both e-mail addresses and a password for each, and the regular user can obtain a token with the printed password

#### Scenario: Small seed for tests
- **WHEN** `app:demo:seed --clicks=500 --days=5` runs
- **THEN** exactly 500 click records exist, every `occurred_at` lies within the last 5 days, at least one record has `is_bot` true, at least one has a null country, at least one has a non-null `variant` with `resolved_by` `variant`, at least one has a null `referer_host`, and the number of distinct `visitor_hash` values is smaller than 500

#### Scenario: A supplied password survives a re-seed
- **WHEN** the instance supplies the demo password through its setting, the command seeds, and the command is then run again with `--reset`
- **THEN** both runs set that same password for the regular user, and the credential that worked before the reset still obtains a token after it

#### Scenario: The administrator does not share the published credential
- **WHEN** the instance supplies the demo password and the command seeds
- **THEN** the administrator account's password is not that value, so a page publishing the demo credential does not publish administrator access

### Requirement: Guards and re-runs
`app:demo:seed` SHALL refuse to run in the `prod` environment (exit code 1, nothing written) **unless the instance explicitly declares itself a demo instance through a setting made for that purpose**, which is unset everywhere by default; the refusal is otherwise exactly as before, and no option of the command can lift it. When the demo accounts already exist it SHALL refuse (exit code 1, nothing written) unless `--reset` is given, in which case it SHALL first delete the two demo accounts — their links and click records follow through the deletion of a link (capability `links`), their counters and cached reports are dropped — and then seed anew. Deletion and seeding are one transaction: a failure at any point — after the former accounts were deleted, after replacement accounts, links or click records were written — SHALL leave no partial data: the exit code is 1 with the reason, a former dataset is intact (the same account ids, the same passwords still valid, the same links and click records) and no replacement account, link or record exists.

Two runs SHALL NOT overlap. The command SHALL take a lock for its whole run and, when another run already holds it, SHALL exit without writing anything and say so, rather than waiting — because on a scheduled instance a waiting run would still be there when the next one starts. A scheduled reload that outlives its interval therefore delays the next reload rather than deleting the accounts a running one is recreating.

#### Scenario: Refuses in prod
- **WHEN** the command runs with the `prod` environment and the demo-instance setting is unset
- **THEN** the exit code is 1, the output says why, and no account, link or click record was written

#### Scenario: Refuses in prod however it is invoked
- **WHEN** the command runs with the `prod` environment, the demo-instance setting unset, and every option the command accepts — including `--reset`
- **THEN** the exit code is 1 and nothing is written, because the guard is not an option of the command

#### Scenario: A declared demo instance may seed itself in prod
- **WHEN** the command runs with the `prod` environment on an instance whose demo-instance setting is on
- **THEN** it seeds as it does outside `prod`, and the output says which instance setting allowed it

#### Scenario: A second run while the first is working is refused, not queued
- **WHEN** a run is in progress and a second run of the command starts
- **THEN** the second exits non-zero at once without writing anything, saying a run is in progress, and the first completes as if it had been alone

#### Scenario: Refuses to seed twice
- **WHEN** the command runs a second time without `--reset`
- **THEN** the exit code is 1 and the dataset of the first run is unchanged

#### Scenario: Failure during a reset keeps the former dataset
- **WHEN** a dataset exists and a `--reset` run fails while it writes the replacement click records (after the former accounts were deleted and the replacement accounts and links were written)
- **THEN** the exit code is 1, the output names the failure, the former accounts exist with their ids and the former password still obtains a token, the former links and their click records are unchanged, and no replacement link exists

#### Scenario: Failure during a fresh seed writes nothing
- **WHEN** no dataset exists and a run fails while writing the click records
- **THEN** the exit code is 1 and no account, link or click record exists

#### Scenario: Reset
- **WHEN** the command runs a second time with `--reset --clicks=300 --days=3`
- **THEN** the exit code is 0, exactly two demo accounts and ten demo links exist, exactly 300 click records exist for them, and the links of the first run are gone
