<?php

declare(strict_types=1);

namespace App\Analytics\Api;

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
        public bool $includeBots,
        public int $totalUsers,
        public int $totalLinks,
        public int $activeLinks,
        public int $totalClicks,
        public int $clicksToday,
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    public static function of(ReportRequest $request, GlobalTotals $t, \DateTimeImmutable $generatedAt): self
    {
        return new self($request->includeBots, $t->totalUsers, $t->totalLinks, $t->activeLinks, $t->totalClicks, $t->clicksToday, $generatedAt);
    }
}
