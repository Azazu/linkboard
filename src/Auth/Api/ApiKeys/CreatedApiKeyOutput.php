<?php

declare(strict_types=1);

namespace App\Auth\Api\ApiKeys;

use ApiPlatform\Metadata\ApiProperty;
use App\Auth\Entity\ApiKey;

/** The creation response: the key's representation plus the plaintext, shown this once (FR-KEY-1). */
final readonly class CreatedApiKeyOutput
{
    public function __construct(
        #[ApiProperty(example: '01920f3a-2222-7a1c-9c0d-2b4e8a1d3f57')]
        public string $id,
        #[ApiProperty(example: 'deploy monitor')]
        public string $name,
        #[ApiProperty(example: 'lb_7f3a9c')]
        public string $prefix,
        #[\SensitiveParameter]
        #[ApiProperty(example: 'lb_7f3a9cW2mK8pQ4rT6yU1iO3aS5dF7gH9jK0lZ')]
        public string $key,
        #[ApiProperty(example: '2027-01-01T00:00:00+00:00')]
        public ?\DateTimeImmutable $expiresAt,
        #[ApiProperty(example: '2026-09-01T08:15:00+00:00')]
        public \DateTimeImmutable $createdAt,
        #[ApiProperty(example: '2026-09-14T06:02:00+00:00')]
        public ?\DateTimeImmutable $lastUsedAt,
        #[ApiProperty(example: '2026-09-20T12:00:00+00:00')]
        public ?\DateTimeImmutable $revokedAt,
    ) {
    }

    public static function fromKey(ApiKey $key, #[\SensitiveParameter] string $plaintext): self
    {
        return new self(
            $key->getId()->toRfc4122(),
            $key->getName(),
            $key->getPrefix(),
            $plaintext,
            $key->getExpiresAt(),
            $key->getCreatedAt(),
            $key->getLastUsedAt(),
            $key->getRevokedAt(),
        );
    }
}
