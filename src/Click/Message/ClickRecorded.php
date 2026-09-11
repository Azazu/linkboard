<?php

declare(strict_types=1);

namespace App\Click\Message;

/**
 * FR-CLK-1: one accepted redirect, as the finished click row (design decision
 * 3). Carries the routing facts, the referer host and the salted visitor hash
 * computed in the request — never the client IP, user agent or header values
 * (spec click-logging, "The queue carries no raw personal data"). `clickId`
 * becomes the record's primary key and makes handling idempotent.
 */
final readonly class ClickRecorded
{
    public function __construct(
        public string $clickId,
        public string $linkId,
        public \DateTimeImmutable $occurredAt,
        public ?string $country,
        public ?string $deviceType,
        public ?string $os,
        public ?string $browser,
        public bool $isBot,
        public string $resolvedBy,
        public ?string $variant,
        public ?string $refererHost,
        public string $visitorHash,
    ) {
    }
}
