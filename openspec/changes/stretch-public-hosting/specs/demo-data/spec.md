# Delta — demo-data

## MODIFIED Requirements

### Requirement: Guards and re-runs
`app:demo:seed` SHALL refuse to run in the `prod` environment (exit code 1, nothing written) **unless the instance explicitly declares itself a demo instance through a setting made for that purpose**, which is unset everywhere by default; the refusal is otherwise exactly as before, and no flag of the command can lift it. When the demo accounts already exist it SHALL refuse (exit code 1, nothing written) unless `--reset` is given, in which case it SHALL first delete the two demo accounts — their links and click records follow through the deletion of a link (capability `links`), their counters and cached reports are dropped — and then seed anew. Deletion and seeding are one transaction: a failure at any point — after the former accounts were deleted, after replacement accounts, links or click records were written — SHALL leave no partial data: the exit code is 1 with the reason, a former dataset is intact (the same account ids, the same passwords still valid, the same links and click records) and no replacement account, link or record exists.

#### Scenario: Refuses in prod
- **WHEN** the command runs with the `prod` environment and the demo-instance setting is unset
- **THEN** the exit code is 1, the output says why, and no account, link or click record was written

#### Scenario: Refuses in prod however it is invoked
- **WHEN** the command runs with the `prod` environment, the demo-instance setting unset, and every option the command accepts — including `--reset`
- **THEN** the exit code is 1 and nothing is written, because the guard is not an option of the command

#### Scenario: A declared demo instance may seed itself in prod
- **WHEN** the command runs with the `prod` environment on an instance whose demo-instance setting is on
- **THEN** it seeds as it does outside `prod`, and the output says which instance setting allowed it

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
