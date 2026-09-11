<?php

declare(strict_types=1);

namespace App\Analytics\Api;

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
            description: 'The `limit` links with the most clicks in the period, with slug, owner, clicks, unique visitors and rank; `total` is the period\'s clicks over every link. Admin only.',
        ),
    ],
)]
final readonly class AdminTopLinksReport
{
    /**
     * @param list<TopLinkRow> $items
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public int $limit,
        public bool $includeBots,
        public int $total,
        public array $items,
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
