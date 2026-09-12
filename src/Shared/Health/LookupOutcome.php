<?php

declare(strict_types=1);

namespace App\Shared\Health;

/**
 * Result of one key lookup: verified (with the key's expiry, null = never),
 * denied (the database answered "no such active admin key"), or unavailable
 * (the database could not be reached or asked — the reason is a class name,
 * never anything about the key).
 */
final readonly class LookupOutcome
{
    private function __construct(
        public LookupStatus $status,
        public ?\DateTimeImmutable $expiresAt,
        public ?string $reason,
    ) {
    }

    public static function verified(?\DateTimeImmutable $expiresAt): self
    {
        return new self(LookupStatus::Verified, $expiresAt, null);
    }

    public static function denied(): self
    {
        return new self(LookupStatus::Denied, null, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(LookupStatus::Unavailable, null, $reason);
    }

    public function isVerified(): bool
    {
        return LookupStatus::Verified === $this->status;
    }

    public function isDenied(): bool
    {
        return LookupStatus::Denied === $this->status;
    }

    public function isUnavailable(): bool
    {
        return LookupStatus::Unavailable === $this->status;
    }
}
