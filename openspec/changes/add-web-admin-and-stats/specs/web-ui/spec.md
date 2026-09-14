## MODIFIED Requirements

### Requirement: The owner-facing pages
The system SHALL serve, to a signed-in user, `/dashboard` (their own totals, a clicks-per-day chart over their links, and their ten most recent links), `/links` (their links, paginated, filterable by active state and by a slug fragment, orderable by creation time or click count), `/links/new`, `/links/{id}` (the link's details, its short URL with a copy control, and its QR code), `/links/{id}/edit`, `/links/{id}/stats` (the link's reports), and `/api-keys` (their keys and the controls to create and revoke one). Every one of these pages SHALL require an authenticated session: a guest SHALL be sent to `/login` and no page content SHALL be disclosed. `/login` and `/register` remain public.

#### Scenario: A signed-in owner reaches every page
- **WHEN** a signed-in user requests `/dashboard`, `/links`, `/links/new`, `/links/{id}`, `/links/{id}/edit` and `/links/{id}/stats` for a link they own, and `/api-keys`
- **THEN** each response is 200 and carries that user's own data

#### Scenario: A guest is sent to the login page
- **WHEN** a client with no session requests any of those paths
- **THEN** the response redirects to `/login` and its body contains none of the page's content

#### Scenario: A short link whose slug begins with a page's name is still a short link
- **WHEN** a guest requests `/dashboard-sale`, `/links-promo` and `/api-keys-promo`, which are valid slugs of existing links
- **THEN** each response is the redirect the `redirect` capability defines, no response sends the client to `/login`, and none starts a session

#### Scenario: The dashboard shows the signed-in user's own figures
- **WHEN** two users each own links with clicks, and one of them opens `/dashboard`
- **THEN** the totals and the chart series count only that user's links, and the recent-links list holds only their links

### Requirement: Pages work without JavaScript
The system SHALL render every page's content and accept every form submission without JavaScript: navigation is plain links, submissions are plain form posts, and the only features that require scripting are the charts, the copy-to-clipboard control and the rules editor's convenience behaviour, each of which SHALL degrade to readable content or a plain text field. Every number a chart draws SHALL also be present on the page as text, so a reader without scripting loses the picture and not the figures. Every page SHALL declare a viewport for small screens and SHALL NOT impose a fixed pixel width on its layout, so it stays usable at 375 pixels.

#### Scenario: The links list and the forms work with scripting disabled
- **WHEN** a client that runs no JavaScript signs in, lists links, creates one through `/links/new` and edits it
- **THEN** each step completes and the resulting pages show the data

#### Scenario: The chart degrades to its numbers
- **WHEN** the dashboard is rendered and its chart cannot run
- **THEN** the page still shows the totals, the recent links, and a row per day of the series the chart would have drawn

#### Scenario: Every charted series is also a table
- **WHEN** a client that runs no JavaScript opens the dashboard, a link's statistics page and the global statistics page
- **THEN** on each of them every bucket the chart would draw is present as a row with its own figures, and on the statistics pages the period, granularity and bots controls submit and take effect

## ADDED Requirements

### Requirement: A link's statistics page shows every report the analytics capability defines
The system SHALL serve, at `/links/{id}/stats`, every report the `analytics` capability defines for one link: the summary figures (all-time clicks, unique visitors, first and last click, clicks today, clicks in the period against the period before it with its delta), the timeseries over the period, and the breakdowns by country, by device type, by operating system, by referrer host and by A/B variant, each breakdown carrying its counts and shares. The page SHALL state the period it covers, that its figures are counted in UTC, whether bots are included, and how stale the numbers may be, taking that from the report's own generation time rather than from the moment of rendering. A link with no clicks in the selected period SHALL render as a page whose period-scoped figures read zero and whose breakdowns are empty, while the figures the `analytics` capability does not scope to the period — all-time clicks, unique visitors, the first and last click, and today's clicks — keep the values that capability answers; it SHALL NOT render as an error. The page SHALL be reachable from the link's own page.

