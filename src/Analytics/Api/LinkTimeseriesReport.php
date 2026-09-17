<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GraphQl\Query as QueryOperation;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Analytics\Api\Parameter\FromParameter;
use App\Analytics\Api\Parameter\GranularityParameter;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\TimeBucket;
use App\Analytics\Report\ReportRequest;
use App\Shared\Api\RefusedParameters;

/**
 * GET /api/v1/links/{id}/stats/timeseries (spec analytics "Timeseries report"):
 * one UTC bucket per hour or day of the period, zeros included, running total.
 */
#[ApiResource(
    shortName: 'LinkTimeseriesReport',
    normalizationContext: ['skip_null_values' => false],
    // Read-only through GraphQL (change stretch-graphql): the same provider,
    // which resolves the link and checks the voter before it reads anything,
    // and the same parameters — supplied as arguments here and as a query
    // string over REST, parsed by one factory into one cache key. Declared
    // explicitly because a resource that declares no GraphQL operations
    // receives the default set, mutations on a read model included.
    graphQlOperations: [
        new QueryOperation(
            provider: LinkReportProvider::class,
            security: 'is_granted("ROLE_USER")',
            // The report's parameters, declared again: API Platform's
            // `parameters:` are a REST concept and do not become GraphQL
            // arguments — measured, the schema exposed `id` alone, so a
            // client could not have asked for a period and would have been
            // answered with the default one silently. Same names, same rules,
            // same cache key; `ReportParameters` reads them from the
            // operation's arguments here and from the query string over REST.
            args: [
                'id' => ['type' => 'ID!'],
                'from' => ['type' => 'String'],
                'to' => ['type' => 'String'],
                'granularity' => ['type' => 'String'],
                'includeBots' => ['type' => 'Boolean'],
            ],
        ),
    ],
    operations: [
        new Get(
            uriTemplate: '/links/{id}/stats/timeseries',
            uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['linkId'])],
            provider: LinkReportProvider::class,
            security: 'is_granted("ROLE_USER")',
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'granularity' => new GranularityParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'Clicks and unique visitors per UTC bucket (`hour` for periods of at most 14 days, or `day`), every bucket of the period present with zeros where nothing happened, plus the running total. Owner or admin.',
            // the report's own parameter rules answer this, not a path rule
            openapi: new OpenApiOperation(responses: [422 => new OpenApiResponse(RefusedParameters::UNPROCESSABLE)]),
        ),
    ],
)]
final readonly class LinkTimeseriesReport
{
    /**
     * @param list<TimeBucket> $buckets
     */
    public function __construct(
        #[ApiProperty(identifier: true, example: '01920f3a-6f2e-7a1c-9c0d-2b4e8a1d3f57')]
        public string $linkId,
        #[ApiProperty(example: '2026-09-01T00:00:00+00:00')]
        public \DateTimeImmutable $from,
        #[ApiProperty(example: '2026-10-01T00:00:00+00:00')]
        public \DateTimeImmutable $to,
        #[ApiProperty(example: 'day')]
        public string $granularity,
        #[ApiProperty(example: false)]
        public bool $includeBots,
        #[ApiProperty(example: [['bucket' => '2026-09-01T00:00:00+00:00', 'clicks' => 120, 'uniqueVisitors' => 98, 'cumulativeClicks' => 120]])]
        public array $buckets,
        #[ApiProperty(example: '2026-09-14T09:30:00+00:00')]
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
