## Purpose
Smart routing of a short link: an optional rules document on the link that sends a visitor to a different destination by device and OS, country or language, or splits visitors deterministically between A/B variants, evaluated on every redirect in a fixed order and degrading to the plain target on any hostile or unusable input.

## ADDED Requirements

### Requirement: Rules document shape
A link's `rules` MUST be either null or a JSON object with `version` (the integer 1) and at least one of `rules` and `variants`, with no other keys anywhere in the document. `rules` MUST be a list of 1 to 20 objects `{"match": {...}, "target": "<url>"}`; each `match` MUST name exactly one dimension: the device dimension (`device`, `os`, or both together), `country`, or `language`. Every dimension value MUST be a non-empty list of 1 to 64 distinct values from its vocabulary: `device` ∈ `desktop`, `smartphone`, `tablet`, `other`; `os` ∈ `iOS`, `Android`, `Windows`, `macOS`, `Linux`, `other`; `country` matching `^[A-Z]{2}$`; `language` matching `^[a-z]{2}$`. `variants` MUST be a list of 2 to 4 objects `{"name": "<name>", "weight": <int>, "target": "<url>"}` with distinct names matching `^[A-Za-z0-9_-]{1,16}$` and integer weights of at least 1 that sum to exactly 100. Every `target` MUST satisfy the target URL policy of the `links` capability. A document violating any of these rules SHALL be rejected with 422 and one `violations` entry per violation whose `propertyPath` names the offending element in array-access notation under `rules` (for example `rules[rules][0][match]`, `rules[rules][2][match][country][1]`, `rules[variants][1][weight]`, `rules[version]`); a `rules` value that is not an object or null, or an empty object, is one violation on `rules`.

#### Scenario: Valid document is accepted and echoed
- **WHEN** a user posts a link whose `rules` is `{"version":1,"rules":[{"match":{"device":["smartphone","tablet"],"os":["iOS"]},"target":"https://apps.apple.com/app/id123"},{"match":{"os":["Android"]},"target":"https://play.google.com/store/apps/details?id=com.example"},{"match":{"country":["DE","AT","CH"]},"target":"https://example.de/"},{"match":{"language":["uk","ru"]},"target":"https://example.com/ua/"}],"variants":[{"name":"A","weight":50,"target":"https://example.com/landing-a"},{"name":"B","weight":50,"target":"https://example.com/landing-b"}]}`
- **THEN** the response status is 201 and `rules` in the response equals the posted document

#### Scenario: Structural violations name their path
- **WHEN** a user posts documents with, in turn, 21 rules; a rule whose `match` has both `device` and `country`; a rule whose `match` is `{}`; a rule with `"device": []`; a rule with `"device": ["phone"]`; a rule with `"country": ["de"]`; a rule with `"language": ["en-US"]`; a rule with `"country": ["DE","DE"]`; `"version": 2`; an extra key `"note"` at the top level; `{"version":1}` alone; and `"rules": "x"`
- **THEN** each response status is 422 with a single violation at, respectively, `rules[rules]`, `rules[rules][0][match]`, `rules[rules][0][match]`, `rules[rules][0][match][device]`, `rules[rules][0][match][device][0]`, `rules[rules][0][match][country][0]`, `rules[rules][0][match][language][0]`, `rules[rules][0][match][country][1]`, `rules[version]`, `rules[note]`, `rules`, and `rules`

#### Scenario: Variant violations name their path
- **WHEN** a user posts documents with, in turn, one variant; five variants; two variants both named `A`; weights 60 and 50; a weight of `0` with another of `100`; a weight of `"50"`; and a variant named `this-name-is-far-too-long`
- **THEN** each response status is 422 with a violation at, respectively, `rules[variants]`, `rules[variants]`, `rules[variants][1][name]`, `rules[variants]`, `rules[variants][0][weight]`, `rules[variants][0][weight]`, and `rules[variants][0][name]`

#### Scenario: Rule and variant targets obey the target URL policy
- **WHEN** a user posts a document with a rule target `http://169.254.169.254/latest/meta-data`, and another with a variant target `market://details?id=x`
- **THEN** each response status is 422 with a violation at `rules[rules][0][target]` and `rules[variants][0][target]` respectively

### Requirement: Published schema matches the validator
The document schema SHALL be published as a JSON Schema document in the repository's reference documentation (`docs/reference/rules-schema.json`) and MUST agree with the validator on the `version` constant, the vocabularies, the size limits, the name pattern, the weight range and the rejection of unknown keys.

#### Scenario: Parity
- **WHEN** the published schema's enumerations, `minItems`/`maxItems`, `pattern`, `minimum` and `additionalProperties` values are compared with the validator's constants
- **THEN** every pair is equal, and the example document of the how-to is accepted by the validator

