<?php

declare(strict_types=1);

namespace App\Click;

/**
 * What the redirect knows about one visitor at request time, built by
 * App\Redirect\VisitFactory, which also classifies every header as absent,
 * well-formed or hostile (NFR-SEC-4, FR-RUL-7): hostile values are not carried
 * — only their issue class in `inputIssues`. The IP and user agent are
 * consumed by the visitor hash and never persisted (NFR-SEC-6); the user
 * agent is always cut to USER_AGENT_MAX_BYTES so the hash is bounded.
 */
final readonly class Visit
{
    public const int USER_AGENT_MAX_BYTES = 1024;
    public const int REFERER_MAX_BYTES = 2048;
    public const int ACCEPT_LANGUAGE_MAX_BYTES = 256;
    public const int CLIENT_HINT_MAX_BYTES = 256;
    public const int PROXY_COUNTRY_MAX_BYTES = 16;

    /**
     * @param array<string, string> $clientHints  well-formed `Sec-CH-UA*` headers by lower-case name
     * @param ?string               $proxyCountry the trusted proxy's country header, only when the request came through a trusted proxy
     * @param list<string>          $inputIssues  issue classes of hostile headers (App\Redirect\VisitFactory::ISSUE_*); empty for a clean request
     */
    public function __construct(
        public string $clientIp,
        public string $userAgent,
        public ?string $referer,
        public \DateTimeImmutable $occurredAt,
        public bool $isHead = false,
        public ?string $acceptLanguage = null,
        public array $clientHints = [],
        public ?string $proxyCountry = null,
        public array $inputIssues = [],
    ) {
    }

    public function isHostile(): bool
    {
        return [] !== $this->inputIssues;
    }
}