#### Scenario: Every report is on the page
- **WHEN** the owner of a link with clicks from several countries, devices, operating systems and referrers, and with A/B variants, opens `/links/{id}/stats`
- **THEN** the page shows the summary figures, a row per bucket of the timeseries, and a row per country, device type, operating system, referrer host and variant, with the same numbers the corresponding report of the `analytics` capability answers for the same parameters

#### Scenario: A link nobody has clicked
- **WHEN** the owner of a link with no clicks at all opens its statistics page
- **THEN** the response is 200, every figure reads zero, the first and last click are shown as absent, each breakdown says it has nothing to show, and no part of the page reports an error

#### Scenario: A link whose clicks are all outside the selected period
- **WHEN** the owner of a link whose only clicks fall before the selected period opens its statistics page for that period
- **THEN** the clicks in the period read zero and the breakdowns are empty, while the all-time clicks and unique visitors keep their non-zero values and the first and last click are shown with their dates

#### Scenario: The page states its staleness from the report
- **WHEN** a statistics page is rendered
- **THEN** it names the period, says the figures are UTC, says whether bots are counted, and states the age of the numbers from the generation time the report carries

### Requirement: The statistics page is bounded by the same permission as the report
The system SHALL show a link's statistics only to a user the `analytics` capability would serve that link's reports to — its owner or an administrator. For any other signed-in user, and for an identifier no link has, the page SHALL answer 404, so that another owner's link is indistinguishable from an identifier that does not exist; a guest SHALL be sent to `/login`. The API's own answer for the same decision is unchanged.

#### Scenario: Owner, admin, stranger, guest
- **WHEN** user A owns a link and A, an administrator, user B and a client with no session each request `/links/{id}/stats`
- **THEN** A's and the administrator's responses are 200, B's is 404 with none of the link's data in it, and the guest is sent to `/login`

#### Scenario: An identifier no link has
- **WHEN** a signed-in user requests the statistics page for a well-formed identifier no link has, and for a malformed one
- **THEN** both responses are 404, the same answer another owner's link gives

### Requirement: The statistics page reports a refused parameter on its own control
The system SHALL let the reader choose the period, the granularity of the timeseries and whether bots are counted, and SHALL accept those choices as an ordinary page request that can be bookmarked and shared. A choice the `analytics` capability refuses — a malformed date, a period whose end is not after its start, a period longer than the capability allows, or an hourly granularity over a period too long for it — SHALL leave the reader on the statistics page with the message attached to the control that carried the refused value, and SHALL NOT replace the page with an error document. The figures shown SHALL be the figures for the parameters the page reports.

#### Scenario: A period the capability refuses
- **WHEN** the owner submits a period whose end precedes its start, and separately a period of two years, and separately an hourly granularity over ninety days
- **THEN** each response keeps the reader on the statistics page, shows a message on the control that carried the refused value, and shows no figures computed from the refused parameters

#### Scenario: The chosen parameters are shared in the address
- **WHEN** the owner chooses a period, an hourly granularity and bots included, and the resulting address is opened again in a new session by the same user
- **THEN** the page shows the same parameters in its controls and figures computed for them

#### Scenario: Bots are counted only when asked for
- **WHEN** a link's period holds both human and bot clicks and the owner views the page with and then without bots included
- **THEN** the totals with bots included are the larger ones, and each page states which of the two it is showing

### Requirement: The administrative pages and their actions are reachable only by an administrator
The system SHALL serve `/admin/users`, `/admin/links` and `/admin/stats`, and SHALL accept the actions those pages offer — the confirmation pages for blocking and unblocking an account and the submissions that carry them out — only to a signed-in user with the administrator role. A signed-in user without that role SHALL receive 403 and none of the page's data, and SHALL change nothing even when their submission carries a valid cross-site request forgery token; a guest SHALL be sent to `/login` and SHALL change nothing. A short link whose slug begins with `admin` SHALL keep redirecting, unaffected by this boundary. The administrative entry in the navigation SHALL appear only for an administrator.

