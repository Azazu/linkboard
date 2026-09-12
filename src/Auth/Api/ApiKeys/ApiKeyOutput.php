<?php

declare(strict_types=1);

namespace App\Auth\Api\ApiKeys;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Auth\Entity\ApiKey;

/**
 * A user's API key as the API shows it (spec api-keys): never the plaintext,
 * never the hash — the display prefix identifies it. Every operation is
 * scoped to the authenticated caller by its provider; an admin manages only
 * their own keys.
 */
#[ApiResource(
    shortName: 'ApiKey',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/api-keys',
            provider: OwnApiKeysProvider::class,
            security: 'is_granted("ROLE_USER")',
            paginationItemsPerPage: 30,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            description: 'The caller\'s API keys, newest first — including revoked and expired ones. The plaintext is never shown again.',
        ),
        new Post(
            uriTemplate: '/api-keys',
            input: CreateApiKeyInput::class,
            output: CreatedApiKeyOutput::class,
            processor: CreateApiKeyProcessor::class,
            security: 'is_granted("ROLE_USER")',
            status: 201,
            description: 'Create a named key (`name` 1–64 characters, optional future `expiresAt`). The response carries the plaintext `key` exactly once. At most 10 active keys per user (409 beyond).',
        ),
        new Delete(
            uriTemplate: '/api-keys/{id}',
            provider: OwnApiKeyItemProvider::class,
            processor: RevokeApiKeyProcessor::class,
            security: 'is_granted("ROLE_USER")',
            description: 'Revoke a key: it stops authenticating on the next request; the row is kept for audit. Idempotent. Another user\'s key is 404.',
        ),
    ],
)]
final readonly class ApiKeyOutput
{
    public function __construct(
        public string $id,
        public string $name,
        public string $prefix,
        public ?\DateTimeImmutable $expiresAt,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastUsedAt,
        public ?\DateTimeImmutable $revokedAt,
    ) {
    }

    public static function fromKey(ApiKey $key): self
    {
        return new self(
            $key->getId()->toRfc4122(),
            $key->getName(),
            $key->getPrefix(),
            $key->getExpiresAt(),
            $key->getCreatedAt(),
            $key->getLastUsedAt(),
            $key->getRevokedAt(),
        );
    }
}
