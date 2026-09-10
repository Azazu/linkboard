<?php

declare(strict_types=1);

namespace App\Click;

/**
 * What the redirect knows about one visitor at request time. Built by the
 * controller from bounded headers (NFR-SEC-4); the IP and user agent are
 * consumed by the visitor hash and never persisted (NFR-SEC-6).
 */
final readonly class Visit
{
    public const int USER_AGENT_MAX_BYTES = 1024;
    public const int REFERER_MAX_BYTES = 2048;

    public function __construct(
        public string $clientIp,
        public string $userAgent,
        public ?string $referer,
        public \DateTimeImmutable $occurredAt,
        public bool $isHead = false,
    ) {
    }

    /** Applies the header bounds; absent headers become '' / null. */
    public static function fromHeaders(?string $clientIp, ?string $userAgent, ?string $referer, \DateTimeImmutable $occurredAt, bool $isHead): self
    {
        return new self(
            $clientIp ?? '',
            substr($userAgent ?? '', 0, self::USER_AGENT_MAX_BYTES),
            null === $referer ? null : substr($referer, 0, self::REFERER_MAX_BYTES),
            $occurredAt,
            $isHead,
        );
    }
}
