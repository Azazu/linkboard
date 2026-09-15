## ADDED Requirements

### Requirement: The error types are published and kept in step
The system SHALL publish, in the repository's reference documentation, a catalogue of every `type` value its API can produce: what the condition means, which operations raise it, and what a client can do about it. The catalogue SHALL be kept in step with the code — an error type the application can produce and the catalogue does not name is a defect the test suite reports, not something a reader discovers.

#### Scenario: Every type the code can produce is catalogued
- **WHEN** the error types the application can produce are enumerated from the code
- **THEN** each one appears in the catalogue with its meaning and at least one operation that raises it

#### Scenario: The catalogue describes what a client should do
- **WHEN** a reader looks up a refusal that a client can recover from — a validation failure, a rate-limit refusal, an expired credential
- **THEN** the catalogue names the recovery, and for a rate-limit refusal names the header that carries the delay
