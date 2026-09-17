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
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\LimitParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\Grouped;
use App\Analytics\Dto\TopLinkRow;
use App\Analytics\Report\ReportRequest;
use App\Shared\Api\RefusedParameters;

/** GET /api/v1/admin/stats/top-links — the links with the most clicks in the period. */
#[ApiResource(
    shortName: 'AdminTopLinksReport',
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
                'limit' => ['type' => 'Int'],
                'includeBots' => ['type' => 'Boolean'],
            ],
        ),
    ],
    operations: [
        new Get(
            uriTemplate: '/admin/stats/top-links',
            provider: AdminStatsProvider::class,
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'limit' => new LimitParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'The `limit` links with the most clicks in the period, with slug, owner, clicks, unique visitors and rank (ties share one); `total` is the period\'s clicks over every link. Admin only.',
            // the report's own parameter rules answer this, not a path rule
            openapi: new OpenApiOperation(responses: [422 => new OpenApiResponse(RefusedParameters::UNPROCESSABLE)]),
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
