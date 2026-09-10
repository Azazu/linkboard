## MODIFIED Requirements

### Requirement: Click record contents
A click record SHALL carry a unique id, the link reference, `occurred_at`, a `visitor_hash` (hex SHA-256 over a configured salt, the client IP and the user agent), `referer_host` (the host of the `Referer` header, lower-case; null when the header is absent, unparseable, longer than 255 bytes, not valid UTF-8, containing control characters, or naming the service's own public host), and the outcome of the `routing-rules` capability for this visit: `device_type`, `os`, `browser`, `is_bot` from detection (null device, OS or browser when unrecognised; `is_bot` false when unknown), `country` from country resolution (null when unknown), `resolved_by` (`device`, `country`, `language`, `variant` or `default`) and `variant` (the assigned variant's name, null unless `resolved_by` is `variant`). Two requests from the same IP and user agent MUST produce the same `visitor_hash`; a different user agent or a different salt MUST produce a different one.

#### Scenario: Visitor hash is stable per IP and user agent
- **WHEN** the same client IP with the same `User-Agent` is redirected twice, then once more with another `User-Agent`
- **THEN** the first two records share one `visitor_hash` and the third has a different one

#### Scenario: Referer host
- **WHEN** a client is redirected with `Referer: https://News.Example.org/story?id=1`, then without a `Referer`, then with a `Referer` on the service's own public host
- **THEN** the three records have `referer_host` `news.example.org`, null and null

#### Scenario: Resolution recorded
- **WHEN** an iPhone visitor from Germany (test map) with `Accept-Language: de` is redirected through a link whose device rule matches, and then a desktop Linux visitor from an unmapped IP without `Accept-Language` is redirected through a link with variants only
- **THEN** the first record has `device_type` `smartphone`, `os` `iOS`, a non-null `browser`, `is_bot` false, `country` `DE`, `resolved_by` `device`, `variant` null; the second has `device_type` `desktop`, `os` `Linux`, `country` null, `resolved_by` `variant` and `variant` equal to the assigned name

#### Scenario: Plain link
- **WHEN** a visitor is redirected through a link without rules
- **THEN** the record has `resolved_by` `default` and `variant` null while `device_type`, `os`, `browser`, `is_bot` and `country` still reflect detection and resolution
