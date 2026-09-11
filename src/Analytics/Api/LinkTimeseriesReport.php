<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Analytics\Api\Parameter\FromParameter;
use App\Analytics\Api\Parameter\GranularityParameter;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\TimeBucket;
use App\Analytics\Report\ReportRequest;

/**
 * GET /api/v1/links/{id}/stats/timeseries (spec analytics "Timeseries report"):
 * one UTC bucket per hour or day of the period, zeros included, running total.
 */
#[ApiResource(
    shortName: 'LinkTimeseriesReport',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/links/{id}/stats/timeseries',
            uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['linkId'])],
            provider: LinkReportProvider::class,
            security: 'is_granted("ROLE_USER")',
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'granularity' => new GranularityParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'Clicks and unique visitors per UTC bucket (`hour` for periods of at most 14 days, or `day`), every bucket of the period present with zeros where nothing happened, plus the running total. Owner or admin.',
        ),
    ],
)]
final readonly class LinkTimeseriesReport
{
    /**
     * @param list<TimeBucket> $buckets
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $linkId,
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
        return new self(
            $request->linkId?->toRfc4122() ?? throw new \LogicException('A link report needs a link.'),
            $request->period->from,
            $request->period->to,
            $request->granularity->value,
            $request->includeBots,
            $buckets,
            $generatedAt,
        );
    }
}
