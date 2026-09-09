<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An account. Plain DataMapper entity: no persistence logic, no framework
 * base class — the two security interfaces are contracts only.
 *
 * Case-insensitive email uniqueness is enforced by a functional unique
 * index on lower(email) (see the users migration), not by lowercasing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_USER = 'ROLE_USER';
    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    #[ORM\Column(name: 'password_hash', type: Types::STRING, length: 255)]
    private string $passwordHash;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $roles = [];

    #[ORM\Column(name: 'is_blocked', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $blocked = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $email, string $passwordHash, \DateTimeImmutable $now)
    {
        if ('' === $email) {
            throw new \InvalidArgumentException('An account needs a non-empty email.');
        }
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function changePasswordHash(string $passwordHash, \DateTimeImmutable $now): void
    {
        $this->passwordHash = $passwordHash;
        $this->updatedAt = $now;
    }

    /**
     * @return list<string> always contains ROLE_USER, never duplicates
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = self::ROLE_USER;

        return array_values(array_unique($roles));
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function promoteToAdmin(\DateTimeImmutable $now): void
    {
        if (!$this->isAdmin()) {
            $this->roles[] = self::ROLE_ADMIN;
            $this->updatedAt = $now;
        }
    }

    public function demoteFromAdmin(\DateTimeImmutable $now): void
    {
        if ($this->isAdmin()) {
            $this->roles = array_values(array_filter($this->roles, static fn (string $r): bool => self::ROLE_ADMIN !== $r));
            $this->updatedAt = $now;
        }
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function block(\DateTimeImmutable $now): void
    {
        $this->blocked = true;
        $this->updatedAt = $now;
    }

    public function unblock(\DateTimeImmutable $now): void
    {
        $this->blocked = false;
        $this->updatedAt = $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function eraseCredentials(): void
    {
        // no transient credentials are kept on the entity
    }
}
