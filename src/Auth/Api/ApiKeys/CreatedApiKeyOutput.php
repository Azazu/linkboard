<?php

declare(strict_types=1);

namespace App\Auth\Api\ApiKeys;

use App\Auth\Entity\ApiKey;

/** The creation response: the key's representation plus the plaintext, shown this once (FR-KEY-1). */
final readonly class CreatedApiKeyOutput
{
    public function __construct(
        public string $id,
        public string $name,
        public string $prefix,
        #[\SensitiveParameter]
        public string $key,
        public ?\DateTimeImmutable $expiresAt,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastUsedAt,
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
