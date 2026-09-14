<?php

declare(strict_types=1);

namespace App\Auth\Api\ApiKeys;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
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
            // the one refusal no path rule can imply: it belongs to this
            // operation alone (change polish-api-and-openapi, decision 1)
            openapi: new OpenApiOperation(responses: [
                409 => new OpenApiResponse('The account already holds the maximum number of active keys; revoke one first. Nothing was created.'),
            ]),
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
        #[ApiProperty(example: '01920f3a-2222-7a1c-9c0d-2b4e8a1d3f57')]
        public string $id,
        #[ApiProperty(example: 'deploy monitor')]
        public string $name,
        #[ApiProperty(example: 'lb_7f3a9c')]
        public string $prefix,
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
