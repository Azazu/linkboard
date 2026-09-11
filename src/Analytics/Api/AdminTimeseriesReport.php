<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Analytics\Api\Parameter\FromParameter;
use App\Analytics\Api\Parameter\GranularityParameter;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\TimeBucket;
use App\Analytics\Report\ReportRequest;

/** GET /api/v1/admin/stats/timeseries — the per-link timeseries over every link. */
#[ApiResource(
    shortName: 'AdminTimeseriesReport',
    security: 'is_granted("ROLE_ADMIN")',
    operations: [
        new Get(
            uriTemplate: '/admin/stats/timeseries',
            provider: AdminStatsProvider::class,
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'granularity' => new GranularityParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'Clicks and unique visitors per UTC bucket over every link — same parameters, shape and rules as a link\'s timeseries. Admin only.',
        ),
    ],
)]
final readonly class AdminTimeseriesReport
{
    /**
     * @param list<TimeBucket> $buckets
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $granularity,
        public bool $includeBots,
        public array $buckets,
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    /**
     * @param list<TimeBucket> $buckets
     */
    public static function of(ReportRequest $request, array $buckets, \DateTimeImmutable $generatedAt): self
    {
        return new self($request->period->from, $request->period->to, $request->granularity->value, $request->includeBots, $buckets, $generatedAt);
    }
}
