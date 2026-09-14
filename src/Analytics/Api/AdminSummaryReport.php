<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Dto\GlobalTotals;
use App\Analytics\Report\ReportRequest;

/** GET /api/v1/admin/stats/summary (spec analytics "Global statistics for administrators"). */
#[ApiResource(
    shortName: 'AdminSummaryReport',
    security: 'is_granted("ROLE_ADMIN")',
    operations: [
        new Get(
            uriTemplate: '/admin/stats/summary',
            provider: AdminStatsProvider::class,
            parameters: ['includeBots' => new IncludeBotsParameter()],
            description: 'Instance totals: users, links, active links, clicks and clicks today (UTC). No period: `from`/`to` are not part of this report and are ignored. Admin only. Cached 300 s (`generatedAt`).',
        ),
    ],
)]
final readonly class AdminSummaryReport
{
    public function __construct(
        #[ApiProperty(example: false)]
        public bool $includeBots,
        #[ApiProperty(example: 128)]
        public int $totalUsers,
        #[ApiProperty(example: 1904)]
        public int $totalLinks,
        #[ApiProperty(example: 1751)]
        public int $activeLinks,
        #[ApiProperty(example: 482913)]
        public int $totalClicks,
        #[ApiProperty(example: 1204)]
        public int $clicksToday,
        #[ApiProperty(example: '2026-09-14T09:30:00+00:00')]
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    public static function of(ReportRequest $request, GlobalTotals $t, \DateTimeImmutable $generatedAt): self
    {
        return new self($request->includeBots, $t->totalUsers, $t->totalLinks, $t->activeLinks, $t->totalClicks, $t->clicksToday, $generatedAt);
    }
}
