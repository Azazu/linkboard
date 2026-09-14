<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Analytics\Api\Parameter\FromParameter;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\LimitParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\Grouped;
use App\Analytics\Dto\TopLinkRow;
use App\Analytics\Report\ReportRequest;

/** GET /api/v1/admin/stats/top-links — the links with the most clicks in the period. */
#[ApiResource(
    shortName: 'AdminTopLinksReport',
    security: 'is_granted("ROLE_ADMIN")',
    operations: [
        new Get(
            uriTemplate: '/admin/stats/top-links',
            provider: AdminStatsProvider::class,
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'limit' => new LimitParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'The `limit` links with the most clicks in the period, with slug, owner, clicks, unique visitors and rank (ties share one); `total` is the period\'s clicks over every link. Admin only.',
        ),
    ],
)]
final readonly class AdminTopLinksReport
{
    /**
     * @param list<TopLinkRow> $items
     */
    public function __construct(
        #[ApiProperty(example: '2026-09-01T00:00:00+00:00')]
        public \DateTimeImmutable $from,
        #[ApiProperty(example: '2026-10-01T00:00:00+00:00')]
        public \DateTimeImmutable $to,
        #[ApiProperty(example: 10)]
        public int $limit,
        #[ApiProperty(example: false)]
        public bool $includeBots,
        #[ApiProperty(example: 482913)]
        public int $total,
        #[ApiProperty(example: [['linkId' => '01920f3a-6f2e-7a1c-9c0d-2b4e8a1d3f57', 'slug' => 'spring-sale', 'ownerId' => '01920f3a-1111-7a1c-9c0d-2b4e8a1d3f57', 'clicks' => 1842, 'uniqueVisitors' => 1197, 'rank' => 1]])]
        public array $items,
        #[ApiProperty(example: '2026-09-14T09:30:00+00:00')]
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    /**
     * @param Grouped<TopLinkRow> $grouped
     */
    public static function of(ReportRequest $request, Grouped $grouped, \DateTimeImmutable $generatedAt): self
    {
        return new self($request->period->from, $request->period->to, $request->limit, $request->includeBots, $grouped->total, $grouped->items, $generatedAt);
    }
}
