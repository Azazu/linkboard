<?php

declare(strict_types=1);

namespace App\Auth\Api\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Auth\Entity\User;

/**
 * Admin view of accounts (spec user-administration): list, block, unblock.
 * Every operation requires ROLE_ADMIN; access_control adds the same rule
 * for the whole /api/v1/admin/ prefix as the coarse net.
 */
#[ApiResource(
    shortName: 'AdminUser',
    security: 'is_granted("ROLE_ADMIN")',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/users',
            provider: UserAdminCollectionProvider::class,
            paginationItemsPerPage: 30,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            description: 'All accounts, newest first.',
        ),
        new Post(
            uriTemplate: '/admin/users/{id}/block',
            provider: UserAdminItemProvider::class,
            processor: BlockUserProcessor::class,
            deserialize: false,
            validate: false,
            status: 200,
            description: 'Block an account: it cannot log in and every request with its credentials is refused (403 blocked). Idempotent.',
        ),
        new Post(
            uriTemplate: '/admin/users/{id}/unblock',
            provider: UserAdminItemProvider::class,
            processor: UnblockUserProcessor::class,
            deserialize: false,
            validate: false,
            status: 200,
            description: 'Unblock an account. Idempotent.',
        ),
    ],
)]
final readonly class UserAdmin
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
        #[ApiProperty(example: false)]
        public bool $isBlocked,
        #[ApiProperty(example: '2026-08-20T10:00:00+00:00')]
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self((string) $user->getId(), $user->getEmail(), $user->getRoles(), $user->isBlocked(), $user->getCreatedAt());
    }
}
