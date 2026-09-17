<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GraphQl\Query as QueryOperation;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Dto\GlobalTotals;
use App\Analytics\Report\ReportRequest;
use App\Shared\Api\RefusedParameters;

/** GET /api/v1/admin/stats/summary (spec analytics "Global statistics for administrators"). */
#[ApiResource(
    shortName: 'AdminSummaryReport',
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
                'includeBots' => ['type' => 'Boolean'],
            ],
        ),
    ],
    operations: [
        new Get(
            uriTemplate: '/admin/stats/summary',
            provider: AdminStatsProvider::class,
            parameters: ['includeBots' => new IncludeBotsParameter()],
            description: 'Instance totals: users, links, active links, clicks and clicks today (UTC). No period: `from`/`to` are not part of this report and are ignored. Admin only. Cached 300 s (`generatedAt`).',
            // the report's own parameter rules answer this, not a path rule
            openapi: new OpenApiOperation(responses: [422 => new OpenApiResponse(RefusedParameters::UNPROCESSABLE)]),
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