### Requirement: Fixed matching order
On every `GET /{slug}` and `HEAD /{slug}` that reaches the 302 of the response matrix, the destination SHALL be resolved from the link's document in this order: (1) rules of the device dimension in document order, (2) `country` rules in document order, (3) `language` rules in document order, (4) the A/B variants, (5) `targetUrl`. A rule matches when every value list it names contains the visitor's resolved value for that dimension (a rule with both `device` and `os` requires both). The first match wins; the dimension that resolved the destination is recorded as `resolved_by` (`device`, `country`, `language`, `variant` or `default`). The order MUST NOT depend on the order of dimensions in the document.

#### Scenario: Device beats country beats language
- **WHEN** a link has a `language` rule for `de`, then a `country` rule for `DE`, then a device rule for `smartphone` (in that document order), and a smartphone visitor from Germany with `Accept-Language: de` is redirected
- **THEN** `Location` is the device rule's target and the click's `resolved_by` is `device`; a desktop visitor from Germany with `de` gets the country target (`resolved_by` `country`); a desktop visitor from France with `de` gets the language target (`resolved_by` `language`); a desktop visitor from France with `en` gets the variant or default target

#### Scenario: Document order within a dimension
- **WHEN** a link has two `country` rules, `["DE","FR"]` then `["FR"]`, and a visitor from France is redirected
- **THEN** `Location` is the first rule's target

#### Scenario: Device rule with an OS condition
- **WHEN** a link has a rule `{"device":["smartphone"],"os":["iOS"]}` and then `{"os":["Android"]}`, and an iPhone, an Android phone, an Android tablet and an iPad are redirected
- **THEN** the iPhone gets the first target, the Android phone and the Android tablet get the second, and the iPad falls through to the variants or `targetUrl`

#### Scenario: Default when nothing matches
- **WHEN** a link has rules that match none of the visitor's dimensions and no variants
- **THEN** `Location` is `targetUrl` with UTM appended and `resolved_by` is `default`

### Requirement: Unresolvable dimensions are skipped
A rule whose dimension could not be resolved for the visitor — device or OS unknown (no or unrecognised `User-Agent`), country unknown (no resolver answered), language unknown (no `Accept-Language` or none with a two-letter primary subtag) — SHALL be skipped and evaluation SHALL continue with the next rule; a skipped rule is never a match, and skipping produces no log record.

#### Scenario: Unknown country skips country rules
- **WHEN** a link has a `country` rule and then a `language` rule for `en`, and a visitor whose IP resolves to no country sends `Accept-Language: en`
- **THEN** `Location` is the language rule's target

#### Scenario: Missing headers fall to the default
- **WHEN** a link has device, country and language rules and no variants, and a request arrives without `User-Agent` and without `Accept-Language` from an IP that resolves to no country
- **THEN** the response status is 302 to `targetUrl`, `resolved_by` is `default`, and the click's `device_type`, `os`, `country` are null

### Requirement: Device, OS, browser and bot detection
Device type, OS, browser and the bot flag SHALL be derived from the `User-Agent` (bounded to 1024 bytes) and the `Sec-CH-UA*` client-hint headers (each bounded to 256 bytes) and mapped onto the vocabularies: device `desktop`, `smartphone` (including phablets), `tablet`, any other recognised type → `other`, unrecognised → unknown (null); OS by family: iOS (including iPadOS) → `iOS`, Android → `Android`, Windows → `Windows`, Mac → `macOS`, GNU/Linux → `Linux`, any other recognised family → `other`, unrecognised → unknown (null). `browser` is the recognised client name truncated to 32 characters, or null. `is_bot` is true when the user agent is a known bot; bots are evaluated and recorded like other visitors. Detection MUST NOT make a network call or use Redis.

#### Scenario: Common user agents
- **WHEN** visitors are redirected with the user agents of Safari on an iPhone, Chrome on an Android phone, Safari on an iPad, Chrome on Windows, Safari on macOS, Firefox on Linux, and Googlebot
- **THEN** their clicks carry `(device_type, os)` of `(smartphone, iOS)`, `(smartphone, Android)`, `(tablet, iOS)`, `(desktop, Windows)`, `(desktop, macOS)`, `(desktop, Linux)` respectively, the first six have `is_bot` false and a non-null `browser`, and Googlebot has `is_bot` true

#### Scenario: Garbage user agent
- **WHEN** a visitor sends `User-Agent: %%%%\x00garbage` (invalid characters) to a link with a device rule and no other rules
- **THEN** the response status is 302 to `targetUrl`, the click's `device_type` and `os` are null and `is_bot` is false

