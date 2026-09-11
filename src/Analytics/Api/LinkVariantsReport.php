<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Analytics\Api\Parameter\FromParameter;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\Grouped;
use App\Analytics\Dto\VariantRow;
use App\Analytics\Report\ReportRequest;

/** GET /api/v1/links/{id}/stats/variants (spec analytics "Variants report"). */
#[ApiResource(
    shortName: 'LinkVariantsReport',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/links/{id}/stats/variants',
            uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['linkId'])],
            provider: LinkReportProvider::class,
            security: 'is_granted("ROLE_USER")',
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'Clicks and unique visitors per A/B variant among the period\'s clicks that were resolved by a variant (`total`); clicks resolved by a rule or the default target are not part of this report. Owner or admin.',
        ),
    ],
)]
final readonly class LinkVariantsReport
{
    /**
     * @param list<VariantRow> $items
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $linkId,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public bool $includeBots,
        public int $total,
        public array $items,
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    /**
     * @param Grouped<VariantRow> $grouped
     */
    public static function of(ReportRequest $request, Grouped $grouped, \DateTimeImmutable $generatedAt): self
    {
        return new self(
            $request->linkId?->toRfc4122() ?? throw new \LogicException('A link report needs a link.'),
            $request->period->from,
            $request->period->to,
            $request->includeBots,
            $grouped->total,
            $grouped->items,
            $generatedAt,
        );
    }
}
