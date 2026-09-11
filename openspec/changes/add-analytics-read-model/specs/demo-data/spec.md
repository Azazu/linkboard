## Purpose

A console command that fills a local instance with a realistic demo dataset — accounts, links with routing rules and tens of thousands of synthetic clicks — so reviewers and screenshots start from a populated dashboard rather than an empty one.

## ADDED Requirements

### Requirement: Demo dataset
The console command `app:demo:seed` SHALL create, in one transaction, two accounts — a regular user and an administrator, with fixed demo e-mail addresses and passwords generated at random for the run (never a password from the code or the repository) — ten links owned by the regular user, every one carrying a valid routing-rules document (device, country or language rules, A/B variants, or a combination) and some with a click limit, an expiry or a UTM set, and synthetic click records for those links spread over the last `--days` days (default 60), `--clicks` records in total (default 50 000), with realistic distributions: several countries and an unknown share, device types with matching operating systems and browsers, referrer hosts with a direct share, variants only on links that have them with `resolved_by` set accordingly, a small bot share, and visitor hashes drawn from a pool so visitors repeat. The click records SHALL have the same shape as records written by the click handler (no raw IP or user agent anywhere), every link's `clickCount` SHALL equal the number of its seeded records, and the command SHALL print the two e-mail addresses, the generated passwords and the counts once, on the console only.

#### Scenario: Default seed
- **WHEN** `app:demo:seed` runs on an instance without demo accounts
- **THEN** it exits 0; two accounts exist, one with `ROLE_ADMIN`; ten links with routing documents belong to the regular user; 50 000 click records spread over 60 days exist for them; each link's `clickCount` equals its record count; the output names both e-mail addresses and a password for each, and the regular user can obtain a token with the printed password

#### Scenario: Small seed for tests
- **WHEN** `app:demo:seed --clicks=500 --days=5` runs
- **THEN** exactly 500 click records exist, every `occurred_at` lies within the last 5 days, at least one record has `is_bot` true, at least one has a null country, at least one has a non-null `variant` with `resolved_by` `variant`, at least one has a null `referer_host`, and the number of distinct `visitor_hash` values is smaller than 500

### Requirement: Guards and re-runs
`app:demo:seed` SHALL refuse to run in the `prod` environment (exit code 1, nothing written). When the demo accounts already exist it SHALL refuse (exit code 1, nothing written) unless `--reset` is given, in which case it SHALL first delete the two demo accounts — their links and click records follow through the deletion of a link (capability `links`), their counters and cached reports are dropped — and then seed anew. Deletion and seeding are one transaction: a failure at any point — after the former accounts were deleted, after replacement accounts, links or click records were written — SHALL leave no partial data: the exit code is 1 with the reason, a former dataset is intact (the same account ids, the same passwords still valid, the same links and click records) and no replacement account, link or record exists.

#### Scenario: Refuses in prod
- **WHEN** the command runs with the `prod` environment
- **THEN** the exit code is 1, the output says why, and no account, link or click record was written

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
