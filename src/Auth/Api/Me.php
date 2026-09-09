<?php

declare(strict_types=1);

namespace App\Auth\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Auth\Entity\User;

/**
 * GET /api/v1/me — the authenticated account (spec authentication).
 */
#[ApiResource(
    shortName: 'Me',
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
        public string $id,
        public string $email,
        public array $roles,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self((string) $user->getId(), $user->getEmail(), $user->getRoles(), $user->getCreatedAt());
    }
}
