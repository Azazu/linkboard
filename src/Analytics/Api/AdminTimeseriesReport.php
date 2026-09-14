<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Analytics\Api\Parameter\FromParameter;
use App\Analytics\Api\Parameter\GranularityParameter;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\ClickBucket;
use App\Analytics\Report\ReportRequest;

/** GET /api/v1/admin/stats/timeseries — clicks per bucket over every link (no distinct visitors). */
#[ApiResource(
    shortName: 'AdminTimeseriesReport',
    security: 'is_granted("ROLE_ADMIN")',
    operations: [
        new Get(
            uriTemplate: '/admin/stats/timeseries',
            provider: AdminStatsProvider::class,
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'granularity' => new GranularityParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'Clicks per UTC bucket over every link with the running total — same parameters and rules as a link\'s timeseries, without unique visitors. Admin only.',
        ),
    ],
)]
final readonly class AdminTimeseriesReport
{
    /**
     * @param list<ClickBucket> $buckets
     */
    public function __construct(
        #[ApiProperty(example: '2026-09-01T00:00:00+00:00')]
        public \DateTimeImmutable $from,
        #[ApiProperty(example: '2026-10-01T00:00:00+00:00')]
        public \DateTimeImmutable $to,
        #[ApiProperty(example: 'day')]
        public string $granularity,
        #[ApiProperty(example: false)]
        public bool $includeBots,
        #[ApiProperty(example: [['bucket' => '2026-09-01T00:00:00+00:00', 'clicks' => 4210, 'cumulativeClicks' => 4210]])]
        public array $buckets,
        #[ApiProperty(example: '2026-09-14T09:30:00+00:00')]
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    /**
     * @param list<ClickBucket> $buckets
     */
    public static function of(ReportRequest $request, array $buckets, \DateTimeImmutable $generatedAt): self
    {
        return new self($request->period->from, $request->period->to, $request->granularity->value, $request->includeBots, $buckets, $generatedAt);
    }
}
