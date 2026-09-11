<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\Dto\TimeBucket;
use App\Analytics\Query\TimeseriesQuery;
use App\Analytics\Report\Granularity;
use PHPUnit\Framework\Attributes\CoversClass;

/** Spec analytics "Timeseries report": gap filling, UTC bucketing, hourly buckets, running total. */
#[CoversClass(TimeseriesQuery::class)]
final class TimeseriesQueryTest extends AnalyticsQueryTestCase
{
    public function testGapsAreFilledAndTheRunningTotalAccumulates(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', ['visitor' => 'a'], 2);
        self::click($link, '2026-09-02T12:00:00Z', ['visitor' => 'b']);
        self::click($link, '2026-09-05T23:59:59Z', ['visitor' => 'a']);
        self::click($link, '2026-09-08T00:00:00Z'); // `to` is exclusive
        self::click($link, '2026-08-31T23:59:59Z'); // before `from`

        $buckets = $this->query()->buckets($this->request($link, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z'));

        self::assertCount(7, $buckets);
        self::assertSame('2026-09-01T00:00:00+00:00', $buckets[0]->bucket->format('c'));
        self::assertSame('2026-09-07T00:00:00+00:00', $buckets[6]->bucket->format('c'));
        self::assertSame([0, 3, 0, 0, 1, 0, 0], array_map(static fn (TimeBucket $b): int => $b->clicks, $buckets));
        self::assertSame([0, 2, 0, 0, 1, 0, 0], array_map(static fn (TimeBucket $b): int => $b->uniqueVisitors, $buckets));
        self::assertSame([0, 3, 3, 3, 4, 4, 4], array_map(static fn (TimeBucket $b): int => $b->cumulativeClicks, $buckets));
    }

    public function testBucketsAreUtcWhateverTheOffsetOrSessionZone(): void
    {
        $link = $this->link();
        self::click($link, '2026-03-29T00:30:00+02:00'); // 2026-03-28T22:30Z
        self::click($link, '2026-03-29T03:30:00+02:00'); // 2026-03-29T01:30Z
        self::click($link, '2026-03-28T23:30:00Z'); // 00:30 on the 29th in Berlin, still the 28th in UTC
        self::connection()->executeStatement("SET TIME ZONE 'Europe/Berlin'");

        try {
            $buckets = $this->query()->buckets($this->request($link, '2026-03-28T00:00:00Z', '2026-03-30T00:00:00Z'));
        } finally {
            self::connection()->executeStatement("SET TIME ZONE 'UTC'");
        }

        self::assertSame(['2026-03-28T00:00:00+00:00', '2026-03-29T00:00:00+00:00'], array_map(static fn (TimeBucket $b): string => $b->bucket->format('c'), $buckets));
        self::assertSame([2, 1], array_map(static fn (TimeBucket $b): int => $b->clicks, $buckets));
    }

    public function testHourlyBuckets(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-01T09:15:00Z', [], 2);
        self::click($link, '2026-09-01T23:59:00Z');

        $buckets = $this->query()->buckets($this->request($link, '2026-09-01T00:00:00Z', '2026-09-02T00:00:00Z', Granularity::Hour));

        self::assertCount(24, $buckets);
        $byHour = [];
        foreach ($buckets as $b) {
            $byHour[$b->bucket->format('c')] = $b->clicks;
        }
        self::assertSame(2, $byHour['2026-09-01T09:00:00+00:00']);
        self::assertSame(1, $byHour['2026-09-01T23:00:00+00:00']);
        self::assertSame(3, array_sum($byHour));
        self::assertSame(3, $buckets[23]->cumulativeClicks);
    }

    public function testBotsToggleAndGlobalVariant(): void
    {
        $a = $this->link();
        $b = $this->link();
        self::click($a, '2026-09-02T10:00:00Z', [], 5);
        self::click($a, '2026-09-02T10:00:00Z', ['bot' => true], 2);
        self::click($b, '2026-09-05T10:00:00Z', [], 3);

        $sum = static fn (array $buckets): int => array_sum(array_map(static fn (TimeBucket $x): int => $x->clicks, $buckets));
        self::assertSame(5, $sum($this->query()->buckets($this->request($a, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z'))));
        self::assertSame(7, $sum($this->query()->buckets($this->request($a, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z', includeBots: true))));
        $global = $this->query()->buckets($this->request(null, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z'));
        self::assertCount(7, $global);
        self::assertSame([0, 5, 0, 0, 3, 0, 0], array_map(static fn (TimeBucket $x): int => $x->clicks, $global));
    }

    private function query(): TimeseriesQuery
    {
        return new TimeseriesQuery(self::connection());
    }
}
