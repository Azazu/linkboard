<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Analytics\Api\Parameter\FromParameter;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\LimitParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\CountryRow;
use App\Analytics\Dto\Grouped;
use App\Analytics\Report\ReportRequest;
use App\Shared\Api\RefusedParameters;

/** GET /api/v1/links/{id}/stats/countries (spec analytics "Countries report"). */
#[ApiResource(
    shortName: 'LinkCountriesReport',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/links/{id}/stats/countries',
            uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['linkId'])],
            provider: LinkReportProvider::class,
            security: 'is_granted("ROLE_USER")',
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'limit' => new LimitParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'The `limit` countries with the most clicks in the period, with share of the period total and rank (ties share a rank); `country` null groups clicks of unknown origin. Owner or admin.',
            // the report's own parameter rules answer this, not a path rule
            openapi: new OpenApiOperation(responses: [422 => new OpenApiResponse(RefusedParameters::UNPROCESSABLE)]),
        ),
    ],
)]
final readonly class LinkCountriesReport
{
    /**
     * @param list<CountryRow> $items
     */
    public function __construct(
        #[ApiProperty(identifier: true, example: '01920f3a-6f2e-7a1c-9c0d-2b4e8a1d3f57')]
        public string $linkId,
        #[ApiProperty(example: '2026-09-01T00:00:00+00:00')]
        public \DateTimeImmutable $from,
        #[ApiProperty(example: '2026-10-01T00:00:00+00:00')]
        public \DateTimeImmutable $to,
        #[ApiProperty(example: 10)]
        public int $limit,
        #[ApiProperty(example: false)]
        public bool $includeBots,
        #[ApiProperty(example: 1842)]
        public int $total,
        #[ApiProperty(example: [['country' => 'DE', 'clicks' => 820, 'share' => 44.5, 'rank' => 1]])]
        public array $items,
        #[ApiProperty(example: '2026-09-14T09:30:00+00:00')]
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    /**
     * @param Grouped<CountryRow> $grouped
     */
    public static function of(ReportRequest $request, Grouped $grouped, \DateTimeImmutable $generatedAt): self
    {
        return new self(
            $request->linkId?->toRfc4122() ?? throw new \LogicException('A link report needs a link.'),
            $request->period->from,
            $request->period->to,
            $request->limit,
            $request->includeBots,
            $grouped->total,
            $grouped->items,
            $generatedAt,
        );
    }
}
