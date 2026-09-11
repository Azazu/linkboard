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
use App\Analytics\Dto\SummaryFigures;
use App\Analytics\Report\ReportRequest;

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
        ),
    ],
)]
final readonly class LinkSummaryReport
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $linkId,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public bool $includeBots,
        public int $totalClicks,
        public int $uniqueVisitors,
        public ?\DateTimeImmutable $firstClickAt,
        public ?\DateTimeImmutable $lastClickAt,
        public int $clicksToday,
        public int $clicksInPeriod,
        public int $clicksInPreviousPeriod,
        public ?float $deltaPercent,
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
