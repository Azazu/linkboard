<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

/** The numbers of a link's summary report, straight from one SQL statement. */
final readonly class SummaryFigures
{
    public function __construct(
        public int $totalClicks,
        public int $uniqueVisitors,
        public ?\DateTimeImmutable $firstClickAt,
        public ?\DateTimeImmutable $lastClickAt,
        public int $clicksToday,
        public int $clicksInPeriod,
        public int $clicksInPreviousPeriod,
        public ?float $deltaPercent,
    ) {
    }
}
