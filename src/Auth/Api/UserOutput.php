<?php

declare(strict_types=1);

namespace App\Auth\Api;

use ApiPlatform\Metadata\ApiProperty;
use App\Auth\Entity\User;

/**
 * Public representation of an account: never any password material.
 */
final readonly class UserOutput
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
        return new self(
            (string) $user->getId(),
            $user->getEmail(),
            $user->getRoles(),
            $user->isBlocked(),
            $user->getCreatedAt(),
        );
    }
}
