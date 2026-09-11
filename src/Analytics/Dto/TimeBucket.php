<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

/** One UTC bucket of a timeseries; `bucket` is its inclusive start. */
final readonly class TimeBucket
{
    public function __construct(
        public \DateTimeImmutable $bucket,
        public int $clicks,
        public int $uniqueVisitors,
        public int $cumulativeClicks,
    ) {
    }
}