#### Scenario: Administrator, ordinary user, guest
- **WHEN** an administrator, a signed-in ordinary user and a client with no session each request `/admin/users`, `/admin/links` and `/admin/stats`
- **THEN** the administrator's responses are 200, the ordinary user's are 403 carrying none of the data, and the guest is sent to `/login`

#### Scenario: A non-administrator cannot block an account even with a valid token
- **WHEN** a signed-in ordinary user requests the confirmation page for blocking an account, and submits the block and the unblock with a cross-site request forgery token their own session would accept
- **THEN** every response is 403, and the target account's blocked state is unchanged

#### Scenario: A short link whose slug begins with the administrative prefix
- **WHEN** a guest requests `/admin-sale`, a valid slug of an existing link
- **THEN** the response is the redirect the `redirect` capability defines, not a redirect to `/login` and not a 403

#### Scenario: The navigation names the administrative pages only to an administrator
- **WHEN** an administrator and an ordinary user each open any page
- **THEN** only the administrator's page offers a link to the administrative pages

### Requirement: The accounts page lists every account and blocks or unblocks one
The system SHALL show, at `/admin/users`, every account newest first, paginated so that no account is unreachable, each with its address, its roles, whether it is blocked, and when it was created. It SHALL block and unblock an account only on a confirmed, CSRF-protected submission: the confirmation page SHALL name the account and state the consequence, and SHALL offer a way back that changes nothing. An administrator attempting to block their own account SHALL be refused with the reason, and nothing SHALL change. A blocked account SHALL be shown as blocked and SHALL offer unblocking, which is the exact inverse.

#### Scenario: Blocking an account
- **WHEN** an administrator confirms blocking another account
- **THEN** that account is blocked, the page says so, and the account can no longer sign in or authenticate with its credentials

#### Scenario: Nothing happens before the confirmation
- **WHEN** an administrator opens the confirmation page for an account and then follows the way back
- **THEN** the account's blocked state is unchanged, and a request to the confirmation page alone changes nothing

#### Scenario: An administrator cannot block themselves
- **WHEN** an administrator confirms blocking their own account
- **THEN** the response refuses with the reason, their account stays unblocked, and they stay signed in

#### Scenario: Unblocking restores the account
- **WHEN** an administrator confirms unblocking a blocked account
- **THEN** the account is no longer blocked and can sign in again

#### Scenario: Every account is reachable
- **WHEN** there are more accounts than one page holds and an administrator pages through the list
- **THEN** every account appears on exactly one page and the oldest is reachable

### Requirement: The administrative links page lists every user's links
The system SHALL show, at `/admin/links`, the links of every user, paginated, with the same filters and ordering the owner's own list offers, each row naming the link's slug, its target, its owner, its click count and whether it is active, and leading to that link's own page.

#### Scenario: Links of every owner
- **WHEN** two users each own links and an administrator opens `/admin/links`
- **THEN** the page lists the links of both, each naming its owner, while the owner's own `/links` page keeps listing only their own

#### Scenario: The filters work the same way
- **WHEN** an administrator filters by active state and by a slug fragment and orders by click count
- **THEN** the listed links are exactly those matching, in that order

### Requirement: The global statistics page shows the service-wide reports
The system SHALL show, at `/admin/stats`, the global reports the `analytics` capability defines for an administrator: the totals of accounts, links, active links and clicks with today's clicks; the clicks per bucket over every link with its running total; and the links with the most clicks in the period, each with its slug, its owner and its figures. The page SHALL offer the period, granularity and bots controls with the same behaviour the link statistics page has, and SHALL state its period, its UTC counting and the age of its figures.

#### Scenario: The three global reports are on the page
- **WHEN** several users own links with clicks and an administrator opens `/admin/stats`
- **THEN** the page shows the totals, a row per bucket of the global timeseries with its running total, and the top links with their owners, matching the numbers the corresponding reports of the `analytics` capability answer for the same parameters

#### Scenario: A refused parameter on the global page
- **WHEN** an administrator submits a period the capability refuses
- **THEN** the response keeps them on the global statistics page with the message on the control that carried the value, and shows no figures computed from it
