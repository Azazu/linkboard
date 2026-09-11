<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Analytics\Api\Parameter\FromParameter;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\LimitParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\CountryRow;
use App\Analytics\Dto\Grouped;
use App\Analytics\Report\ReportRequest;

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
        ),
    ],
)]
final readonly class LinkCountriesReport
{
    /**
     * @param list<CountryRow> $items
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $linkId,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public int $limit,
        public bool $includeBots,
        public int $total,
        public array $items,
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
