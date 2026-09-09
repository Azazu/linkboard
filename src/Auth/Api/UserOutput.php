<?php

declare(strict_types=1);

namespace App\Auth\Api;

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
        public string $id,
        public string $email,
        public array $roles,
        public bool $isBlocked,
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
