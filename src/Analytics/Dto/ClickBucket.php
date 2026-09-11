<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

/**
 * One UTC bucket of the global timeseries: clicks and the running total, no
 * distinct visitors — the report is bounded by no link, and the distinct
 * count was what kept it over the performance target (design appendix).
 */
final readonly class ClickBucket
{
    public function __construct(
        public \DateTimeImmutable $bucket,
        public int $clicks,
        public int $cumulativeClicks,
    ) {
    }
}