### Requirement: Country resolution
The visitor's country SHALL be resolved by an ordered chain of resolvers configured by `COUNTRY_RESOLVERS` (comma-separated names; an unknown name MUST fail application boot). Resolver `header` SHALL read the header named by `GEOIP_COUNTRY_HEADER` (default `CF-IPCountry`) only when the request comes from a trusted proxy (`TRUSTED_PROXIES`) and the value matches `^[A-Za-z]{2}$` and is neither `XX` nor `T1`, returning it upper-cased; otherwise it answers unknown. Resolver `geolite2` SHALL look the client IP up in the GeoLite2 country database at `GEOIP_DATABASE_PATH`; when the file is missing or unreadable at first use the resolver SHALL disable itself for the process and log one `warning`, and any lookup failure (address not found, private address, reader error) SHALL answer unknown, logging at `notice` only for reader errors and never with the IP. The first resolver that answers wins; when none answers the country is unknown. The default is `header,geolite2`; the test environment uses a fixed IP→country map (`fixed`).

#### Scenario: Spoofed country header from an untrusted peer
- **WHEN** a request carrying `CF-IPCountry: DE` arrives directly from a peer that is not a trusted proxy, to a link with a `country` rule for `DE`
- **THEN** the header is ignored, the rule is skipped, and the click's `country` is what the remaining resolvers answer (null in the test map for that IP)

#### Scenario: Country header from a trusted proxy
- **WHEN** the same request arrives from a trusted proxy address
- **THEN** the country rule matches and the click's `country` is `DE`

#### Scenario: GeoLite2 database missing
- **WHEN** `GEOIP_DATABASE_PATH` names a file that does not exist and a visitor is redirected
- **THEN** the response status is 302, the country is unknown, and exactly one `warning` log record names the missing database — not repeated on the next request

#### Scenario: Reader failure is unknown, not an error
- **WHEN** the GeoLite2 reader throws for an address
- **THEN** the country is unknown, the redirect proceeds, and a `notice` log record names the exception class without the IP

### Requirement: Language resolution
The visitor's language SHALL be the primary subtag, lower-cased, of the highest-quality entry of `Accept-Language` (bounded to 256 bytes before parsing) when that subtag is exactly two ASCII letters; otherwise the language is unknown. Wildcards, malformed entries and oversized headers MUST NOT cause an error.

#### Scenario: Quality ordering and subtag
- **WHEN** visitors send `Accept-Language: uk-UA;q=0.8, ru;q=0.9`, `en-US,en;q=0.9`, `*`, `x`, and an 8 KB header of repeated `de,`
- **THEN** their languages are `ru`, `en`, unknown, unknown, and `de`, and every response is a 302

### Requirement: Deterministic A/B assignment
When evaluation reaches the variants, the variant SHALL be chosen as `pick(weights, crc32(link_id ‖ client_ip ‖ user_agent) mod 100)` over the cumulative weights in document order, without cookies or server-side state. The same link, client IP and user agent MUST yield the same variant on every request; `resolved_by` is `variant` and the variant name is recorded on the click.

#### Scenario: Same visitor, same variant
- **WHEN** one client IP and user agent are redirected 20 times through a link with variants A (50) and B (50)
- **THEN** every `Location` is the same variant's target and every click carries the same `variant`

#### Scenario: Distribution follows the weights
- **WHEN** 10 000 distinct synthetic visitors (distinct IP and user-agent pairs) are assigned for a link with weights 70 and 30
- **THEN** the share of each variant is within ±3 percentage points of its weight

#### Scenario: Different links may differ
- **WHEN** the same visitor is assigned for two links with identical variants
- **THEN** the assignments are computed independently (the link id is part of the hash input) and the test asserts only that each link is self-consistent

### Requirement: Evaluation never fails the redirect
Detection, country and language resolution and rule evaluation SHALL run inside one guard: if any of them throws, or if the stored document cannot be interpreted, the request SHALL proceed to the response matrix with `Location` = `targetUrl` with UTM appended, `resolved_by = default`, `variant` null and the unresolved dimensions null on the click, and exactly one `notice` log record SHALL carry the link id and the exception class — never the IP, user agent, header values or target. Hostile or oversized headers alone (garbage `User-Agent`, an 8 KB `Accept-Language`, malformed client hints) are unresolved dimensions and produce no log record.

#### Scenario: Detector failure
- **WHEN** the device detector throws for a request to a link with device rules and variants
- **THEN** the response status is 302 with `Location` = `targetUrl` (UTM appended), the click has `resolved_by` `default` and null `device_type`, `os`, `variant`, and one `notice` record names the link id and the exception class

#### Scenario: Hostile headers
- **WHEN** a visitor sends a 4 KB `User-Agent` of random bytes, an 8 KB `Accept-Language` and a 4 KB `Sec-CH-UA` to a link with rules
- **THEN** the response status is 302, exactly one click is recorded, and no log record at `notice` or above was written for the request
