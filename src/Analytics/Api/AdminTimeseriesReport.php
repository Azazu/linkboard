<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GraphQl\Query as QueryOperation;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Analytics\Api\Parameter\FromParameter;
use App\Analytics\Api\Parameter\GranularityParameter;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\ClickBucket;
use App\Analytics\Report\ReportRequest;
use App\Shared\Api\RefusedParameters;

/** GET /api/v1/admin/stats/timeseries — clicks per bucket over every link (no distinct visitors). */
#[ApiResource(
    shortName: 'AdminTimeseriesReport',
    security: 'is_granted("ROLE_ADMIN")',
    // Read-only through GraphQL (change stretch-graphql): the same provider,
    // which resolves the link and checks the voter before it reads anything,
    // and the same parameters — supplied as arguments here and as a query
    // string over REST, parsed by one factory into one cache key. Declared
    // explicitly because a resource that declares no GraphQL operations
    // receives the default set, mutations on a read model included.
    graphQlOperations: [
        new QueryOperation(
            provider: AdminStatsProvider::class,
            security: 'is_granted("ROLE_ADMIN")',
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
            uriTemplate: '/admin/stats/timeseries',
            provider: AdminStatsProvider::class,
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'granularity' => new GranularityParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'Clicks per UTC bucket over every link with the running total — same parameters and rules as a link\'s timeseries, without unique visitors. Admin only.',
            // the report's own parameter rules answer this, not a path rule
            openapi: new OpenApiOperation(responses: [422 => new OpenApiResponse(RefusedParameters::UNPROCESSABLE)]),
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
