## Purpose
Public resolution of a short link: the one anonymous, hot-path endpoint of the service. It turns a slug into a 302 to the destination with the link's UTM appended while enforcing the link's state, expiry and click limit, and it protects the service from floods and from failures of the click record.

## ADDED Requirements

### Requirement: Public redirect endpoint
`GET /{slug}` and `HEAD /{slug}` SHALL be served without authentication, outside `/api`, and MUST NOT start a session or set a cookie. The slug MUST be matched exactly and case-sensitively against `^[A-Za-z0-9_-]{3,32}$`; a path that is not a slug is not this endpoint. Paths of application routes are never redirect slugs because the reserved-slug list of the `links` capability blocks them on write.

#### Scenario: Anonymous visitor is redirected
- **WHEN** an anonymous client requests `GET /promo-1` and `promo-1` is an active, unexpired link without a click limit
- **THEN** the response status is 302 and the response carries no `Set-Cookie` header

#### Scenario: Slugs are case-sensitive
- **WHEN** only the link `Promo` exists and a client requests `GET /promo`
- **THEN** the response status is 404

#### Scenario: HEAD mirrors GET without recording
- **WHEN** a client sends `HEAD /promo-1` for an active link
- **THEN** the status and headers equal those of `GET /promo-1`, the body is empty, and no click is recorded

### Requirement: Response matrix
The endpoint SHALL answer 404 when no link has the slug or the link is inactive; 410 Gone when the link's `expiresAt` is in the past or its recorded clicks have reached `maxClicks`; otherwise 302 Found with `Location` set to the destination. 301 MUST never be used. Inactivity is checked before expiry and limit, so an inactive expired link is 404.

#### Scenario: Unknown slug
- **WHEN** a client requests `GET /nothing-here` and no link has that slug
- **THEN** the response status is 404

#### Scenario: Inactive link
- **WHEN** a client requests a link whose `isActive` is false, even if it is also expired
- **THEN** the response status is 404

#### Scenario: Expired link
- **WHEN** a client requests an active link whose `expiresAt` is one minute in the past
- **THEN** the response status is 410

#### Scenario: Click limit reached
- **WHEN** a client requests an active link with `maxClicks` 3 that already has 3 recorded clicks
- **THEN** the response status is 410

#### Scenario: Active link
- **WHEN** a client requests an active link with `maxClicks` 3 and 2 recorded clicks
- **THEN** the response status is 302 and `Location` is the link's destination

### Requirement: Destination with UTM appended
The `Location` of a 302 SHALL be the link's `targetUrl` with the link's UTM keys added to the query string. Existing query parameters of the target MUST be preserved, a UTM key already present on the target MUST be overwritten by the link's value, the fragment MUST be kept, and values MUST be percent-encoded. A link without UTM redirects to its `targetUrl` unchanged.

#### Scenario: UTM added to a target with a query and a fragment
- **WHEN** a link targets `https://example.com/p?a=1&utm_source=old#top` and carries UTM `{"utm_source":"newsletter","utm_campaign":"spring sale"}`
- **THEN** `Location` is `https://example.com/p?a=1&utm_source=newsletter&utm_campaign=spring%20sale#top`

#### Scenario: No UTM
- **WHEN** a link targets `https://example.com/p?a=1` and has no UTM
- **THEN** `Location` is exactly `https://example.com/p?a=1`

### Requirement: Headers and bodies
Every response of the endpoint SHALL carry `Cache-Control: no-store`. The 302 SHALL also carry `Referrer-Policy: no-referrer-when-downgrade`. 404, 410, 429 and 503 bodies SHALL be small HTML pages, or RFC 9457 problem details (`application/problem+json` with `type`, `title`, `status`, `detail`) when the request's `Accept` header includes `application/json`.

#### Scenario: Redirect headers
- **WHEN** a client receives a 302 from the endpoint
- **THEN** the response has `Cache-Control: no-store` and `Referrer-Policy: no-referrer-when-downgrade`

#### Scenario: HTML page for a browser
- **WHEN** a browser (`Accept: text/html`) requests an unknown slug
- **THEN** the response is 404 with an HTML body and `Cache-Control: no-store`

#### Scenario: Problem details for an API client
- **WHEN** a client with `Accept: application/json` requests an expired link
- **THEN** the response is 410 with content type `application/problem+json`, `status` 410 and a `title`

### Requirement: Click limit is exact under concurrency
Concurrent requests to a link with `maxClicks` SHALL never redirect more times than the limit allows: check and increment happen atomically, so with `maxClicks` M and N > M concurrent requests exactly M answer 302 and N − M answer 410, and the recorded click count equals M.

#### Scenario: Concurrent first redirects
- **WHEN** 10 concurrent requests hit a fresh link with `maxClicks` 3
- **THEN** exactly 3 responses are 302, 7 are 410, and the link's `clickCount` is 3

### Requirement: Per-IP rate limit
Redirects SHALL be limited per client IP with a sliding window, default 60 requests per minute, configurable by `RATE_LIMIT_REDIRECT_PER_IP`. Over the limit the endpoint SHALL answer 429 with a `Retry-After` header (HTML page or problem details by `Accept`) without touching the link. The client IP MUST be the one derived through the trusted-proxy configuration: a forwarded header from an untrusted peer does not create a separate bucket.

#### Scenario: Flood from one address
- **WHEN** one client IP sends 61 redirect requests within a minute
- **THEN** the first 60 are answered by the matrix and the 61st is 429 with `Retry-After`

#### Scenario: Spoofed forwarded header
- **WHEN** an untrusted peer sends requests with different `X-Forwarded-For` values
- **THEN** they are counted against the peer's own IP, not against the forwarded values

### Requirement: Recording failure never fails an unlimited redirect
When the click cannot be recorded, a link without `maxClicks` SHALL still answer 302 and the failure SHALL be logged at `error` without personal data; a link with `maxClicks` SHALL answer 503 with `Retry-After: 5` (its limit cannot be guaranteed) and log at `warning`.

#### Scenario: Recording fails for an unlimited link
- **WHEN** the click store is unavailable and a client requests an active link without `maxClicks`
- **THEN** the response is 302 to the destination and an `error` log record names the link id and the failure class, but no IP or user agent

#### Scenario: Recording fails for a limited link
- **WHEN** the click store is unavailable and a client requests an active link with `maxClicks` 10 and 0 clicks
- **THEN** the response is 503 with `Retry-After: 5`
