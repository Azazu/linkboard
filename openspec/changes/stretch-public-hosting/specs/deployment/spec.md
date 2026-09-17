# Delta — deployment

## Purpose

What a production deployment of this service is, as distinct from the
development stack: the image it runs, the processes it runs, how the compiled
web assets and the signing keys reach the processes that need them, how it is
reached over HTTPS and what it must believe about the proxy in front of it, the
settings it requires before it will start, the schedule it keeps, and what the
public demo instance in particular exposes.

## ADDED Requirements

### Requirement: The production image carries the application and boots nothing at build time
The production image SHALL contain the application's source and its dependencies installed without development packages, SHALL NOT require a mounted working tree, and SHALL run as a non-root user fixed in the image rather than taken from the building machine. It SHALL be configured for production rather than development — in particular the opcode cache SHALL NOT re-stat sources on each request.

Building the image SHALL NOT start the application: no build step may boot the kernel. This is not a convenience — the boot is where the deployment's own configuration check lives (see "A missing or default setting stops the boot"), so a build that booted would have to be given real settings or be exempted from the check, and both defeat it. Consequently the build SHALL require no secret of any kind, and the image SHALL contain no placeholder value standing in for one.

#### Scenario: The image runs without the source directory
- **WHEN** the production image is started with no bind mount of the repository
- **THEN** the application serves requests, because its code and dependencies are in the image

#### Scenario: Building needs no secret and bakes none
- **WHEN** the image is built in an environment where none of the required settings is present — as continuous integration builds it
- **THEN** the build succeeds, and no layer of the resulting image contains a value for any of them

#### Scenario: Development packages and settings are absent
- **WHEN** the production image is inspected
- **THEN** no development-only dependency is installed, no development-only extension is enabled, and the opcode cache does not validate timestamps

### Requirement: Start-time provisioning produces the assets and the signing keys
Because the build boots nothing, two artefacts the running service needs are produced when a container starts, after its settings have been accepted: the compiled web assets, and the JWT signing keypair.

The assets SHALL be compiled into a location the web server can read, and the keypair SHALL be generated only if absent, into storage that survives a container being replaced. Neither SHALL be written into an image layer, and the private key SHALL NOT enter the repository, an image, a log or any output. Provisioning SHALL be idempotent: starting a container repeatedly SHALL NOT replace an existing keypair, and SHALL NOT leave the assets missing or half-written.

#### Scenario: A clean host issues tokens
- **WHEN** the stack is started for the first time on a host with no keypair, and a seeded account posts its credentials to the token endpoint
- **THEN** a token is returned and an authenticated API request with it succeeds

#### Scenario: A restart does not invalidate issued tokens
- **WHEN** the containers are replaced and a token issued before the replacement is used
- **THEN** it is still accepted, because the keypair was kept rather than regenerated

#### Scenario: The private key is nowhere it should not be
- **WHEN** the image layers, the repository and the start-up output are searched for the private key
- **THEN** it is in none of them

### Requirement: The web server serves the compiled assets of the same release
The deployment SHALL give the web server access to the compiled assets, because the application process cannot serve static files itself. A page of the web UI SHALL therefore load its stylesheets and scripts over HTTPS with a success status, not a 404.

#### Scenario: A compiled asset is served through the proxy
- **WHEN** a client requests a compiled asset referenced by a page of the web UI
- **THEN** the response is 200 with that asset's content type, served by the web server rather than by the application

#### Scenario: The dashboard is usable, not unstyled
- **WHEN** a signed-in visitor opens the dashboard
- **THEN** every asset it references answers 200

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

### Requirement: A missing or default setting stops the boot
The deployment SHALL require, from outside the repository, every setting through which a credential or a secret actually reaches the application. That set SHALL be named exhaustively rather than by category, and SHALL be defined by what the application consumes, not by what looks like a password: the application secret, the JWT passphrase, the visitor-hash salt, the database connection string, and **each** of the Redis connection strings the application uses independently — the cache and counter connection, the lock connection, and the message transport — because a check that examined only one of them would leave the others carrying the committed values.

When one of those settings is unset, empty, or **still equal to the value committed in the repository as a local development default**, the application SHALL fail to boot with a message naming the setting — in every process of the deployment, the web application, the worker and the scheduler alike. The message SHALL NOT contain the value it found. No such value SHALL appear in the repository, in an image layer, in a log line or in a message.

#### Scenario: An unset setting is fatal, and named
- **WHEN** the deployment starts with the application secret unset
- **THEN** the process exits with a failure naming that setting, and no request is served

#### Scenario: A committed default is treated as unset
- **WHEN** the deployment starts with the visitor-hash salt still equal to the value committed for local development
- **THEN** the process fails to boot naming that setting, because a predictable salt is not a secret

#### Scenario: Every consumer is covered, one at a time
- **WHEN** the deployment is started once for each setting in the named set, with that one left at its committed default and all the others set
- **THEN** each of those starts fails naming the setting that was left — including each Redis connection string separately

#### Scenario: The failure does not disclose the value
- **WHEN** a setting is misconfigured and the boot fails
- **THEN** the message names the setting and does not contain the value it found

### Requirement: The public demo instance exposes one account and no registration
On the public demo instance self-registration SHALL be closed and the dataset SHALL be reloaded on a schedule, so that what a visitor finds is the demo dataset and not what previous visitors left. A visitor SHALL be able to sign in with the demo account, create and edit links and read every report that account owns.

The demo account's password SHALL survive the scheduled reload, so that a visitor who was given it can still sign in afterwards: it SHALL come from a setting of the instance rather than being generated afresh on each run. It SHALL NOT be committed to the repository; the instance SHALL publish it on its own sign-in page, which is the only place it is stated, and the repository SHALL merely say that it is stated there.

#### Scenario: A visitor cannot create an account
- **WHEN** a visitor requests the registration form or posts to the registration endpoint on the public instance
- **THEN** both answer as if the surface did not exist, and no account is created

#### Scenario: The published credentials still work after a reload
- **WHEN** a visitor reads the demo credentials from the sign-in page, the scheduled reload then runs, and the visitor signs in
- **THEN** the sign-in succeeds, and the dataset is the seeded one rather than what the previous visitor left

#### Scenario: The demo is usable, not read-only
- **WHEN** a visitor signs in with the demo account
- **THEN** they can create a link, follow it, and see the click in the reports once the worker has drained it

#### Scenario: An instance that is not a demo publishes nothing
- **WHEN** an instance that has not declared itself a demo serves its sign-in page
- **THEN** the page states no credentials

### Requirement: The deployment path is exercised without deploying
The production image SHALL be built by continuous integration on every run that builds the application, so that a change breaking the deployment fails in review rather than at deployment time. That job SHALL NOT push the image anywhere, hold a deployment credential, or contact a host. The command the job runs SHALL be the same one the deploy documentation gives, so that neither can drift from the other unnoticed.

#### Scenario: A broken production image fails the build
- **WHEN** the production stage is made unbuildable and the documented build command is run
- **THEN** it fails, and it is the same command continuous integration runs

#### Scenario: Continuous integration reaches no host
- **WHEN** the deployment-related job runs
- **THEN** it publishes nothing and authenticates to nothing
