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
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\SummaryFigures;
use App\Analytics\Report\ReportRequest;
use App\Shared\Api\RefusedParameters;

/**
 * GET /api/v1/links/{id}/stats/summary (spec analytics "Summary report").
 * All-time totals, today, the period against the previous period.
 */
#[ApiResource(
    shortName: 'LinkSummaryReport',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/links/{id}/stats/summary',
            uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['linkId'])],
            provider: LinkReportProvider::class,
            security: 'is_granted("ROLE_USER")',
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'Summary of a link\'s clicks: all-time totals, clicks today (UTC), clicks in the period against the previous period of the same length. Owner or admin. Bots excluded unless `includeBots=true`. Served from a cache with a 300 s TTL (`generatedAt`).',
            // the report's own parameter rules answer this, not a path rule
            openapi: new OpenApiOperation(responses: [422 => new OpenApiResponse(RefusedParameters::UNPROCESSABLE)]),
        ),
    ],
)]
final readonly class LinkSummaryReport
{
    public function __construct(
        #[ApiProperty(identifier: true, example: '01920f3a-6f2e-7a1c-9c0d-2b4e8a1d3f57')]
        public string $linkId,
        #[ApiProperty(example: '2026-09-01T00:00:00+00:00')]
        public \DateTimeImmutable $from,
        #[ApiProperty(example: '2026-10-01T00:00:00+00:00')]
        public \DateTimeImmutable $to,
        #[ApiProperty(example: false)]
        public bool $includeBots,
        #[ApiProperty(example: 1842)]
        public int $totalClicks,
        #[ApiProperty(example: 1197)]
        public int $uniqueVisitors,
        #[ApiProperty(example: '2026-09-01T09:12:00+00:00')]
        public ?\DateTimeImmutable $firstClickAt,
        #[ApiProperty(example: '2026-09-14T08:55:00+00:00')]
        public ?\DateTimeImmutable $lastClickAt,
        #[ApiProperty(example: 37)]
        public int $clicksToday,
        #[ApiProperty(example: 1842)]
        public int $clicksInPeriod,
        #[ApiProperty(example: 1420)]
        public int $clicksInPreviousPeriod,
        #[ApiProperty(example: 29.7)]
        public ?float $deltaPercent,
        #[ApiProperty(example: '2026-09-14T09:30:00+00:00')]
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    public static function of(ReportRequest $request, SummaryFigures $f, \DateTimeImmutable $generatedAt): self
    {
        return new self(
            $request->linkId?->toRfc4122() ?? throw new \LogicException('A link report needs a link.'),
            $request->period->from,
            $request->period->to,
            $request->includeBots,
            $f->totalClicks,
            $f->uniqueVisitors,
            $f->firstClickAt,
            $f->lastClickAt,
            $f->clicksToday,
            $f->clicksInPeriod,
            $f->clicksInPreviousPeriod,
            $f->deltaPercent,
            $generatedAt,
        );
    }
}
