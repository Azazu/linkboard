# Delta — deployment

## Purpose

What a production deployment of this service is, as distinct from the
development stack: the image it runs, the processes it runs, how it is reached
over HTTPS and what it must believe about the proxy in front of it, the secrets
it requires before it will start, the schedule it keeps, and what the public
demo instance in particular exposes.

## ADDED Requirements

### Requirement: The production image is self-contained
The production image SHALL carry the application inside it: dependencies installed without development packages, the asset map compiled, and the cache warmed at build time. It SHALL NOT depend on a mounted working tree, SHALL run as a non-root user that is fixed in the image rather than taken from the building machine, and SHALL be configured for production rather than development — in particular the opcode cache SHALL NOT re-stat sources on each request. Building the image SHALL NOT require any secret.

#### Scenario: The image runs without the source directory
- **WHEN** the production image is started with no bind mount of the repository
- **THEN** the application serves requests, because its code, dependencies and compiled assets are in the image

#### Scenario: Development packages are absent
- **WHEN** the production image is inspected
- **THEN** no development-only dependency is installed and no development-only PHP extension is enabled

#### Scenario: Building needs no secret
- **WHEN** the image is built on a machine with no secret available — as continuous integration builds it
- **THEN** the build succeeds

### Requirement: A deployment runs the web, the worker and the schedule
A deployment SHALL run three kinds of process from the same image: the web application, at least one Messenger worker consuming the `async` transport, and a scheduler that runs the periodic maintenance commands. The worker and the scheduler SHALL be restarted automatically when they exit. A deployment that runs the web application alone SHALL be treated as incomplete, because clicks would be recorded to the transport and never drained.

#### Scenario: A worker exit does not end click recording
- **WHEN** the worker process exits for any reason
- **THEN** it is started again automatically, and the messages queued meanwhile are consumed when it returns

#### Scenario: The partition horizon keeps moving
- **WHEN** the deployment has been running for longer than the partition horizon
- **THEN** the scheduled maintenance has created the partitions ahead of the current month, so a click recorded today has a partition to land in

### Requirement: The public instance is reached over HTTPS only
The deployment SHALL terminate TLS in front of the application, obtain and renew its certificate without manual steps, and answer a plain HTTP request with a permanent redirect to the HTTPS URL rather than with content. Responses SHALL carry HSTS. The proxy SHALL decide an upstream is healthy using the service's own liveness endpoint.

#### Scenario: HTTP is redirected, not served
- **WHEN** a client requests `http://<host>/<slug>`
- **THEN** the response is a permanent redirect to the same path on `https://<host>`, and the link's own redirect is not performed over plain HTTP

#### Scenario: A certificate is obtained without a manual step
- **WHEN** the deployment is started for the first time against a host name that resolves to it
- **THEN** a valid certificate is in place without an operator command, and renewal needs none either

### Requirement: The client IP survives the proxy
The deployment SHALL configure the trusted proxy so that the address the application treats as the client's is the one the proxy forwards, not the proxy's own. Two behaviours the service already guarantees depend on it: the per-IP redirect limit and the per-IP authentication limit count one bucket per client, and the country resolver reads a forwarded header. A deployment left with the development default SHALL be treated as misconfigured, because every visitor would share a single bucket.

#### Scenario: Two clients are two buckets
- **WHEN** two different client addresses reach the deployment through the proxy and each sends requests up to the per-IP redirect limit
- **THEN** neither is refused, because they are counted separately

#### Scenario: A forwarded header from outside the proxy is still not believed
- **WHEN** a client sends its own forwarded header through the proxy
- **THEN** the address counted is the one the trusted proxy set, not the one the client claimed

### Requirement: A missing or default secret stops the boot
The deployment SHALL require `APP_SECRET`, `JWT_PASSPHRASE`, `VISITOR_HASH_SALT` and the database and Redis credentials to be set from outside the repository. When one of them is unset, empty, or still equal to the value committed as a local development default, the application SHALL fail to boot with a message naming the setting — in every process of the deployment, the web application, the worker and the scheduler alike. No secret value SHALL appear in the repository, in an image layer, in a log line or in a message.

#### Scenario: An unset secret is fatal, and named
- **WHEN** the deployment starts with `APP_SECRET` unset
- **THEN** the process exits with a failure naming `APP_SECRET`, and no request is served

#### Scenario: A committed default is treated as unset
- **WHEN** the deployment starts with the visitor-hash salt still equal to the value committed for local development
- **THEN** the process fails to boot naming that setting, because a predictable salt is not a secret

#### Scenario: The failure does not disclose the value
- **WHEN** a secret is misconfigured and the boot fails
- **THEN** the message names the setting and does not contain the value it found

### Requirement: The public demo instance exposes one account and no registration
On the public demo instance self-registration SHALL be closed and the dataset SHALL be reloaded on a schedule, so that what a visitor finds is the demo dataset and not what previous visitors left. A visitor SHALL be able to sign in with the published demo account, create and edit links and read every report that account owns. The credentials of the demo account SHALL NOT be committed to the repository; the documentation SHALL name the command whose output prints them.

#### Scenario: A visitor cannot create an account
- **WHEN** a visitor requests the registration form or posts to the registration endpoint on the public instance
- **THEN** both answer as if the surface did not exist, and no account is created

#### Scenario: What a visitor leaves does not accumulate
- **WHEN** a visitor creates links under the demo account and the scheduled reload then runs
- **THEN** the instance is back to the seeded dataset

#### Scenario: The demo is usable, not read-only
- **WHEN** a visitor signs in with the published demo account
- **THEN** they can create a link, follow it, and see the click in the reports once the worker has drained it

### Requirement: The deployment path is exercised without deploying
Continuous integration SHALL build the production image on every run that builds the application, so that a change breaking the deployment fails in review rather than at deployment time. It SHALL NOT push the image anywhere, hold a deployment credential, or contact a host.

#### Scenario: A broken production image fails the build
- **WHEN** a change makes the production image unbuildable
- **THEN** continuous integration fails on that change

#### Scenario: Continuous integration reaches no host
- **WHEN** the deployment-related job runs
- **THEN** it publishes nothing and authenticates to nothing
