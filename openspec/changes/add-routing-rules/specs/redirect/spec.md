## MODIFIED Requirements

### Requirement: Destination with UTM appended
The `Location` of a 302 SHALL be the destination resolved by the `routing-rules` capability — a matching rule's target, the assigned variant's target, or the link's `targetUrl` when nothing matches or the link has no rules — with the link's UTM keys added to the query string. The query is processed as a sequence of `key=value` pairs, never as a decoded map: every pair whose percent-decoded key is one of the link's UTM keys is removed (all occurrences), every other pair is kept byte for byte and in order (repeated keys, dotted keys, bracket notation and encoded values included), then the link's UTM pairs are appended in a fixed order with percent-encoded values. The fragment MUST be kept. A link without UTM redirects to its resolved destination unchanged. `HEAD` resolves the destination exactly as `GET` does.

#### Scenario: UTM added to a target with a query and a fragment
- **WHEN** a link targets `https://example.com/p?a=1&utm_source=old#top` and carries UTM `{"utm_source":"newsletter","utm_campaign":"spring sale"}`
- **THEN** `Location` is `https://example.com/p?a=1&utm_source=newsletter&utm_campaign=spring%20sale#top`

#### Scenario: Unrelated query components survive untouched
- **WHEN** a link targets `https://example.com/p?tag=a&tag=b&a.b=1&x%5By%5D=2&utm%5Fsource=old&utm_source=old2` and carries UTM `{"utm_source":"news"}`
- **THEN** `Location` is `https://example.com/p?tag=a&tag=b&a.b=1&x%5By%5D=2&utm_source=news` (both spellings of the old key removed, everything else verbatim)

#### Scenario: No UTM
- **WHEN** a link targets `https://example.com/p?a=1` and has no UTM
- **THEN** `Location` is exactly `https://example.com/p?a=1`

#### Scenario: UTM on a rule target
- **WHEN** a link with UTM `{"utm_source":"news"}` has a device rule whose target is `https://apps.apple.com/app/id123?x=1` and an iPhone visitor is redirected with `GET` and then with `HEAD`
- **THEN** both `Location` headers are `https://apps.apple.com/app/id123?x=1&utm_source=news`
