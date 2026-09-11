<?php

declare(strict_types=1);

namespace App\Analytics\Report;

/**
 * Bucket size of a timeseries report; hourly buckets are bounded to short
 * periods so a report never exceeds a few hundred rows.
 */
enum Granularity: string
{
    case Hour = 'hour';
    case Day = 'day';

    public const int MAX_HOURLY_DAYS = 14;

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $g): string => $g->value, self::cases());
    }

    /** The `date_trunc` unit. */
    public function unit(): string
    {
        return $this->value;
    }

    /** The `generate_series` step as an interval literal. */
    public function step(): string
    {
        return '1 '.$this->value;
    }

    /**
     * @throws InvalidReportParameter when hourly buckets are requested over more than MAX_HOURLY_DAYS days
     */
    public function assertAllowedFor(Period $period): void
    {
        if (self::Hour === $this && $period->days() > self::MAX_HOURLY_DAYS) {
            throw new InvalidReportParameter('granularity', \sprintf('Hourly buckets are available for periods of at most %d days.', self::MAX_HOURLY_DAYS));
        }
    }
}
