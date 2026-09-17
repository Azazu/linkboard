<?php

declare(strict_types=1);

namespace App\Auth\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GraphQl\Query as QueryOperation;
use App\Auth\Entity\User;

/**
 * GET /api/v1/me — the authenticated account (spec authentication).
 */
#[ApiResource(
    shortName: 'Me',
    // Read-only through GraphQL (change stretch-graphql), same provider and
    // same security expression as the REST operation.
    graphQlOperations: [
        new QueryOperation(
            provider: MeProvider::class,
            security: 'is_granted("ROLE_USER")',
            description: 'The authenticated account.',
        ),
    ],
    operations: [
        new Get(
            uriTemplate: '/me',
            provider: MeProvider::class,
            security: 'is_granted("ROLE_USER")',
            description: 'The authenticated account.',
        ),
    ],
)]
final readonly class Me
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[ApiProperty(example: '01920f3a-1111-7a1c-9c0d-2b4e8a1d3f57')]
        public string $id,
        #[ApiProperty(example: 'ada@example.com')]
        public string $email,
        #[ApiProperty(example: ['ROLE_USER'])]
        public array $roles,
        #[ApiProperty(example: '2026-08-20T10:00:00+00:00')]
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self((string) $user->getId(), $user->getEmail(), $user->getRoles(), $user->getCreatedAt());
    }
}
