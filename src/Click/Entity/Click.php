<?php

declare(strict_types=1);

namespace App\Click\Entity;

use App\Link\Entity\Link;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The click write model (specification §3.4). Read-only mapping: the hot path
 * writes rows through the DBAL (DbalClickRecorder), never through the
 * UnitOfWork; this class exists so the schema tooling, migrations and tests
 * know the table. Analytics reads it by SQL, never as entities (CQRS-lite).
 *
 * The composite index is ascending: a btree serves `ORDER BY occurred_at DESC`
 * by a backward scan, and Doctrine does not model index sort order.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'clicks')]
#[ORM\Index(name: 'idx_clicks_link_occurred', columns: ['link_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_clicks_link_occurred_human', columns: ['link_id', 'occurred_at'], options: ['where' => 'NOT is_bot'])]
class Click
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Link::class)]
    #[ORM\JoinColumn(name: 'link_id', nullable: false, onDelete: 'CASCADE')]
    private Link $link;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::STRING, length: 2, nullable: true, options: ['fixed' => true])]
    private ?string $country = null;

    #[ORM\Column(name: 'device_type', type: Types::STRING, length: 16, nullable: true)]
    private ?string $deviceType = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $os = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $browser = null;

    #[ORM\Column(name: 'is_bot', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $bot = false;

    #[ORM\Column(name: 'referer_host', type: Types::STRING, length: 255, nullable: true)]
    private ?string $refererHost = null;

    #[ORM\Column(name: 'visitor_hash', type: Types::STRING, length: 64, options: ['fixed' => true])]
    private string $visitorHash;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $variant = null;

    #[ORM\Column(name: 'resolved_by', type: Types::STRING, length: 8)]
    private string $resolvedBy;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLink(): Link
    {
        return $this->link;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function getDeviceType(): ?string
    {
        return $this->deviceType;
    }

    public function getOs(): ?string
    {
        return $this->os;
    }

    public function getBrowser(): ?string
    {
        return $this->browser;
    }

    public function isBot(): bool
    {
        return $this->bot;
    }

    public function getRefererHost(): ?string
    {
        return $this->refererHost;
    }

    public function getVisitorHash(): string
    {
        return $this->visitorHash;
    }

    public function getVariant(): ?string
    {
        return $this->variant;
    }

    public function getResolvedBy(): string
    {
        return $this->resolvedBy;
    }
}
