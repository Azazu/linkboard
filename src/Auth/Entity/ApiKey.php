<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A named API key of a user (spec api-keys, brief §3.2). Plain DataMapper
 * entity: the plaintext never enters it — only the SHA-256 hex hash and the
 * display prefix (first 8 characters). Revocation keeps the row for audit.
 */
#[ORM\Entity]
#[ORM\Table(name: 'api_keys')]
#[ORM\Index(name: 'idx_api_keys_owner_created', columns: ['user_id', 'created_at'])]
class ApiKey
{
    public const int NAME_MAX_LENGTH = 64;
    public const int MAX_ACTIVE_PER_USER = 10;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: Types::STRING, length: self::NAME_MAX_LENGTH)]
    private string $name;

    #[ORM\Column(name: 'key_hash', type: Types::STRING, length: 64, unique: true, options: ['fixed' => true])]
    private string $keyHash;

    #[ORM\Column(type: Types::STRING, length: 8, options: ['fixed' => true])]
    private string $prefix;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param string $keyHash 64 hex characters — SHA-256 of the plaintext
     * @param string $prefix  the first 8 characters of the plaintext
     */
    public function __construct(User $owner, string $name, string $keyHash, string $prefix, ?\DateTimeImmutable $expiresAt, \DateTimeImmutable $now)
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('An API key name is 1 to %d characters.', self::NAME_MAX_LENGTH));
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $keyHash)) {
            throw new \InvalidArgumentException('An API key is stored as its 64-character hex SHA-256.');
        }
        if (8 !== \strlen($prefix)) {
            throw new \InvalidArgumentException('An API key prefix is 8 characters.');
        }
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->name = $name;
        $this->keyHash = $keyHash;
        $this->prefix = $prefix;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * In-memory idempotency for a loaded entity (the first timestamp stays within
     * one unit of work). The API revokes through the repository's conditional
     * UPDATE, which gives the same guarantee across concurrent requests.
     */
    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    /** Neither revoked nor expired at $now — the key authenticates and counts against the cap. */
    public function isActive(\DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }
}
