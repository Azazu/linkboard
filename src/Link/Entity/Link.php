<?php

declare(strict_types=1);

namespace App\Link\Entity;

use App\Auth\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The link aggregate (specification §2.2, §3.3). Plain DataMapper entity:
 * intention-revealing methods, no setters for slug, owner or click count.
 * Slug uniqueness is case-sensitive and enforced by the unique index with
 * collation "C" (migration); the routing `rules` column is created here and
 * populated by the routing-rules change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'links')]
#[ORM\Index(name: 'idx_links_owner_created', columns: ['owner_id', 'created_at'])]
class Link
{
    public const int SLUG_MAX_LENGTH = 32;
    public const int TARGET_URL_MAX_LENGTH = 2048;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: Types::STRING, length: self::SLUG_MAX_LENGTH, unique: true, options: ['collation' => 'C'])]
    private string $slug;

    #[ORM\Column(name: 'target_url', type: Types::TEXT)]
    private string $targetUrl;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $rules = null;

    /** @var array<string, string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $utm = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'max_clicks', type: Types::INTEGER, nullable: true)]
    private ?int $maxClicks = null;

    #[ORM\Column(name: 'click_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $clickCount = 0;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $slug, string $targetUrl, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->slug = $slug;
        $this->targetUrl = $targetUrl;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }

    public function changeTarget(string $targetUrl, \DateTimeImmutable $now): void
    {
        $this->targetUrl = $targetUrl;
        $this->touch($now);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRules(): ?array
    {
        return $this->rules;
    }

    /**
     * @return array<string, string>|null
     */
    public function getUtm(): ?array
    {
        return $this->utm;
    }

    /**
     * @param array<string, string>|null $utm null or an empty array clears the tags
     */
    public function replaceUtm(?array $utm, \DateTimeImmutable $now): void
    {
        $this->utm = [] === $utm ? null : $utm;
        $this->touch($now);
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiry(?\DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void
    {
        $this->expiresAt = $expiresAt;
        $this->touch($now);
    }

    public function getMaxClicks(): ?int
    {
        return $this->maxClicks;
    }

    public function setClickLimit(?int $maxClicks, \DateTimeImmutable $now): void
    {
        if (null !== $maxClicks && $maxClicks < 1) {
            throw new \InvalidArgumentException('The click limit must be a positive integer.');
        }
        $this->maxClicks = $maxClicks;
        $this->touch($now);
    }

    public function getClickCount(): int
    {
        return $this->clickCount;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function activate(\DateTimeImmutable $now): void
    {
        $this->active = true;
        $this->touch($now);
    }

    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->active = false;
        $this->touch($now);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
